<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Door check-in is only for the calendar day of the event. Thrown when a
 * confirm is attempted before or after that date.
 */
class CheckInClosedException extends RuntimeException
{
    public function __construct(string $message = 'Check-in is only available on the event date.')
    {
        parent::__construct($message);
    }
}
