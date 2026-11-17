import type { Pricing, Quality, Size } from '@/types/generation';

/**
 * Unicode code points, like PHP's mb_strlen() and Pixelforge's own count (String.length counts UTF-16 units).
 */
export function countCharacters(text: string): number {
    // oxlint-disable-next-line typescript/no-misused-spread -- code points are exactly what we want here
    return [...text].length;
}

export type CostEstimate = { expectedNanos: number; maxNanos: number };

/** Rates are dollars per 1M tokens: dollars × 1e9 nanodollars ÷ 1e6 tokens. */
const NANODOLLARS_PER_RATE_UNIT = 1000;

/**
 * Live preview of App\Services\CostCalculator::estimate(). Display only: the server recomputes
 * the estimate (and the credit hold) itself when the generation is submitted.
 */
export function estimateCost(
    pricing: Pricing,
    markupPercent: number,
    prompt: string,
    size: Size,
    quality: Quality,
    referenceImageCount: number,
): CostEstimate {
    const textTokens = Math.ceil(
        countCharacters(prompt) / pricing.charactersPerTextToken,
    );
    const maxTextTokens = Math.ceil(
        textTokens * (1 + Number(pricing.textTokenVariance)) - 1e-9,
    );
    const reasoning = pricing.reasoningTokens[quality];

    const known = {
        input_image_tokens:
            referenceImageCount * pricing.tokensPerReferenceImage,
        output_tokens: pricing.outputTokens[quality][size],
    };

    const providerCost = (usage: Record<string, number>): number =>
        Object.entries(pricing.rates).reduce(
            (total, [tokenType, rate]) =>
                total +
                Math.round(
                    Number(rate) *
                        NANODOLLARS_PER_RATE_UNIT *
                        (usage[tokenType] ?? 0),
                ),
            0,
        );

    const userPrice = (cost: number): number =>
        Math.ceil((cost * (100 + markupPercent)) / 100);

    return {
        expectedNanos: userPrice(
            providerCost({
                ...known,
                input_text_tokens: textTokens,
                reasoning_tokens: Math.floor(
                    (reasoning.min + reasoning.max) / 2,
                ),
            }),
        ),
        maxNanos: userPrice(
            providerCost({
                ...known,
                input_text_tokens: maxTextTokens,
                reasoning_tokens: reasoning.max,
            }),
        ),
    };
}
