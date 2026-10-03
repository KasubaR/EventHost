<?php

namespace App\Rules;

use App\Support\EventAttendance;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A host may not lower `guest_limit` under the seats already confirmed — that would leave the
 * event over its own limit with nobody able to change their RSVP. Clearing it (unlimited) is fine.
 */
class GuestLimitNotBelowConfirmed implements ValidationRule
{
    public function __construct(private readonly int $eventId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return;
        }

        $held = EventAttendance::heldSeats($this->eventId);

        if ((int) $value < $held) {
            $fail("The guest limit can't be lower than the {$held} seat".($held === 1 ? '' : 's').' already confirmed. Decline or reject some RSVPs first.');
        }
    }
}
