<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case Purchase = 'purchase';
    /** Reserves the maximum possible charge while a generation is in flight. */
    case Hold = 'hold';
    /** Returns the hold once the generation is final. */
    case Release = 'release';
    /** The actual charge for a succeeded generation. */
    case Charge = 'charge';
}
