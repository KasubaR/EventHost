<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\NotificationLog;
use App\Services\CommunicationService;
use Illuminate\Console\Command;

/**
 * Emails each host, once per deletion, a digest of their deleted events that will be
 * permanently removed within the next Event::PURGE_WARNING_DAYS days. Plan:
 * plans/event-retention.md §4b.
 *
 * Runs before events:purge-deleted, which will not delete an event that has no warning
 * on record — so this command is what makes purging safe to turn on. It sends nothing
 * while purging is off.
 */
class WarnPendingEventPurgeCommand extends Command
{
    protected $signature = 'events:warn-pending-purge
                            {--dry-run : List who would be emailed about which events, without sending or logging anything}';

    protected $description = 'Warn hosts about deleted events that are about to be permanently removed';

    public function handle(CommunicationService $communication): int
    {
        if (Event::retentionDays() < 1) {
            $this->info('Deleted-event purging is off (EVENT_TRASH_RETENTION_DAYS is 0); nothing to warn about.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $candidates = Event::onlyTrashed()
            ->purgeable(Event::PURGE_WARNING_DAYS)
            ->with('user')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No deleted events are within the warning window.');

            return self::SUCCESS;
        }

        // One query for who has already been warned about this deletion.
        $warned = NotificationLog::query()
            ->whereIn('idempotency_key', $candidates->map(fn (Event $e) => $e->purgeWarningKey())->all())
            ->whereIn('status', [NotificationLog::STATUS_PENDING, NotificationLog::STATUS_SENT])
            ->pluck('idempotency_key')
            ->flip();

        $emails = 0;
        $events = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($candidates->groupBy('user_id') as $hostEvents) {
            $host = $hostEvents->first()->user;

            $due = $hostEvents->filter(
                fn (Event $event): bool => ! $warned->has($event->purgeWarningKey())
                    // Never warn about (or promise the removal of) an event that will be kept.
                    && ! $event->hasRetainedFinancialRecords()
            );

            if ($due->isEmpty()) {
                continue;
            }

            // Nowhere to send it, or a closed account: skipped, and the purge will in turn
            // refuse these events because no warning was recorded — they are simply kept.
            if ($host === null || ! filled($host->email) || $host->status === 'suspended') {
                $skipped += $due->count();

                continue;
            }

            if ($dryRun) {
                $this->line("[dry-run] {$host->email}: ".$due->pluck('name')->implode(', '));
                $emails++;
                $events += $due->count();

                continue;
            }

            try {
                $sent = $communication->sendPurgeWarning($host, $due->values());
                $emails += $sent > 0 ? 1 : 0;
                $events += $sent;
            } catch (\Throwable $e) {
                report($e);
                $failed++;
            }
        }

        $verb = $dryRun ? 'Would warn' : 'Warned';
        $this->info("{$verb} {$emails} host(s) about {$events} event(s); skipped {$skipped} with no one to warn.".($failed > 0 ? " {$failed} host(s) failed." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
