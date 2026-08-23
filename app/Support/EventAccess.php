<?php

namespace App\Support;

use App\Enums\EventStaffRole;
use App\Models\Event;
use App\Models\User;

/**
 * Single place every event-scoped policy asks "can this user do X on this
 * event". Owner always passes everything; an accepted App\Models\EventStaff
 * row grants Manager (operate everything short of billing/destructive
 * actions and staff management) or Check-in (door scanning only).
 *
 * canCheckIn() is deliberately a superset of canManage()'s check-in reach,
 * not a separate track — a Manager can already do anything a Check-in
 * staffer can.
 */
class EventAccess
{
    public static function isOwner(User $user, Event $event): bool
    {
        return $user->id === $event->user_id;
    }

    public static function canManage(User $user, Event $event): bool
    {
        return self::isOwner($user, $event)
            || $event->staffRoleFor($user) === EventStaffRole::Manager;
    }

    public static function canCheckIn(User $user, Event $event): bool
    {
        if (self::isOwner($user, $event)) {
            return true;
        }

        return in_array($event->staffRoleFor($user), [EventStaffRole::Manager, EventStaffRole::CheckIn], true);
    }
}
