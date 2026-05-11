<?php

namespace App\Policies;

use App\Models\Guest;
use App\Models\User;

class GuestPolicy
{
    public function update(User $user, Guest $guest): bool
    {
        return $user->id === $guest->event->user_id;
    }

    public function delete(User $user, Guest $guest): bool
    {
        return $user->id === $guest->event->user_id;
    }
}
