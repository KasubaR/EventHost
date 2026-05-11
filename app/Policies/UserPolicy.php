<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function update(User $authenticated, User $subject): bool
    {
        return $authenticated->id === $subject->id;
    }

    public function delete(User $authenticated, User $subject): bool
    {
        return $authenticated->id === $subject->id;
    }
}
