<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Support\EventAccess;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Owner or any accepted staff role — lets Check-in staff load the event
     * show page for context, not just the scanner itself.
     */
    public function view(User $user, Event $event): bool
    {
        return EventAccess::isOwner($user, $event) || $event->staffRoleFor($user) !== null;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Content management: editing event details, guests, tickets, tables,
     * photos, invitation design, and (Phase 17) staff scanner links. Owner
     * or an accepted Event Manager.
     */
    public function update(User $user, Event $event): bool
    {
        return EventAccess::canManage($user, $event);
    }

    /**
     * Door check-in scanning — guest and ticket. A strict superset of who can
     * manage() (Managers can already do this), plus Check-in staff, who can
     * do nothing else.
     */
    public function checkIn(User $user, Event $event): bool
    {
        return EventAccess::canCheckIn($user, $event);
    }

    /**
     * Invitation events: spends an event credit. Ticketed events: submits
     * for EventHost review, the pipeline that ends in ticket sales going
     * live (EventTicketingController::submit()) — both billing-adjacent, so
     * this stays owner-only even for a Manager who can otherwise touch
     * everything else about the event.
     */
    public function publish(User $user, Event $event): bool
    {
        return EventAccess::isOwner($user, $event);
    }

    public function delete(User $user, Event $event): bool
    {
        return EventAccess::isOwner($user, $event);
    }
}
