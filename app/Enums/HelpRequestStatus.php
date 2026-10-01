<?php

namespace App\Enums;

/**
 * Lifecycle of a help request. `Declined` is an addition to the plan's list: an admin
 * turning a request down is not the client cancelling it, and the client is told why.
 */
enum HelpRequestStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Declined = 'declined';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Waiting for our team',
            self::InProgress => 'Our team is working on it',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
        };
    }

    /**
     * Same tone vocabulary as the other admin status enums.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'ok',
            self::Open, self::InProgress => 'info',
            self::Declined => 'danger',
            self::Cancelled, self::Expired => 'warn',
        };
    }

    /**
     * Still occupying the client's single request slot.
     */
    public function isCurrent(): bool
    {
        return $this === self::Open || $this === self::InProgress;
    }
}
