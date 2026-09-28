<?php

namespace App\Enums;

enum RsvpApprovalStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'No approval needed',
            self::Pending => 'Awaiting host review',
            self::Approved => 'Approved',
            self::Rejected => 'Declined',
        };
    }

    /**
     * Visual tone for the guest-list pill.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Approved, self::NotRequired => 'ok',
            self::Pending => 'info',
            self::Rejected => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Approved, self::NotRequired => 'fa-circle-check',
            self::Pending => 'fa-hourglass-half',
            self::Rejected => 'fa-circle-xmark',
        };
    }
}
