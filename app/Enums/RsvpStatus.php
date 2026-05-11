<?php

namespace App\Enums;

enum RsvpStatus: string
{
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Maybe = 'maybe';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Accept',
            self::Declined => 'Decline',
            self::Maybe => 'Maybe',
        };
    }

    public function countsTowardGuestLimit(): bool
    {
        return $this === self::Accepted;
    }
}
