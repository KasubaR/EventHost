<?php

namespace App\Enums;

enum PhotoStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Approved => 'Approved',
            self::Hidden => 'Hidden',
        };
    }
}
