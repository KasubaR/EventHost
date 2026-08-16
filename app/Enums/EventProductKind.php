<?php

namespace App\Enums;

enum EventProductKind: string
{
    case Invitation = 'invitation';
    case Ticketed = 'ticketed';

    public function label(): string
    {
        return match ($this) {
            self::Invitation => 'Invitation / RSVP',
            self::Ticketed => 'Ticketed event',
        };
    }
}
