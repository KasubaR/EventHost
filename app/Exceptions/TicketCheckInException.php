<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by TicketCheckInService when a scanned ticket cannot be checked in —
 * wrong day or cancelled. Distinct from an "already used" ticket, which is
 * not an error: re-scanning a used ticket is a normal door-staff action and
 * returns already_checked_in = true instead, same as Guest's CheckInService.
 */
class TicketCheckInException extends RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
