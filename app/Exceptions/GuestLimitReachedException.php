<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * An RSVP asked for more seats than the event's guest limit has left. Still an ordinary 422 validation
 * error on `status` (the shape clients already handle); the extra fields let the page preselect what
 * fits and let the API report `seats_left`. plans/plus-one-edge-cases.md Phase 5.
 */
class GuestLimitReachedException extends ValidationException
{
    public int $seatsLeft = 0;

    public int $heldSeats = 0;

    public int $requestedSeats = 0;

    /**
     * @param  int  $seatsLeft  Seats this guest could still hold, counting any they already have.
     * @param  int  $heldSeats  Seats they hold now (0 for a first RSVP).
     */
    public static function forSeats(int $seatsLeft, int $heldSeats, int $requestedSeats): self
    {
        $message = $seatsLeft === 0
            ? 'This event has reached its guest limit for confirmed attendees.'
            : "Only {$seatsLeft} ".($seatsLeft === 1 ? 'seat is' : 'seats are').' left for confirmed attendees.';

        // The guest asked for a plus-one that does not fit: say what still does, instead of leaving them
        // to guess that RSVPing for themselves alone would work.
        if ($seatsLeft === 1 && $requestedSeats > 1) {
            $message .= ' You can RSVP for yourself only.';
        }

        if ($heldSeats > 0) {
            $message .= ' Your current RSVP is unchanged.';
        }

        $e = static::withMessages(['status' => [$message]]);
        $e->seatsLeft = $seatsLeft;
        $e->heldSeats = $heldSeats;
        $e->requestedSeats = $requestedSeats;

        return $e;
    }
}
