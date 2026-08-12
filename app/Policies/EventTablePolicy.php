<?php

namespace App\Policies;

use App\Models\EventTable;
use App\Models\User;

class EventTablePolicy
{
    public function update(User $user, EventTable $table): bool
    {
        return $user->id === $table->event->user_id;
    }

    public function delete(User $user, EventTable $table): bool
    {
        return $user->id === $table->event->user_id;
    }
}
