<?php

namespace App\Policies;

use App\Models\GuestGroup;
use App\Models\User;

class GuestGroupPolicy
{
    public function update(User $user, GuestGroup $guestGroup): bool
    {
        $guestGroup->loadMissing('event');

        return $user->id === $guestGroup->event->user_id;
    }

    public function delete(User $user, GuestGroup $guestGroup): bool
    {
        $guestGroup->loadMissing('event');

        return $user->id === $guestGroup->event->user_id;
    }
}
