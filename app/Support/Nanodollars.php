<?php

namespace App\Support;

use BcMath\Number;

/**
 * All money is stored as integer nanodollars ($0.000000001), the precision
 * Pixelforge bills in, so provider costs are exact and never drift.
 */
final class Nanodollars
{
    public const int PER_DOLLAR = 1_000_000_000;

    /**
     * Parse an exact decimal dollar string (e.g. "0.169080000") into nanodollars.
     *
     * @param  numeric-string  $dollars
     */
    public static function fromDollars(string $dollars): int
    {
        return (int) (string) (new Number($dollars) * self::PER_DOLLAR)->round(0);
    }
}
