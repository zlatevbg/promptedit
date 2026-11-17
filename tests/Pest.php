<?php

use App\Enums\CreditTransactionType;
use App\Models\Generation;
use App\Models\User;
use App\Services\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/** Pixelforge's published pricing, as GET /v1/pricing returns it. */
function pixelforgePricing(): array
{
    return [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

/**
 * A Pixelforge generation object for a local generation, in the given status.
 *
 * @param  array<string, mixed>  $overrides
 */
function pixelforgeGeneration(Generation $generation, string $status, array $overrides = []): array
{
    return array_merge([
        'id' => $generation->provider_id ?? 'gen_test',
        'object' => 'generation',
        'status' => $status,
        'prompt' => $generation->prompt,
        'size' => $generation->size->value,
        'quality' => $generation->quality->value,
        'reference_images' => $generation->reference_images,
        'metadata' => ['local_id' => $generation->id],
        'image_url' => $status === 'succeeded' ? 'https://pixelforge.test/images/gen_test.png' : null,
        'image_url_expires_at' => $status === 'succeeded' ? '2026-11-18T10:00:30Z' : null,
        // 41 text + 3300 output + 2950 reasoning tokens = $0.169080000, the docs' /v1/usage example.
        'usage' => $status === 'succeeded'
            ? ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950, 'total_tokens' => 6291]
            : null,
        'rates' => pixelforgePricing()['rates'],
        'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'] : null,
        'created_at' => '2026-11-17T10:00:00Z',
        'started_at' => null,
        'completed_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-11-17T10:00:30Z' : null,
    ], $overrides);
}

/** Provider cost of the usage in pixelforgeGeneration(), and what the user pays for it with the 25% markup. */
const SUCCEEDED_PROVIDER_COST_NANOS = 169_080_000;
const SUCCEEDED_CHARGE_NANOS = 211_350_000;

/**
 * A pending generation whose maximum price is already held from the user's credits, as CreateGenerationAction leaves it.
 */
function heldGeneration(int $balanceNanos = 1_000_000_000, array $attributes = []): Generation
{
    $user = User::factory()->create();
    app(CreditLedger::class)->purchase($user, $balanceNanos);

    $generation = Generation::factory()->for($user)->create($attributes);
    app(CreditLedger::class)->hold($generation);

    return $generation->refresh();
}

function ledgerCount(Generation $generation, CreditTransactionType $type): int
{
    return $generation->user->creditTransactions()->where('generation_id', $generation->id)->where('type', $type)->count();
}
