<?php

namespace App\Services;

use App\DTO\CostEstimate;
use App\DTO\Pricing;
use App\Enums\Quality;
use App\Enums\Size;
use App\Enums\TokenType;
use BcMath\Number;

/**
 * Pixelforge cost and user price maths. Everything is integer nanodollars; decimals go through bcmath, never floats.
 */
class CostCalculator
{
    public const int MARKUP_PERCENT = 25;

    /** Rates are dollars per 1M tokens: dollars × 1e9 nanodollars ÷ 1e6 tokens. */
    private const int NANODOLLARS_PER_RATE_UNIT = 1000;

    /**
     * The expected and maximum user price, before Pixelforge tells us the real token usage.
     */
    public function estimate(Pricing $pricing, string $prompt, Size $size, Quality $quality, int $referenceImageCount): CostEstimate
    {
        $textTokens = intdiv(mb_strlen($prompt) + $pricing->charactersPerTextToken - 1, $pricing->charactersPerTextToken);
        $maxTextTokens = (int) (string) (new Number($textTokens) * (new Number($pricing->textTokenVariance) + 1))->ceil();
        $reasoning = $pricing->reasoningTokensFor($quality);

        $knownUsage = [
            TokenType::InputImage->value => $referenceImageCount * $pricing->tokensPerReferenceImage,
            TokenType::Output->value => $pricing->outputTokensFor($quality, $size),
        ];

        $expectedUsage = $knownUsage + [
            TokenType::InputText->value => $textTokens,
            TokenType::Reasoning->value => intdiv($reasoning['min'] + $reasoning['max'], 2),
        ];

        $maxUsage = $knownUsage + [
            TokenType::InputText->value => $maxTextTokens,
            TokenType::Reasoning->value => $reasoning['max'],
        ];

        return new CostEstimate(
            expectedNanos: $this->userPrice($this->providerCost($expectedUsage, $pricing->rates)),
            maxNanos: $this->userPrice($this->providerCost($maxUsage, $pricing->rates)),
        );
    }

    /**
     * What Pixelforge charges for this token usage at these rates, exactly.
     *
     * @param  array<string, int>  $usage
     * @param  array<string, numeric-string>  $rates  dollars per 1M tokens, keyed like the usage
     */
    public function providerCost(array $usage, array $rates): int
    {
        $cost = new Number(0);

        foreach ($rates as $tokenType => $rate) {
            $cost += new Number($rate) * ($usage[$tokenType] ?? 0) * self::NANODOLLARS_PER_RATE_UNIT;
        }

        return (int) (string) $cost->round(0);
    }

    /**
     * Provider cost plus markup, rounded up to the next nanodollar so we never undercharge.
     */
    public function userPrice(int $providerCostNanos): int
    {
        $percent = 100 + self::MARKUP_PERCENT;

        return intdiv($providerCostNanos * $percent + 99, 100);
    }
}
