<?php

namespace App\Policies;

use App\Models\EventStaffLink;
use App\Models\User;

class EventStaffLinkPolicy
{
    public function delete(User $user, EventStaffLink $link): bool
    {
        return $user->id === $link->event->user_id;
    }
}
