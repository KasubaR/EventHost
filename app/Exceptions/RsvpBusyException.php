<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Every RSVP on an event waits for the same row lock, so on a very busy event the database can give up waiting (lock wait
 * timeout) or pick this request as the loser of a deadlock after the retries are spent. Nothing was saved. Rendered once, in
 * bootstrap/app.php: web goes back to the form with the answers kept, JSON gets 503 with `code: rsvp_busy` and a Retry-After.
 * plans/rsvp-attendance.md Phase 4.
 */
class RsvpBusyException extends RuntimeException
{
    public function __construct(string $message = 'We are receiving a lot of responses right now and could not save yours. Nothing was changed, so please send it again in a moment.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
