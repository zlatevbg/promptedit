<?php

namespace App\DTO;

use App\Enums\Quality;
use App\Enums\Size;

/**
 * Pixelforge's published pricing (GET /v1/pricing). Rates are US dollars per 1M tokens.
 */
final readonly class Pricing
{
    /**
     * @param  array<string, numeric-string>  $rates
     * @param  numeric-string  $textTokenVariance
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public array $rates,
        public int $charactersPerTextToken,
        public string $textTokenVariance,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $pricing
     */
    public static function fromArray(array $pricing): self
    {
        /** @var numeric-string $textTokenVariance Kept as a string so the maths stays exact. */
        $textTokenVariance = (string) $pricing['tokens']['text_token_variance'];

        return new self(
            rates: $pricing['rates'],
            charactersPerTextToken: $pricing['tokens']['characters_per_text_token'],
            textTokenVariance: $textTokenVariance,
            tokensPerReferenceImage: $pricing['tokens']['per_reference_image'],
            outputTokens: $pricing['tokens']['output'],
            reasoningTokens: $pricing['tokens']['reasoning'],
        );
    }

    public function outputTokensFor(Quality $quality, Size $size): int
    {
        return $this->outputTokens[$quality->value][$size->value];
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningTokensFor(Quality $quality): array
    {
        return $this->reasoningTokens[$quality->value];
    }
}
