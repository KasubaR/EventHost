<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Valid = 'valid';
    case Used = 'used';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::Used => 'Used',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
        };
    }
}
