<?php

namespace App\Console\Commands;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Services\CommunicationService;
use App\Support\EventReminderBuckets;
use Illuminate\Console\Command;

class SendWhatsAppEventRemindersCommand extends Command
{
    protected $signature = 'events:send-whatsapp-reminders';

    protected $description = 'Send WhatsApp reminders to Accepted guests 7 days before, 1 day before, and on event day';

    public function handle(CommunicationService $communication): int
    {
        if (! (bool) config('communications.whatsapp.enabled', false)) {
            $this->info('WhatsApp communications disabled; nothing to send.');

            return self::SUCCESS;
        }

        $sentTotal = 0;

        // Which events can be due at all (published, invitation, not cancelled, inside the 7-day window)
        // is one shared scope, so this and the email reminder cannot disagree about it.
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
                        ->whereNotNull('phone')
                        // A Pending/Rejected guest has no pass and may never get one — a "see you
                        // tomorrow" reminder for an event they can't actually enter would be wrong.
                        // (An extra seat still waiting on the host does not take back a pass the guest already holds.)
                        ->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Accepted)
                            ->where('host_approval_status', '!=', RsvpApprovalStatus::Rejected)
                            ->where(fn ($w) => $w->where('host_approval_status', '!=', RsvpApprovalStatus::Pending)->orWhere('approved_seats', '>', 0)))
                        ->with('rsvp')
                        ->cursor();

                    foreach ($guests as $guest) {
                        /** @var list<string> $sent */
                        $sent = $guest->whatsapp_event_reminders_sent;
                        if (in_array($bucket, $sent, true)) {
                            continue;
                        }

                        try {
                            $outcome = $communication->sendWhatsAppEventReminder($event, $guest, $bucket);
                        } catch (\Throwable $e) {
                            report($e);

                            continue;
                        }

                        if ($outcome === 'sent') {
                            $sentTotal++;
                        }

                        if ($outcome === 'rate_limited') {
                            break;
                        }
                    }
                }
            });

        $this->info("WhatsApp event reminders sent: {$sentTotal}");

        return self::SUCCESS;
    }
}
