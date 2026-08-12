<?php

namespace App\Services;

use App\Models\Guest;

/**
 * Shared "mark this guest arrived" logic used by both the authenticated
 * host/staff scanner (CheckInController) and the shareable, no-login
 * staff scanner link (PublicCheckInController).
 */
class CheckInService
{
    /**
     * @return array{guest: array{id: int, name: string, checked_in_at: ?string}, already_checked_in: bool}
     */
    public function confirm(Guest $guest, ?int $staffUserId): array
    {
        $alreadyIn = $guest->isCheckedIn();

        if (! $alreadyIn) {
            $guest->forceFill([
                'checked_in_at' => now(),
                'checked_in_by' => $staffUserId,
            ])->save();
        }

        return [
            'guest' => [
                'id' => $guest->id,
                'name' => $guest->name,
                'checked_in_at' => $guest->checked_in_at?->toIso8601String(),
            ],
            'already_checked_in' => $alreadyIn,
        ];
    }
}
