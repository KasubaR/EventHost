<?php

namespace App\Enums;

enum PublicInvitationStatus: string
{
    case Gone = 'gone';
    case Cancelled = 'cancelled';
    case Unavailable = 'unavailable';
    case Ended = 'ended';

    public function title(): string
    {
        return match ($this) {
            self::Gone => 'Invitation no longer available',
            self::Cancelled => 'Event cancelled',
            self::Unavailable => 'Invitation unavailable',
            self::Ended => 'Event has ended',
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Gone => 'This invitation is no longer available.',
            self::Cancelled => 'This event has been cancelled.',
            self::Unavailable => 'This invitation is temporarily unavailable.',
            self::Ended => 'This event has ended.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Gone => 'fa-link-slash',
            self::Cancelled => 'fa-ban',
            self::Unavailable => 'fa-pause',
            self::Ended => 'fa-flag-checkered',
        };
    }
}
