<?php

namespace App\Exceptions;

/**
 * The door refused a guest because of their RSVP: they declined, or the host rejected the request. Extends
 * CheckInClosedException so every scanner endpoint that already turns "closed" into a 403 with a message handles
 * this too. The host-side endpoints add `can_override` so the scanner can offer "Check in anyway".
 * plans/rsvp-status-changes.md Phase 2.
 */
class CheckInNotAllowedException extends CheckInClosedException
{
    /**
     * @param  string  $reason  `declined` or `rejected`
     */
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
