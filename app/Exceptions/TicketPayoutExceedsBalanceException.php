<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an admin tries to record a ticket payout for more than an
 * event's current pending balance (or an amount <= 0). The balance is
 * re-read under a row lock at record time, same posture as
 * InsufficientCreditsException for event credits.
 */
class TicketPayoutExceedsBalanceException extends RuntimeException
{
    public function __construct(string $message = 'That payout amount exceeds the event\'s pending balance.')
    {
        parent::__construct($message);
    }
}
