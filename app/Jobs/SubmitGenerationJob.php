<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyProviderGenerationAction;
use App\Actions\Generations\RejectGenerationAction;
use App\Enums\GenerationStatus;
use App\Enums\PixelforgeErrorCode;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Attributes\Backoff;
use Throwable;

/**
 * Submits a pending generation to Pixelforge, waiting out rate limits, concurrency limits and overloads.
 * Retrying is safe: the local id is the Idempotency-Key, so Pixelforge never creates or bills a duplicate.
 */
#[Backoff(5)]
class SubmitGenerationJob implements ShouldQueue
{
    use Queueable;

    private const int RETRY_FOR_MINUTES = 10;

    private const int DEFAULT_RETRY_AFTER_SECONDS = 5;

    /** Spreads out retries that were all told the same Retry-After. */
    private const int MAX_JITTER_SECONDS = 3;

    public function __construct(public readonly string $generationId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_FOR_MINUTES);
    }

    public function handle(PixelforgeClient $pixelforge, ApplyProviderGenerationAction $apply, RejectGenerationAction $reject): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        try {
            $snapshot = $pixelforge->createGeneration($generation);
        } catch (ConnectionException) {
            $this->retryLater(self::DEFAULT_RETRY_AFTER_SECONDS);

            return;
        } catch (PixelforgeException $exception) {
            if ($exception->isRetryable()) {
                $this->retryLater($exception->retryAfterSeconds ?? self::DEFAULT_RETRY_AFTER_SECONDS);

                return;
            }

            $reject->execute($this->generationId, $exception->errorCode->value ?? "http_{$exception->status}", $exception->userMessage());

            return;
        }

        $apply->execute($snapshot);
    }

    /**
     * Out of retry time (or an unexpected error). Not charged.
     *
     * ponytail: if Pixelforge did accept an earlier attempt whose response we lost, that generation is
     * billed to us but not to the user; it shows up as a mismatch in the admin report.
     */
    public function failed(?Throwable $exception): void
    {
        $code = PixelforgeErrorCode::SubmissionAbandoned;

        app(RejectGenerationAction::class)->execute($this->generationId, $code->value, (string) $code->userMessage());
    }

    private function retryLater(int $seconds): void
    {
        $this->release($seconds + random_int(0, self::MAX_JITTER_SECONDS));
    }
}
