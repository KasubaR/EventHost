<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AdminActivity
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(string $description, array $properties = []): void
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return;
        }

        activity()
            ->causedBy($actor)
            ->withProperties($properties)
            ->log($description);
    }
}
