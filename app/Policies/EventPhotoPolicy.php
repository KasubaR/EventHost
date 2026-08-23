<?php

namespace App\Policies;

use App\Models\EventPhoto;
use App\Models\User;
use App\Support\EventAccess;

class EventPhotoPolicy
{
    public function update(User $user, EventPhoto $photo): bool
    {
        return EventAccess::canManage($user, $photo->event);
    }

    public function delete(User $user, EventPhoto $photo): bool
    {
        return EventAccess::canManage($user, $photo->event);
    }
}
