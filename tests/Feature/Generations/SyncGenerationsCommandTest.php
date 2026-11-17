<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('polls generations whose webhooks have gone quiet and settles them', function () {
    $generation = heldGeneration(attributes: ['provider_id' => 'gen_test', 'status' => GenerationStatus::Queued]);
    $generation->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();
    Http::fake(['pixelforge.test/v1/generations/gen_test' => Http::response(pixelforgeGeneration($generation, 'succeeded'))]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nanos)->toBe(SUCCEEDED_CHARGE_NANOS);
});

it('moves polled generations to the back of the line even when nothing changed', function () {
    $generation = heldGeneration(attributes: ['provider_id' => 'gen_test', 'status' => GenerationStatus::Queued]);
    $generation->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();
    Http::fake(['pixelforge.test/v1/generations/gen_test' => Http::response(pixelforgeGeneration($generation, 'queued'))]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($generation->refresh()->updated_at->isAfter(now()->subMinute()))->toBeTrue();
});

it('re-queues a pending generation whose submit job was lost', function () {
    Queue::fake();
    $lost = heldGeneration();
    $lost->forceFill(['updated_at' => now()->subMinutes(20)])->saveQuietly();
    $recent = heldGeneration();

    $this->artisan('generations:sync')->assertSuccessful();

    Queue::assertPushed(SubmitGenerationJob::class, 1);
    Queue::assertPushed(SubmitGenerationJob::class, fn ($job) => $job->generationId === $lost->id);
});

it('leaves recently updated generations to their webhooks', function () {
    heldGeneration(attributes: ['provider_id' => 'gen_test', 'status' => GenerationStatus::Queued]);
    Http::fake();

    $this->artisan('generations:sync')->assertSuccessful();

    Http::assertNothingSent();
});
