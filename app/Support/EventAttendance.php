<?php

namespace App\Support;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\Rsvp;

/**
 * Seats that count against an event's `guest_limit`: accepted RSVPs the host has not rejected.
 * A pending request holds its seats (so approving can never overshoot), a rejected one frees
 * them — the same rule GuestGroup::seatsTaken() applies to a group's seat pool.
 */
class EventAttendance
{
    public static function heldSeats(int $eventId, ?int $exceptGuestId = null): int
    {
        return (int) Rsvp::query()
            ->where('event_id', $eventId)
            ->when($exceptGuestId !== null, fn ($q) => $q->where('guest_id', '!=', $exceptGuestId))
            ->where('status', RsvpStatus::Accepted)
            ->where('host_approval_status', '!=', RsvpApprovalStatus::Rejected)
            ->sum('attendee_count');
    }
}
