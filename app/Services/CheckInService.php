<?php

namespace App\Services;

use App\Exceptions\CheckInClosedException;
use App\Models\Guest;
use Illuminate\Support\Facades\DB;

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
     *         checked_in_by: ?string,
     *     },
     *     already_checked_in: bool,
     * }
     *
     * $viaLabel identifies a no-login staff-link door (EventStaffLink::scanLabel()).
     * It is the only attribution those scans have — $staffUserId is null for
     * them, since there is no account behind the link.
     */
    public function confirm(Guest $guest, ?int $staffUserId, ?string $viaLabel = null): array
    {
        return DB::transaction(function () use ($guest, $staffUserId, $viaLabel): array {
            /** @var Guest $locked */
            $locked = Guest::query()->whereKey($guest->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('event');

            if ($locked->event === null || ! $locked->event->isCheckInOpen()) {
                throw new CheckInClosedException(
                    $locked->event?->checkInClosedReason() ?? 'Check-in is not open for this event.'
                );
            }

            $alreadyIn = $locked->isCheckedIn();

            if (! $alreadyIn) {
                $locked->forceFill([
                    'checked_in_at' => now(),
                    'checked_in_by' => $staffUserId,
                    'checked_in_via_label' => $viaLabel,
                ])->save();
            }

            // Door staff act on this the moment it lands — a full name-only match still
            // leaves them guessing which table to point someone to, or whether the
            // kitchen needs to know about a dietary restriction. loadMissing so every
            // caller (dashboard scanner, staff-link scanner, manual lookup) gets the
            // same payload without each having to remember the eager-load itself.
            $locked->loadMissing(['rsvp', 'eventTable', 'checkedInBy']);

            return [
                'guest' => [
                    'id' => $locked->id,
                    'name' => $locked->name,
                    'checked_in_at' => $locked->checked_in_at?->toIso8601String(),
                    'email' => $locked->email,
                    'phone' => $locked->phone,
                    'table' => $locked->tableLabel(),
                    'meal_preference' => $locked->rsvp?->meal_preference,
                    'rsvp_note' => $locked->rsvp?->message,
                    // Which door took them the first time — the scanner shows
                    // this on a repeat scan.
                    'checked_in_by' => $locked->checkedInByLabel(),
                ],
                'already_checked_in' => $alreadyIn,
            ];
        });
    }
}
