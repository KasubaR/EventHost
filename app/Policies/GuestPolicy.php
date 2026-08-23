<?php

namespace App\Policies;

use App\Models\Guest;
use App\Models\User;
use App\Support\EventAccess;

class GuestPolicy
{
    public function update(User $user, Guest $guest): bool
    {
        return EventAccess::canManage($user, $guest->event);
    }

    public function delete(User $user, Guest $guest): bool
    {
        return EventAccess::canManage($user, $guest->event);
    }

    /**
     * Door check-in only — deliberately separate from update() so Check-in
     * staff can confirm a guest's arrival without gaining the ability to
     * edit their other details. CheckInController::confirmGuest() uses this;
     * every other Guest write goes through update().
     */
    public function checkIn(User $user, Guest $guest): bool
    {
        return EventAccess::canCheckIn($user, $guest->event);
    }
}
