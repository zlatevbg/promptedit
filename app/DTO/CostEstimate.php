<?php

namespace App\DTO;

/**
 * What a generation is expected to cost the user, and the most it can cost. Both include the markup.
 */
final readonly class CostEstimate
{
    public function __construct(
        public int $expectedNanos,
        public int $maxNanos,
    ) {}
}
