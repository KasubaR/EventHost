<?php

namespace App\Enums;

/**
 * Admin-approval state for a free-registration public event (public audience
 * + invitation product kind) — plans/public-private-portals.md Phase 4c.
 * Deliberately its own enum rather than reusing TicketingStatus: the cases
 * read the same, but TicketingActivationService's submit/approve checks
 * (active ticket type, hero image) are ticket-specific and don't apply here,
 * and this event has no commission — the admin sets a one-off quote instead.
 */
enum PublicRegistrationStatus: string
{
    case NotApplicable = 'not_applicable';
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'Not a free-registration event',
            self::Draft => 'Not submitted',
            self::PendingReview => 'Awaiting EventHost review',
            self::Approved => 'Approved — awaiting payment',
            self::Rejected => 'Declined',
        };
    }

    /**
     * Visual tone for admin pills and callouts — same palette as
     * TicketingStatus::tone() for a consistent admin panel look.
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
            self::NotApplicable => 'fa-ban',
        };
    }
}
