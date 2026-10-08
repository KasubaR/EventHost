<?php

namespace App\Exceptions;

use App\Models\Event;
use RuntimeException;

/**
 * An RSVP was submitted after the window closed. Thrown by RsvpSubmissionService (the one place that
 * enforces it, under the event lock) and by the guest-facing form requests as an early, friendlier
 * refusal. Rendered once, in bootstrap/app.php: a redirect to the closed page with a message for web
 * pages, 403 with `code: rsvp_closed` for JSON. Plan: plans/rsvp-deadline-fixes.md (G4, G5).
 */
class RsvpClosedException extends RuntimeException
{
    /** `host`, `deadline`, `started` or `unavailable` (see Event::rsvpClosureCause()). */
    public readonly string $reason;

    /** When RSVP closes (ISO 8601, venue offset), for clients; null without an event. */
    public readonly ?string $closesAt;

    /**
     * @param  bool  $mayReduce  the guest can still cancel or reduce an existing RSVP until the event starts
     */
    public function __construct(public readonly bool $mayReduce = false, ?Event $event = null)
    {
        $this->reason = $event?->rsvpClosureCause() ?? 'deadline';
        $this->closesAt = $this->reason === 'host' ? null : $event?->rsvpClosesAt()?->toIso8601String();

        if ($event !== null) {
            parent::__construct($event->rsvpClosedGuestMessage($mayReduce));

            return;
        }

        parent::__construct($mayReduce
            ? 'The RSVP deadline has passed, so a new or larger response cannot be saved. You can still cancel your RSVP or reduce the number of guests.'
            : 'The RSVP deadline has passed, so this response could not be saved. If you still want to come, please contact the host.');
    }
}
