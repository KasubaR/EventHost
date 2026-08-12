<?php

namespace App\Policies;

use App\Models\EventPhoto;
use App\Models\User;

class EventPhotoPolicy
{
    public function update(User $user, EventPhoto $photo): bool
    {
        return $user->id === $photo->event->user_id;
    }

    public function delete(User $user, EventPhoto $photo): bool
    {
        return $user->id === $photo->event->user_id;
    }
}
