<?php

namespace App\Console\Commands;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Services\CommunicationService;
use App\Support\EventReminderBuckets;
use Illuminate\Console\Command;

class SendGuestEmailRemindersCommand extends Command
{
    protected $signature = 'events:send-guest-email-reminders
        {--dry-run : List what would be emailed today without sending or logging anything (works while the feature is off)}';

    protected $description = 'Email Accepted guests a reminder 7 days before, 1 day before, and on event day';

    public function handle(CommunicationService $communication): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! (bool) config('communications.guest_email_reminders.enabled', false)) {
            $this->info('Guest email reminders are disabled; nothing to send.');

            return self::SUCCESS;
        }

        $total = 0;

        // Same candidate events as the WhatsApp reminder — one shared scope, so the two channels cannot
        // disagree about what is reminded (a cancelled event is never one of them).
        Event::query()
            ->dueForGuestEventReminder()
            ->chunkById(50, function ($events) use ($communication, $dryRun, &$total): void {
                foreach ($events as $event) {
                    if (! $event->ownerCanSendAutomatedReminders()) {
                        continue;
                    }

                    $bucket = EventReminderBuckets::forEvent($event);

                    if ($bucket === null) {
                        continue;
                    }

                    $guests = $event->guests()
                        ->whereNotNull('email')
                        ->where('email', '!=', '')
                        ->whereNull('email_reminders_stopped_at')
                        // A Pending/Rejected guest has no pass and may never get one — a "see you
                        // tomorrow" reminder for an event they can't actually enter would be wrong.
                        // (An extra seat still waiting on the host does not take back a pass the guest already holds.)
                        ->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Accepted)
                            ->where('host_approval_status', '!=', RsvpApprovalStatus::Rejected)
                            ->where(fn ($w) => $w->where('host_approval_status', '!=', RsvpApprovalStatus::Pending)->orWhere('approved_seats', '>', 0)))
                        ->with('rsvp')
                        ->cursor();

                    $forThisEvent = 0;

                    foreach ($guests as $guest) {
                        try {
                            $outcome = $communication->sendGuestEventReminderEmail($event, $guest, $bucket, $dryRun);
                        } catch (\Throwable $e) {
                            report($e);

                            continue;
                        }

                        if ($outcome === 'sent' || $outcome === 'would_send') {
                            $total++;
                            $forThisEvent++;

                            // Addresses only on request (-v): a dry run is for counts first.
                            if ($dryRun && $this->output->isVerbose()) {
                                $this->line('    '.$guest->email);
                            }
                        }

                        // Next run picks the rest up; the log key makes it safe to resume.
                        if ($outcome === 'rate_limited') {
                            break;
                        }
                    }

                    if ($dryRun && $forThisEvent > 0) {
                        $this->line(sprintf('  #%d %s — %d-day reminder — %d guest(s)', $event->id, $event->name, (int) $bucket, $forThisEvent));
                    }
                }
            });

        $this->info($dryRun
            ? "Dry run: {$total} guest reminder email(s) would be sent today. Nothing was sent or logged."
            : "Guest email reminders sent: {$total}");

        return self::SUCCESS;
    }
}
