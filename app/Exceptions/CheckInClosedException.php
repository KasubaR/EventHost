<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Door check-in runs for a window around the event's start, not its calendar
 * day — see Event::isCheckInOpen(). Thrown when a confirm lands outside it.
 */
class CheckInClosedException extends RuntimeException
{
    public function __construct(string $message = 'Check-in is not open for this event yet, or has already closed.')
    {
        parent::__construct($message);
    }
}
