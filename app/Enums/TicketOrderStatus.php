<?php

namespace App\Enums;

enum TicketOrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case PaymentProcessing = 'payment_processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::PaymentProcessing => 'Payment processing',
            self::Paid => 'Paid',
            self::Failed => 'Payment failed',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Refunded, self::Cancelled, self::Expired], true);
    }

    public function isInFlight(): bool
    {
        return $this === self::PendingPayment || $this === self::PaymentProcessing;
    }

    /**
     * @return list<self>
     */
    public static function inFlight(): array
    {
        return [self::PendingPayment, self::PaymentProcessing];
    }

    /**
     * Unsuccessful terminals a later Lenco `completed` may still fulfill.
     * Paid stays paid; refunded is never re-issued.
     */
    public function isRecoverable(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled, self::Expired], true);
    }
}
