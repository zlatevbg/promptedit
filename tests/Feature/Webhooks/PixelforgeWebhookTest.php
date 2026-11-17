<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Testing\TestResponse;

/**
 * POST a webhook event signed the way Pixelforge signs it.
 */
function deliverWebhook(array $event, ?int $timestamp = null, string $secret = 'whsec_test'): TestResponse
{
    $body = json_encode($event);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return test()->call('POST', route('webhooks.pixelforge'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], content: $body);
}

function generationEvent(Generation $generation, string $status, string $eventId = 'evt_1'): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => "generation.{$status}",
        'created_at' => '2026-11-17T10:00:30Z',
        'data' => ['object' => pixelforgeGeneration($generation, $status)],
    ];
}

it('rejects requests that are not signed with our secret', function () {
    $generation = heldGeneration(attributes: ['provider_id' => 'gen_test', 'status' => GenerationStatus::Queued]);

    deliverWebhook(generationEvent($generation, 'succeeded'), secret: 'whsec_wrong')->assertStatus(400);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('rejects replayed requests with an old signature timestamp', function () {
    $generation = heldGeneration(attributes: ['provider_id' => 'gen_test', 'status' => GenerationStatus::Queued]);

    deliverWebhook(generationEvent($generation, 'succeeded'), timestamp: time() - 301)->assertStatus(400);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('records the actual cost and charges the user cost plus markup when a generation succeeds', function () {
    $generation = heldGeneration(attributes: ['provider_id' => 'gen_test', 'status' => GenerationStatus::Queued]);

    deliverWebhook(generationEvent($generation, 'succeeded'))->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nanos)->toBe(SUCCEEDED_PROVIDER_COST_NANOS)
        ->and($generation->charge_nanos)->toBe(SUCCEEDED_CHARGE_NANOS)
        ->and($generation->completed_at->toIso8601ZuluString())->toBe('2026-11-17T10:00:30Z')
        ->and($generation->image_url)->not->toBeNull()
        ->and($generation->user->balance_nanos)->toBe(1_000_000_000 - SUCCEEDED_CHARGE_NANOS);
});

it('bills at the rates fixed on the generation, not the current published rates', function () {
    $generation = heldGeneration(attributes: ['provider_id' => 'gen_test', 'status' => GenerationStatus::Queued]);
    $event = generationEvent($generation, 'succeeded');
    // Twice the published rates: 2 × $0.16908 = $0.33816 provider cost, +25% = $0.4227.
    $event['data']['object']['rates'] = ['input_text_tokens' => '10.00', 'input_image_tokens' => '20.00', 'output_tokens' => '80.00', 'reasoning_tokens' => '25.00'];

    deliverWebhook($event)->assertNoContent();

    expect($generation->refresh()->provider_cost_nanos)->toBe(338_160_000);
});

it('never charges more than the maximum price that was shown and held', function () {
    $generation = heldGeneration(attributes: ['provider_id' => 'gen_test', 'status' => GenerationStatus::Queued, 'max_charge_nanos' => 200_000_000]);

    // Real price would be $0.21135, above the $0.20 hold.
    deliverWebhook(generationEvent($generation, 'succeeded'))->assertNoContent();

    expect($generation->refresh()->provider_cost_nanos)->toBe(SUCCEEDED_PROVIDER_COST_NANOS)
        ->and($generation->charge_nanos)->toBe(200_000_000)
        ->and($generation->user->balance_nanos)->toBe(1_000_000_000 - 200_000_000);
});

it('refuses all webhooks while no signing secret is configured', function () {
    config(['services.pixelforge.webhook_secret' => '']);
    $generation = heldGeneration(attributes: ['provider_id' => 'gen_test', 'status' => GenerationStatus::Queued]);

    // Signed with the empty key, which anyone could do.
    deliverWebhook(generationEvent($generation, 'failed'), secret: '')->assertServerError();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('charges once when the same event is delivered twice', function () {
    $generation = heldGeneration(attributes: ['provider_id' => 'gen_test', 'status' => GenerationStatus::Queued]);
    $event = generationEvent($generation, 'succeeded');

    deliverWebhook($event)->assertNoContent();
    deliverWebhook($event)->assertNoContent();

    expect(ledgerCount($generation, CreditTransactionType::Charge))->toBe(1)
        ->and($generation->user->refresh()->balance_nanos)->toBe(1_000_000_000 - SUCCEEDED_CHARGE_NANOS);
});

it('charges once when two different events report the same success', function () {
    $generation = heldGeneration(attributes: ['provider_id' => 'gen_test', 'status' => GenerationStatus::Queued]);

    deliverWebhook(generationEvent($generation, 'succeeded', 'evt_1'))->assertNoContent();
    deliverWebhook(generationEvent($generation, 'succeeded', 'evt_2'))->assertNoContent();

    expect(ledgerCount($generation, CreditTransactionType::Charge))->toBe(1);
});

it('never moves a finished generation back when events arrive out of order', function () {
    $generation = heldGeneration(attributes: ['provider_id' => 'gen_test', 'status' => GenerationStatus::Queued]);

    deliverWebhook(generationEvent($generation, 'succeeded', 'evt_2'))->assertNoContent();
    deliverWebhook(generationEvent($generation, 'processing', 'evt_1'))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nanos)->toBe(SUCCEEDED_CHARGE_NANOS);
});

it('matches a webhook that arrives before the submit request returned', function () {
    $generation = heldGeneration();

    deliverWebhook(generationEvent($generation, 'processing'))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($generation->provider_id)->toBe('gen_test');
});

it('releases the hold and charges nothing when a generation fails', function () {
    $generation = heldGeneration(attributes: ['provider_id' => 'gen_test', 'status' => GenerationStatus::Processing]);

    deliverWebhook(generationEvent($generation, 'failed'))->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charge_nanos)->toBe(0)
        ->and($generation->error_message)->toBe('The model could not produce an image.')
        ->and($generation->user->balance_nanos)->toBe(1_000_000_000)
        ->and(ledgerCount($generation, CreditTransactionType::Charge))->toBe(0);
});

it('acknowledges test pings', function () {
    deliverWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'data' => []])->assertNoContent();
});
