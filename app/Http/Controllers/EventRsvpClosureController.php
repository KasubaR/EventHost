<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\RedirectResponse;

/**
 * The host stops (and resumes) taking RSVPs by hand. plans/rsvp-deadline-moments.md Phase 2.
 *
 * Closing blocks new answers and increases exactly like a passed deadline, but guests who already answered can still cancel
 * or reduce until the event starts, and the invitation page stays visible (pausing hides it). Nobody is notified. Reopening
 * sends the host a "remind them from the guest list" prompt, never an automatic broadcast.
 */
class EventRsvpClosureController extends Controller
{
    public function store(Event $event): RedirectResponse
    {
        $this->authorize('pause', $event);

        if (! self::canClose($event)) {
            return back()->withErrors(['event' => 'Only a live invitation that has not taken place yet can stop taking RSVPs.']);
        }

        // Closing twice keeps the first moment.
        if (! $event->rsvpManuallyClosed()) {
            $event->forceFill(['rsvp_closed_at' => now()])->save();
        }

        return back()->with('status', 'rsvp-closed');
    }

    public function destroy(Event $event): RedirectResponse
    {
        $this->authorize('pause', $event);

        $wasClosed = $event->rsvpManuallyClosed();

        if ($wasClosed) {
            $event->forceFill(['rsvp_closed_at' => null])->save();
        }

        $response = back()->with('status', 'rsvp-reopened');

        // Only worth a prompt when RSVP is actually open again (the deadline may still have passed).
        return $wasClosed && $event->fresh()->isRsvpOpen()
            ? $response->with('rsvp_reopened', route('events.guests.index', $event))
            : $response;
    }

    public static function canClose(Event $event): bool
    {
        return $event->isInvitation()
            && $event->is_published
            && ! $event->trashed()
            && ! $event->isCancelled()
            && ! $event->isLocked();
    }
}
