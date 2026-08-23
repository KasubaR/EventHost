<?php

namespace App\Policies;

use App\Models\EventStaffLink;
use App\Models\User;
use App\Support\EventAccess;

class EventStaffLinkPolicy
{
    public function delete(User $user, EventStaffLink $link): bool
    {
        return EventAccess::canManage($user, $link->event);
    }
}
