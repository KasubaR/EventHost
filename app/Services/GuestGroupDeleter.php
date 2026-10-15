<?php

namespace App\Services;

use App\Enums\RsvpApprovalStatus;
use App\Models\Event;
use App\Models\GuestGroup;
use Illuminate\Support\Facades\DB;

/**
 * The one way a group is deleted or its shared RSVP link turned off (web and API; plans/guest-groups-gaps.md Phase 1).
 *
 * Runs under the event's row lock, the lock every group sign-up takes, so a request that is mid-way through joining cannot hit
 * the foreign key of a group that has just gone. It counts who is affected first, writes one `guest_edits` row per affected
 * guest in chunked multi-row inserts (a group of thousands is a handful of queries, not thousands), then changes the group.
 *
 * Nobody is released from review: a guest who signed up through the link keeps `group_link_joined_at`, and
 * RsvpSubmissionService holds them for the host on that flag alone (it no longer needs the group to still have its link).
 */
class GuestGroupDeleter
{
    private const CHUNK = 500;

    /**
     * @return array{name: string, members: int, joiners: int, pending: int}
     */
    public function delete(Event $event, GuestGroup $group, ?int $userId = null): array
    {
        return $this->run($event, $group, $userId, deleteGroup: true);
    }

    /**
     * @return array{name: string, members: int, joiners: int, pending: int}
     */
    public function turnOffLink(Event $event, GuestGroup $group, ?int $userId = null): array
    {
        return $this->run($event, $group, $userId, deleteGroup: false);
    }

    /**
     * @return array{name: string, members: int, joiners: int, pending: int}
     */
    private function run(Event $event, GuestGroup $group, ?int $userId, bool $deleteGroup): array
    {
        return DB::transaction(function () use ($event, $group, $userId, $deleteGroup): array {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            // Gone already (a second click, another tab): nothing to do, and nothing to report.
            $locked = GuestGroup::query()->whereKey($group->id)->where('event_id', $event->id)->lockForUpdate()->first();
            if ($locked === null) {
                return ['name' => $group->name, 'members' => 0, 'joiners' => 0, 'pending' => 0];
            }

            $counts = [
                'name' => $locked->name,
                'members' => $locked->guests()->count(),
                'joiners' => $locked->guests()->whereNotNull('group_link_joined_at')->count(),
                'pending' => $locked->guests()
                    ->whereHas('rsvp', fn ($q) => $q->where('host_approval_status', RsvpApprovalStatus::Pending))
                    ->count(),
            ];

            $this->writeHistory($event, $locked, $userId, $deleteGroup);

            if ($deleteGroup) {
                $locked->delete();
            } else {
                $locked->disableLink();
            }

            return $counts;
        });
    }

    /**
     * Deleting a group takes the label off every member; turning the link off only matters to the people who signed up through it.
     */
    private function writeHistory(Event $event, GuestGroup $group, ?int $userId, bool $deleteGroup): void
    {
        $query = $group->guests()->select('id', 'group_link_joined_at');
        if (! $deleteGroup) {
            $query->whereNotNull('group_link_joined_at');
        }

        $query->chunkById(self::CHUNK, function ($guests) use ($event, $group, $userId, $deleteGroup): void {
            $now = now();
            $rows = [];

            foreach ($guests as $guest) {
                $changes = [];
                if ($deleteGroup) {
                    $changes['group'] = [$group->name, null];
                }
                if ($guest->group_link_joined_at !== null) {
                    $changes['link_closed'] = true;
                }

                $rows[] = [
                    'event_id' => $event->id,
                    'guest_id' => $guest->id,
                    'user_id' => $userId,
                    'changes' => json_encode($changes),
                    'created_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('guest_edits')->insert($rows);
            }
        });
    }
}
