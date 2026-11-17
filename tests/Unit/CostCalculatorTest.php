<?php

use App\DTO\Pricing;
use App\Enums\Quality;
use App\Enums\Size;
use App\Services\CostCalculator;

$rates = pixelforgePricing()['rates'];

it('prices token usage exactly like Pixelforge bills it', function () use ($rates) {
    // The day total from the docs' GET /v1/usage example: "0.169080000".
    $usage = ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950];

    expect((new CostCalculator)->providerCost($usage, $rates))->toBe(169_080_000);
});

it('prices every token type, including fractional per-token rates', function () use ($rates) {
    // The docs' succeeded generation: 12×$5 + 765×$10 + 6450×$40 + 3184×$12.50 per 1M = $0.30551.
    $usage = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411];

    expect((new CostCalculator)->providerCost($usage, $rates))->toBe(305_510_000);
});

it('bills at the rates it is given, not the current ones', function () {
    $usage = ['input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 1_000_000, 'reasoning_tokens' => 0];

    expect((new CostCalculator)->providerCost($usage, ['output_tokens' => '0.003']))->toBe(3_000_000);
});

it('adds the 25% markup and rounds up to the next nanodollar', function (int $providerCost, int $userPrice) {
    expect((new CostCalculator)->userPrice($providerCost))->toBe($userPrice);
})->with([
    'zero' => [0, 0],
    'exact' => [4, 5],
    'fraction rounds up' => [1, 2],
    'typical' => [169_080_000, 211_350_000],
]);

it('estimates the expected price and the maximum possible price', function () {
    $pricing = Pricing::fromArray(pixelforgePricing());

    // 40 characters = 10 text tokens (up to 12 with the 15% variance), one reference image (765), 4300 output tokens.
    $estimate = (new CostCalculator)->estimate($pricing, str_repeat('a', 40), Size::Square, Quality::High, 1);

    // Expected: 10×$5 + 765×$10 + 4300×$40 + 4500 (mid reasoning)×$12.50 per 1M = $0.23595, +25% = $0.2949375.
    // Max:      12×$5 + 765×$10 + 4300×$40 + 8000 (max reasoning)×$12.50 per 1M = $0.27971, +25% = $0.3496375.
    expect($estimate->expectedNanos)->toBe(294_937_500)
        ->and($estimate->maxNanos)->toBe(349_637_500);
});

it('counts prompt length in Unicode characters, not bytes', function () {
    $pricing = Pricing::fromArray(pixelforgePricing());
    $calculator = new CostCalculator;

    // 8 characters but 16 bytes: 2 text tokens either way the prompt is written.
    $accented = $calculator->estimate($pricing, str_repeat('é', 8), Size::Square, Quality::Low, 0);
    $ascii = $calculator->estimate($pricing, str_repeat('e', 8), Size::Square, Quality::Low, 0);

    expect($accented)->toEqual($ascii);
});
