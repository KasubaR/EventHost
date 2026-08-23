<?php

namespace App\Policies;

use App\Models\EventTable;
use App\Models\User;
use App\Support\EventAccess;

class EventTablePolicy
{
    public function update(User $user, EventTable $table): bool
    {
        return EventAccess::canManage($user, $table->event);
    }

    public function delete(User $user, EventTable $table): bool
    {
        return EventAccess::canManage($user, $table->event);
    }
}
