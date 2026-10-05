<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\CommunicationService;
use App\Support\RsvpReminderBuckets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SendRsvpReminderNotificationsCommand extends Command
{
    protected $signature = 'rsvp:send-reminders';

    protected $description = 'Send RSVP reminder emails to invited guests before the deadline';

    public function handle(): int
    {
        // Days are counted on the venue calendar (config events.timezone), not UTC: the 09:00 Lusaka run
        // and the host's deadline are both venue wall-clock times. See Event::rsvpDeadlineAt().
        $today = now(config('events.timezone', 'Africa/Lusaka'))->startOfDay();
        $hourBucket = now()->format('YmdH');
        $hourlyCap = max(1, (int) config('communications.reminder_hourly_cap_per_event', 500));

        Event::query()
            ->whereNotNull('rsvp_deadline')
            ->where('is_published', true)
            ->chunkById(50, function ($events) use ($today, $hourBucket, $hourlyCap): void {
                foreach ($events as $event) {
                    if (! $event->isRsvpOpen()) {
                        continue;
                    }

                    // Pro+ only — see Event::ownerCanSendAutomatedReminders().
                    // Skipped before querying guests so a Base/Pro event with
                    // a large guest list doesn't do any of that work for
                    // nothing.
                    if (! $event->ownerCanSendAutomatedReminders()) {
                        continue;
                    }

                    $deadlineDay = $event->rsvpDeadlineAt()->startOfDay();
                    $daysUntil = (int) $today->diffInDays($deadlineDay, false);

                    // Catch-up rule (plans/rsvp-deadline-fixes.md D6): a reminder goes out when the deadline
                    // is inside a window (7, 3 or 1 days) that this guest has not used yet, rather than only
                    // on the exact day. A deadline set 5 days out, or a scheduler day that never ran, is
                    // reminded on the next run. More than 7 days away: nothing to do yet.
                    $eligible = RsvpReminderBuckets::eligibleFor($daysUntil);
                    $bucket = RsvpReminderBuckets::windowFor($daysUntil);

                    if ($bucket === null) {
                        continue;
                    }

                    $deadlineStamp = $event->rsvpDeadlineKeyStamp();

                    $guests = $event->guests()
                        ->whereNotNull('email')
                        ->whereNull('email_reminders_stopped_at')
                        ->whereDoesntHave('rsvp')
                        ->cursor();

                    $sentThisEvent = 0;
                    $communication = app(CommunicationService::class);

                    foreach ($guests as $guest) {
                        if ($sentThisEvent >= $hourlyCap) {
                            break;
                        }

                        /** @var list<string> $sent */
                        $sent = $guest->rsvp_reminders_sent;
                        // Every window the deadline has reached is already used: nothing new to say.
                        if (array_diff($eligible, $sent) === []) {
                            continue;
                        }

                        if ($guest->invitation_token === null && ! $event->is_public) {
                            continue;
                        }

                        $hourKey = sprintf('rsvp-reminder:%d:%s:%s', $event->id, $bucket, $hourBucket);
                        $hourCount = (int) Cache::increment($hourKey);
                        if ($hourCount === 1) {
                            Cache::put($hourKey, 1, now()->addHour());
                        }
                        if ($hourCount > $hourlyCap) {
                            continue;
                        }

                        $idempotencyKey = sprintf('rsvp-reminder:%d:%d:%s:%s', $event->id, $guest->id, $bucket, $deadlineStamp);
                        try {
                            $communication->sendRsvpReminder($event, $guest, $daysUntil, $idempotencyKey);
                        } catch (\Throwable $e) {
                            report($e);

                            continue;
                        }
                        $sentThisEvent++;

                        $guest->forceFill([
                            'rsvp_reminders_sent' => RsvpReminderBuckets::withBucketsAppended($sent, $eligible),
                        ])->saveQuietly();
                    }
                }
            });

        return self::SUCCESS;
    }
}
