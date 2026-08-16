<?php

namespace App\Enums;

enum CommissionMode: string
{
    case Absorb = 'absorb';
    case PassThrough = 'pass_through';

    public function label(): string
    {
        return match ($this) {
            self::Absorb => 'Deducted from my earnings',
            self::PassThrough => 'Added to the buyer’s price',
        };
    }
}
