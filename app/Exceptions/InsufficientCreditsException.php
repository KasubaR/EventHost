<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a credit spend is attempted with an empty balance. The balance is
 * re-read under a row lock at spend time, so this fires on the race that the
 * earlier `canCreateEvent()` pre-check cannot catch.
 */
class InsufficientCreditsException extends RuntimeException
{
    public function __construct(string $message = 'Not enough event credits.')
    {
        parent::__construct($message);
    }
}
