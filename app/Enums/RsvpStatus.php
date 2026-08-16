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

    /**
     * Guest-facing past-tense wording for the confirmation page/email ("Your
     * response: Attending"), distinct from label()'s verb form which reads as
     * a form action ("Accept this invitation") on the RSVP buttons themselves.
     */
    public function attendanceLabel(): string
    {
        return match ($this) {
            self::Accepted => 'Attending',
            self::Declined => 'Not Attending',
            self::Maybe => 'Maybe Attending',
        };
    }
}
