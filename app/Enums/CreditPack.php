<?php

namespace App\Enums;

use App\Support\Nanodollars;

/**
 * Fake purchase options, in whole US dollars.
 */
enum CreditPack: int
{
    case Five = 5;
    case Twenty = 20;

    public function nanodollars(): int
    {
        return $this->value * Nanodollars::PER_DOLLAR;
    }
}
