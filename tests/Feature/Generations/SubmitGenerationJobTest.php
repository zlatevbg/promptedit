<?php

use App\Actions\Generations\ApplyProviderGenerationAction;
use App\DTO\ProviderGeneration;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Enums\PixelforgeErrorCode;
use App\Jobs\SubmitGenerationJob;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function runSubmitJob(string $generationId): SubmitGenerationJob
{
    $job = (new SubmitGenerationJob($generationId))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

function pixelforgeError(int $status, string $code, ?int $retryAfter = null): PromiseInterface
{
    return Http::response(
        ['error' => ['code' => $code, 'message' => "Pixelforge said {$code}."]],
        $status,
        $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter],
    );
}

it('submits with the local id as idempotency key and records the accepted generation', function () {
    $generation = heldGeneration();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeGeneration($generation, 'queued', ['id' => 'gen_abc']), 202)]);

    runSubmitJob($generation->id)->assertNotReleased();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBe('gen_abc');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->id)
        && $request['metadata'] === ['local_id' => $generation->id]);
});

it('retries later when Pixelforge is busy, honouring Retry-After', function (int $status, string $code, int $retryAfter) {
    $generation = heldGeneration();
    Http::fake(['pixelforge.test/v1/generations' => pixelforgeError($status, $code, $retryAfter)]);

    $job = runSubmitJob($generation->id);

    $job->assertReleased();
    expect($job->job->releaseDelay)->toBeGreaterThanOrEqual($retryAfter)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Pending);
})->with([
    'overloaded' => [503, 'overloaded', 2],
    'concurrency limit' => [429, 'concurrency_limit_exceeded', 5],
    'rate limit' => [429, 'rate_limit_exceeded', 30],
    'timeout' => [408, 'request_timeout', 5],
]);

it('fails for free when Pixelforge rejects the generation outright', function (int $status, string $code) {
    $generation = heldGeneration();
    Http::fake(['pixelforge.test/v1/generations' => pixelforgeError($status, $code)]);

    runSubmitJob($generation->id)->assertNotReleased();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe($code)
        ->and($generation->charge_nanos)->toBe(0)
        ->and($generation->user->balance_nanos)->toBe(1_000_000_000)
        ->and(ledgerCount($generation, CreditTransactionType::Release))->toBe(1);
})->with([
    'content policy' => [400, 'content_policy_violation'],
    'daily limit' => [429, 'daily_limit_exceeded'],
    'validation' => [422, 'validation_failed'],
]);

it('fails for free once it runs out of retries', function () {
    $generation = heldGeneration();

    (new SubmitGenerationJob($generation->id))->failed(null);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe(PixelforgeErrorCode::SubmissionAbandoned->value)
        ->and($generation->user->balance_nanos)->toBe(1_000_000_000);
});

it('keeps a success that a webhook recorded before the submit response came back', function () {
    $generation = heldGeneration();
    app(ApplyProviderGenerationAction::class)->execute(ProviderGeneration::fromArray(pixelforgeGeneration($generation, 'succeeded')));

    // The late submit response still says "queued", then the job gives up for an unrelated reason.
    app(ApplyProviderGenerationAction::class)->execute(ProviderGeneration::fromArray(pixelforgeGeneration($generation, 'queued')));
    (new SubmitGenerationJob($generation->id))->failed(null);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nanos)->toBe(SUCCEEDED_CHARGE_NANOS)
        ->and(ledgerCount($generation, CreditTransactionType::Charge))->toBe(1)
        ->and(ledgerCount($generation, CreditTransactionType::Release))->toBe(1);
});

it('does nothing once a webhook has already moved the generation on', function () {
    $generation = heldGeneration(attributes: ['status' => GenerationStatus::Processing, 'provider_id' => 'gen_abc']);
    Http::fake();

    runSubmitJob($generation->id);

    Http::assertNothingSent();
    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});
