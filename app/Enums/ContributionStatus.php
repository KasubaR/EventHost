<?php

namespace App\Enums;

enum ContributionStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'No payment yet',
            self::Partial => 'Partially paid',
            self::Completed => 'Fully paid',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warn',
            self::Partial => 'info',
            self::Completed => 'ok',
        };
    }
}
