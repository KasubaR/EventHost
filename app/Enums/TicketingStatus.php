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
}
