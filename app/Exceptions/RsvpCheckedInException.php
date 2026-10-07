<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A guest who is already checked in tried to decline or reduce their RSVP. Check-in opens up to a day before the
 * start while reductions stay open until it, so without this a guest could be "checked in" and "Declined" at once.
 * Thrown by RsvpSubmissionService under the lock; rendered in bootstrap/app.php (a message on the form for web, 403 with
 * `code: rsvp_checked_in` for JSON). plans/rsvp-status-changes.md Phase 2.
 */
class RsvpCheckedInException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('You are already checked in, so this response can no longer be cancelled or reduced here. Please speak to the host.');
    }
}
