<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A guest submitted an RSVP to an invitation that is no longer taking responses for a reason the page
 * itself explains: the event was deleted, cancelled or paused since the form was opened, or a private
 * event's guest list filled up. Thrown by the guest-facing form requests in place of a bare abort(403),
 * whose error page talks about expired verification links. Rendered once, in bootstrap/app.php: web goes
 * back to the GET page (which already renders the right status view), JSON gets 403 with
 * `code: rsvp_unavailable`. plans/rsvp-token-edge-cases.md (T4).
 */
class RsvpUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'This invitation is no longer available, so your response could not be saved. If you still want to come, please contact the host.')
    {
        parent::__construct($message);
    }
}
