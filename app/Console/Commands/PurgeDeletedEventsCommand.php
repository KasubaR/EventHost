<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\EventPurgeService;
use App\Support\PurgeOutcome;
use Illuminate\Console\Command;

/**
 * Permanently deletes events that have been in "Recently deleted" for longer than
 * events.retention.deleted_days. Plan: plans/event-retention.md.
 *
 * Off unless EVENT_TRASH_RETENTION_DAYS is set. Refuses to run when trash older than
 * the window exists and EVENT_TRASH_RETENTION_STARTS_AT is unset: the first
 * scheduled run would otherwise permanently delete every event anyone has ever
 * deleted, however long ago, and the failure is silent and unrecoverable.
 */
class PurgeDeletedEventsCommand extends Command
{
    protected $signature = 'events:purge-deleted
                            {--dry-run : Report what would be deleted without deleting anything}
                            {--limit= : Stop after purging this many events}
                            {--allow-backlog : Run even though EVENT_TRASH_RETENTION_STARTS_AT is unset and old trash exists}';

    protected $description = 'Permanently delete events that have been deleted for longer than the retention window';

    public function handle(EventPurgeService $purger): int
    {
        $days = Event::retentionDays();
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 1) {
            $this->info('Deleted-event purging is off (EVENT_TRASH_RETENTION_DAYS is 0); nothing to do.');

            return self::SUCCESS;
        }

        if (! $this->launchDateIsSafe($days, $dryRun)) {
            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $tally = [PurgeOutcome::PURGED => 0, PurgeOutcome::WOULD_PURGE => 0, PurgeOutcome::SKIPPED => 0, PurgeOutcome::FAILED => 0];
        $done = 0;

        Event::onlyTrashed()
            ->purgeable()
            ->chunkById(50, function ($events) use ($purger, $dryRun, $limit, &$tally, &$done): bool {
                foreach ($events as $event) {
                    if ($limit !== null && $done >= $limit) {
                        return false;
                    }

                    $outcome = $purger->purge($event->id, $dryRun);
                    $tally[$outcome->status]++;

                    if ($outcome->status === PurgeOutcome::PURGED || $outcome->status === PurgeOutcome::WOULD_PURGE) {
                        $done++;
                    }

                    if ($outcome->status === PurgeOutcome::FAILED) {
                        $this->error("Event #{$event->id} failed: {$outcome->reason}");
                    }
                }

                return true;
            });

        $this->info($dryRun
            ? "Dry run — would purge: {$tally[PurgeOutcome::WOULD_PURGE]}, kept/skipped: {$tally[PurgeOutcome::SKIPPED]}"
            : "Purged: {$tally[PurgeOutcome::PURGED]}, kept/skipped: {$tally[PurgeOutcome::SKIPPED]}, failed: {$tally[PurgeOutcome::FAILED]}");

        return $tally[PurgeOutcome::FAILED] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * False (after saying why) when running would delete a backlog nobody was warned
     * about. Only trips while such a backlog exists, so a fresh install is unaffected.
     */
    private function launchDateIsSafe(int $days, bool $dryRun): bool
    {
        if (Event::retentionStartsAt() !== null) {
            return true;
        }

        $backlog = Event::onlyTrashed()->where('deleted_at', '<=', now()->subDays($days))->count();

        if ($backlog === 0) {
            return true;
        }

        $configured = config('events.retention.starts_at');
        $why = is_string($configured) && trim($configured) !== ''
            ? "EVENT_TRASH_RETENTION_STARTS_AT (\"{$configured}\") is not a valid date"
            : 'EVENT_TRASH_RETENTION_STARTS_AT is not set';

        $this->warn("{$backlog} deleted event(s) are already older than {$days} days and {$why}.");
        $this->warn('Purging now would permanently delete all of them immediately, with no notice to their hosts.');
        $this->warn('Set EVENT_TRASH_RETENTION_STARTS_AT to the release date (YYYY-MM-DD) so they get a full window from then,');
        $this->warn('or pass --allow-backlog if you really do want them gone now.');

        if ($dryRun) {
            $this->line('(Dry run: continuing so you can see what would be deleted.)');

            return true;
        }

        return (bool) $this->option('allow-backlog');
    }
}
