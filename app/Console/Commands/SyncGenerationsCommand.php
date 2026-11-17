<?php

namespace App\Console\Commands;

use App\Actions\Generations\ApplyProviderGenerationAction;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safety net for lost messages: polls accepted generations whose webhooks never came, and re-queues
 * pending ones whose submit job was lost. Both are idempotent, so running it too often is harmless.
 */
#[Signature('generations:sync')]
#[Description('Poll Pixelforge for quiet generations and re-queue lost submissions')]
class SyncGenerationsCommand extends Command
{
    private const int QUIET_FOR_MINUTES = 2;

    /** Longer than SubmitGenerationJob keeps retrying, so a pending generation this old has lost its job. */
    private const int LOST_SUBMISSION_MINUTES = 15;

    /** Stays well inside Pixelforge's 60 requests per minute. */
    private const int BATCH_SIZE = 20;

    public function handle(PixelforgeClient $pixelforge, ApplyProviderGenerationAction $apply): int
    {
        $this->claimQuiet(
            Generation::query()->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])->whereNotNull('provider_id'),
            self::QUIET_FOR_MINUTES,
        )->each(function (Generation $generation) use ($pixelforge, $apply): void {
            try {
                $apply->execute($pixelforge->getGeneration((string) $generation->provider_id));
            } catch (Throwable $exception) {
                Log::warning('Could not sync Pixelforge generation.', ['generation_id' => $generation->id, 'error' => $exception->getMessage()]);
            }
        });

        $this->claimQuiet(
            Generation::query()->where('status', GenerationStatus::Pending),
            self::LOST_SUBMISSION_MINUTES,
        )->each(fn (Generation $generation) => SubmitGenerationJob::dispatch($generation->id));

        return self::SUCCESS;
    }

    /**
     * The oldest quiet generations, touched first so they move to the back of the line. Otherwise a few
     * generations that never change (or keep erroring) would fill every batch and starve the rest.
     *
     * @param  Builder<Generation>  $query
     * @return Collection<int, Generation>
     */
    private function claimQuiet(Builder $query, int $quietForMinutes): Collection
    {
        $generations = $query
            ->where('updated_at', '<', now()->subMinutes($quietForMinutes))
            ->orderBy('updated_at')
            ->limit(self::BATCH_SIZE)
            ->get(['id', 'provider_id']);

        Generation::query()->whereKey($generations->modelKeys())->update(['updated_at' => now()]);

        return $generations->toBase();
    }
}
