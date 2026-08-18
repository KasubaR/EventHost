<?php

namespace App\Enums;

enum TicketingStatus: string
{
    case NotApplicable = 'not_applicable';
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'Not a ticketed event',
            self::Draft => 'Tickets not submitted',
            self::PendingReview => 'Awaiting EventHost review',
            self::Approved => 'Ticket sales approved',
            self::Rejected => 'Activation declined',
        };
    }

    /**
     * Visual tone for admin pills and callouts.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Approved => 'ok',
            self::PendingReview => 'info',
            self::Rejected => 'danger',
            self::Draft, self::NotApplicable => 'warn',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Approved => 'fa-circle-check',
            self::PendingReview => 'fa-hourglass-half',
            self::Rejected => 'fa-circle-xmark',
            self::Draft => 'fa-pen-to-square',
            self::NotApplicable => 'fa-ticket',
        };
    }
}
