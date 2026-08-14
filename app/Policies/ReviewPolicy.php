<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Admin-authored reviews have no user_id, so this denies them by design —
     * hosts can only touch their own submissions.
     */
    public function update(User $user, Review $review): bool
    {
        return $review->user_id !== null && $user->id === $review->user_id;
    }

    public function delete(User $user, Review $review): bool
    {
        return $this->update($user, $review);
    }
}
