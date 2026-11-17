<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\CreditLedger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgePricing())]);
    Queue::fake();
});

function userWithCredits(int $nanos): User
{
    $user = User::factory()->create();
    app(CreditLedger::class)->purchase($user, $nanos);

    return $user->refresh();
}

$validRequest = [
    'prompt' => 'A lighthouse on a cliff at dawn',
    'size' => '1536x1024',
    'quality' => 'high',
    'reference_images' => ['https://example.com/style.png'],
];

it('saves the generation, holds its maximum price and queues the submission', function () use ($validRequest) {
    $user = userWithCredits(1_000_000_000);

    $this->actingAs($user)->post(route('generations.store'), $validRequest)
        ->assertRedirect(route('generations.index'));

    $generation = Generation::query()->sole();

    // 31 characters = 8 text tokens (max 10), 1 image = 765, high landscape = 6450 output, reasoning 4500 mid / 8000 max.
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->reference_images)->toBe(['https://example.com/style.png'])
        ->and($generation->estimated_charge_nanos)->toBe(402_425_000)
        ->and($generation->max_charge_nanos)->toBe(457_125_000)
        ->and($user->refresh()->balance_nanos)->toBe(1_000_000_000 - 457_125_000)
        ->and(ledgerCount($generation, CreditTransactionType::Hold))->toBe(1);

    Queue::assertPushed(SubmitGenerationJob::class, fn ($job) => $job->generationId === $generation->id);
});

it('refuses a generation the user cannot afford at its maximum price', function () use ($validRequest) {
    $user = userWithCredits(1_000);

    $this->actingAs($user)->post(route('generations.store'), $validRequest)
        ->assertSessionHasErrors('credits');

    expect(Generation::query()->count())->toBe(0)
        ->and($user->refresh()->balance_nanos)->toBe(1_000);
    Queue::assertNothingPushed();
});

it('accepts a generation when the balance exactly covers its maximum price', function () use ($validRequest) {
    $user = userWithCredits(457_125_000);

    $this->actingAs($user)->post(route('generations.store'), $validRequest)->assertSessionHasNoErrors();

    expect($user->refresh()->balance_nanos)->toBe(0);
});

it('validates the request against Pixelforge limits', function (array $override, string $field) use ($validRequest) {
    $this->actingAs(userWithCredits(1_000_000_000))
        ->post(route('generations.store'), array_merge($validRequest, $override))
        ->assertSessionHasErrors($field);

    Queue::assertNothingPushed();
})->with([
    'missing prompt' => [['prompt' => ''], 'prompt'],
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'too many reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'non-http reference image' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
]);

it('requires sign in', function () use ($validRequest) {
    $this->post(route('generations.store'), $validRequest)->assertRedirect(route('login'));
});
