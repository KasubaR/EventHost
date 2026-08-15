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
     * @return array{
     *     guest: array{
     *         id: int, name: string, checked_in_at: ?string,
     *         email: ?string, phone: ?string, table: ?string,
     *         meal_preference: ?string, rsvp_note: ?string,
     *     },
     *     already_checked_in: bool,
     * }
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

        // Door staff act on this the moment it lands — a full name-only match still
        // leaves them guessing which table to point someone to, or whether the
        // kitchen needs to know about a dietary restriction. loadMissing so every
        // caller (dashboard scanner, staff-link scanner, manual lookup) gets the
        // same payload without each having to remember the eager-load itself.
        $guest->loadMissing(['rsvp', 'eventTable']);

        return [
            'guest' => [
                'id' => $guest->id,
                'name' => $guest->name,
                'checked_in_at' => $guest->checked_in_at?->toIso8601String(),
                'email' => $guest->email,
                'phone' => $guest->phone,
                'table' => $guest->tableLabel(),
                'meal_preference' => $guest->rsvp?->meal_preference,
                'rsvp_note' => $guest->rsvp?->message,
            ],
            'already_checked_in' => $alreadyIn,
        ];
    }
}
