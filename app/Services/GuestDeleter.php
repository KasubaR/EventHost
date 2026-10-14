<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestRemoval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The one way a host removes guests (web and API, single and bulk; plans/guest-deletion-gaps.md Phase 2).
 *
 * Deletes under the event's row lock, the same lock every RSVP write takes, so a guest cannot answer in the middle of
 * their own removal and a seat count read afterwards is settled. Each guest is deleted through the model (not a
 * query-builder delete), so anything later hung on deletion runs for bulk and single alike. Cached pass files for
 * the guest's token are removed only once the transaction commits.
 *
 * Each deletion also leaves a GuestRemoval tombstone, written in the same transaction.
 *
 * Returns what was lost, so the caller can say so: a guest who had answered, held seats or been sent an invitation
 * is not the same as an unanswered row.
 */
class GuestDeleter
{
    /**
     * @return array{deleted: int, seats_freed: int, answered: int, invitation_sent: int}
     */
    public function delete(Event $event, Guest $guest, ?int $removedBy = null): array
    {
        return $this->deleteMany($event, [$guest->id], $removedBy);
    }

    /**
     * Guests that do not belong to the event are ignored, never deleted.
     *
     * @param  array<int, int|string>  $guestIds
     * @return array{deleted: int, seats_freed: int, answered: int, invitation_sent: int}
     */
    public function deleteMany(Event $event, array $guestIds, ?int $removedBy = null): array
    {
        $result = ['deleted' => 0, 'seats_freed' => 0, 'answered' => 0, 'invitation_sent' => 0];

        if ($guestIds === []) {
            return $result;
        }

        $tokens = [];

        DB::transaction(function () use ($event, $guestIds, $removedBy, &$result, &$tokens): void {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $guests = Guest::query()
                ->with('rsvp')
                ->where('event_id', $event->id)
                ->whereIn('id', $guestIds)
                ->get();

            foreach ($guests as $guest) {
                $result['seats_freed'] += $guest->rsvp?->heldSeats() ?? 0;
                $result['answered'] += $guest->rsvp !== null ? 1 : 0;
                $result['invitation_sent'] += $guest->invitation_sent ? 1 : 0;

                if (filled($guest->invitation_token)) {
                    $tokens[] = (string) $guest->invitation_token;
                }

                GuestRemoval::recordFor($guest, $removedBy);
                $guest->delete();
                $result['deleted']++;
            }
        });

        $this->forgetPassFiles($tokens);

        return $result;
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function forgetPassFiles(array $tokens): void
    {
        $disk = Storage::disk('local');
        $cache = app(GuestPassFileCache::class);

        foreach ($tokens as $token) {
            foreach (GuestPassFileCache::ROOTS as $root) {
                $disk->deleteDirectory($cache->directory($root, $token));
            }
        }
    }
}
