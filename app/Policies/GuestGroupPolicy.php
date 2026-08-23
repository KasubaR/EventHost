<?php

namespace App\Policies;

use App\Models\GuestGroup;
use App\Models\User;
use App\Support\EventAccess;

class GuestGroupPolicy
{
    public function update(User $user, GuestGroup $guestGroup): bool
    {
        $guestGroup->loadMissing('event');

        return EventAccess::canManage($user, $guestGroup->event);
    }

    public function delete(User $user, GuestGroup $guestGroup): bool
    {
        $guestGroup->loadMissing('event');

        return EventAccess::canManage($user, $guestGroup->event);
    }
}
