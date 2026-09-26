<?php

namespace App\Services;

use App\Models\Event;
use App\Models\NotificationLog;
use App\Models\Review;
use App\Models\StagedMedia;
use App\Support\PurgeOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Permanently deletes one soft-deleted event once its retention window has run
 * out. Plan: plans/event-retention.md (§3.3). Called by events:purge-deleted.
 *
 * Everything that decides *whether* to delete happens inside one transaction, under
 * a row lock on the event, because the inputs can change between selecting a
 * candidate and acting on it: the host can restore it, and an order can settle.
 * Files are deleted only after that transaction commits — a rollback must never
 * leave a row whose files are already gone.
 */
class EventPurgeService
{
    /** Public-disk directories keyed by event id (see PruneOrphanedInvitationFilesCommand). */
    private const INVITATION_DIRECTORIES = [
        'invitation-gallery',
        'invitation-hero',
        'invitation-couple',
        'invitation-media',
    ];

    public function purge(int $eventId, bool $dryRun = false): PurgeOutcome
    {
        /** @var array{public: list<string>, publicDirectories: list<string>, local: list<string>}|null $files */
        $files = null;

        try {
            $outcome = DB::transaction(function () use ($eventId, $dryRun, &$files): PurgeOutcome {
                $event = Event::onlyTrashed()->whereKey($eventId)->lockForUpdate()->first();

                // Restored, or purged by another run, since it was selected.
                if ($event === null) {
                    return PurgeOutcome::skipped('no longer deleted');
                }

                $due = $event->scheduledPurgeDate();
                if ($due === null || $due->isFuture()) {
                    return PurgeOutcome::skipped('retention window not over');
                }

                if ($event->hasRetainedFinancialRecords()) {
                    return PurgeOutcome::skipped('kept for payment records');
                }

                // Never unannounced, whatever the schedule or a late deploy did: the host
                // must have been emailed about this deletion, and long enough ago to act
                // on it. events:warn-pending-purge is what writes that record.
                if (! $this->hasBeenWarned($event)) {
                    return PurgeOutcome::skipped('not yet warned');
                }

                $counts = [
                    'guests' => $event->guests()->count(),
                    'rsvps' => $event->rsvps()->count(),
                    'photos' => $event->photos()->count(),
                ];

                if ($dryRun) {
                    return PurgeOutcome::wouldPurge($counts);
                }

                $files = $this->collectFiles($event);

                // Published testimonials outlive the event they were written about. The
                // reviews.event_id FK nulls on delete now, but hosts that came up without
                // the constraint would neither null nor cascade, so this stays explicit.
                // The review already snapshots the author name and context for this reason.
                Review::query()->where('event_id', $event->id)->update(['event_id' => null]);

                // notification_logs is nullOnDelete — left alone it would outlive its
                // guests, orphaned, still holding delivery responses, with no event
                // left to hang a later erasure request on.
                NotificationLog::query()->where('event_id', $event->id)->delete();

                // The cascades take guests, RSVPs, groups, tables, photo rows, staff,
                // scanner links, staged-media rows, slug redirects and contributions
                // (none of which took money, by the check above). Ledger rows that
                // must survive — credit_transactions, ticket revenue, payouts — are
                // nullOnDelete and simply lose their event id.
                $event->forceDelete();

                return PurgeOutcome::purged($counts);
            });
        } catch (Throwable $e) {
            report($e);

            return PurgeOutcome::failed($e->getMessage());
        }

        if ($outcome->isPurged() && $files !== null) {
            $this->deleteFiles($eventId, $files);

            Log::info('event.purged', ['event_id' => $eventId] + $outcome->counts);
        }

        return $outcome;
    }

    /**
     * Whether this deletion has been warned about, at least Event::PURGE_MIN_NOTICE_HOURS
     * ago. A pending log counts: the warning is queued the moment it is logged. A failed
     * one does not — the host was not told. Keyed on deleted_at, so a restore followed by
     * a second delete needs a fresh warning.
     */
    private function hasBeenWarned(Event $event): bool
    {
        return NotificationLog::query()
            ->where('idempotency_key', $event->purgeWarningKey())
            ->whereIn('status', [NotificationLog::STATUS_PENDING, NotificationLog::STATUS_SENT])
            ->where('created_at', '<=', now()->subHours(Event::PURGE_MIN_NOTICE_HOURS))
            ->exists();
    }

    /**
     * @return array{public: list<string>, publicDirectories: list<string>, local: list<string>}
     */
    private function collectFiles(Event $event): array
    {
        $public = [];

        if (is_string($event->cover_image) && $event->cover_image !== '') {
            $public[] = $event->cover_image;
        }

        foreach ($event->photos()->get(['path', 'thumbnail_path']) as $photo) {
            $public[] = $photo->path;
            $public[] = $photo->thumbnail_path;
        }

        // Staged rows go with the event via the cascade, so read their paths first.
        foreach (StagedMedia::query()->where('event_id', $event->id)->pluck('path') as $path) {
            $public[] = $path;
        }

        $directories = array_map(
            fn (string $base): string => $base.'/'.$event->id,
            self::INVITATION_DIRECTORIES,
        );

        // Cached pass PDFs/images live on the private disk, one folder per guest token.
        $local = [];
        foreach ($event->guests()->whereNotNull('invitation_token')->pluck('invitation_token') as $token) {
            foreach (GuestPassFileCache::ROOTS as $root) {
                $local[] = app(GuestPassFileCache::class)->directory($root, (string) $token);
            }
        }

        return [
            'public' => array_values(array_unique(array_filter($public, fn ($p): bool => $this->safePath($p)))),
            'publicDirectories' => $directories,
            'local' => $local,
        ];
    }

    /**
     * @param  array{public: list<string>, publicDirectories: list<string>, local: list<string>}  $files
     */
    private function deleteFiles(int $eventId, array $files): void
    {
        // A failure here is logged, not thrown: the row is already gone and nothing
        // can un-delete it. Orphaned invitation-* files are swept by
        // invitation:prune-orphaned-files, and orphaned pass caches by the same
        // command, so a miss self-heals rather than lingering.
        foreach ($files['public'] as $path) {
            $this->attempt($eventId, $path, fn () => Storage::disk('public')->delete($path));
        }

        foreach ($files['publicDirectories'] as $directory) {
            $this->attempt($eventId, $directory, fn () => Storage::disk('public')->deleteDirectory($directory));
        }

        foreach ($files['local'] as $directory) {
            $this->attempt($eventId, $directory, fn () => Storage::disk('local')->deleteDirectory($directory));
        }
    }

    private function attempt(int $eventId, string $path, callable $delete): void
    {
        try {
            $delete();
        } catch (Throwable $e) {
            Log::warning('event.purge_file_failed', ['event_id' => $eventId, 'path' => $path, 'error' => $e->getMessage()]);
        }
    }

    /** Storage-relative paths only — never a URL or anything that climbs out of the disk. */
    private function safePath(mixed $path): bool
    {
        return is_string($path)
            && $path !== ''
            && ! str_contains($path, '..')
            && ! str_contains($path, '://')
            && ! str_starts_with($path, '/');
    }
}
