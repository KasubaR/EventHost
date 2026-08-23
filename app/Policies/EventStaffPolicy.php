<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use App\Support\EventAccess;

/**
 * Staff management is never delegated to a Manager (locked decision, Phase
 * 18 plan) — every ability here is owner-only, unlike every other policy in
 * this app that now checks EventAccess::canManage().
 */
class EventStaffPolicy
{
    /**
     * Authorized against the parent Event directly (via `authorize('manage',
     * [EventStaff::class, $event])`) for viewing the staff list and creating
     * an invite — there's no EventStaff row yet to authorize against for the
     * latter, same reason EventStaffLinkController authorizes 'update' on
     * $event before creating a link.
     */
    public function manage(User $user, Event $event): bool
    {
        return EventAccess::isOwner($user, $event);
    }

    public function update(User $user, EventStaff $eventStaff): bool
    {
        return EventAccess::isOwner($user, $eventStaff->event);
    }

    public function delete(User $user, EventStaff $eventStaff): bool
    {
        return EventAccess::isOwner($user, $eventStaff->event);
    }
}
