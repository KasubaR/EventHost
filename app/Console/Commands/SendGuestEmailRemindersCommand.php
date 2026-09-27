<?php

namespace App\Console\Commands;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Services\CommunicationService;
use App\Support\EventReminderBuckets;
use Illuminate\Console\Command;

class SendGuestEmailRemindersCommand extends Command
{
    protected $signature = 'events:send-guest-email-reminders';

    protected $description = 'Email Accepted guests a reminder 7 days before, 1 day before, and on event day';

    public function handle(CommunicationService $communication): int
    {
        if (! (bool) config('communications.guest_email_reminders.enabled', false)) {
            $this->info('Guest email reminders are disabled; nothing to send.');

            return self::SUCCESS;
        }

        $sentTotal = 0;

        // Same candidate events as the WhatsApp reminder — one shared scope, so the two channels cannot
        // disagree about what is reminded (a cancelled event is never one of them).
        Event::query()
            ->dueForGuestEventReminder()
            ->chunkById(50, function ($events) use ($communication, &$sentTotal): void {
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
                        ->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Accepted))
                        ->with('rsvp')
                        ->cursor();

                    foreach ($guests as $guest) {
                        try {
                            $outcome = $communication->sendGuestEventReminderEmail($event, $guest, $bucket);
                        } catch (\Throwable $e) {
                            report($e);

                            continue;
                        }

                        if ($outcome === 'sent') {
                            $sentTotal++;
                        }

                        // Next run picks the rest up; the log key makes it safe to resume.
                        if ($outcome === 'rate_limited') {
                            break;
                        }
                    }
                }
            });

        $this->info("Guest email reminders sent: {$sentTotal}");

        return self::SUCCESS;
    }
}
