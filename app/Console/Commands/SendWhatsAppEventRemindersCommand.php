<?php

namespace App\Console\Commands;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Services\CommunicationService;
use App\Support\WhatsAppEventReminderBuckets;
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

        $today = now()->startOfDay();
        $sentTotal = 0;

        Event::query()
            ->whereNotNull('event_date')
            ->where('is_published', true)
            ->chunkById(50, function ($events) use ($today, $communication, &$sentTotal): void {
                foreach ($events as $event) {
                    if (! $event->isInvitation()) {
                        continue;
                    }

                    if (! $event->ownerCanSendAutomatedReminders()) {
                        continue;
                    }

                    $eventDay = $event->event_date->copy()->startOfDay();
                    $daysUntil = (int) $today->diffInDays($eventDay, false);

                    if (! in_array($daysUntil, [7, 1, 0], true)) {
                        continue;
                    }

                    $bucket = match ($daysUntil) {
                        7 => WhatsAppEventReminderBuckets::BUCKET_7,
                        1 => WhatsAppEventReminderBuckets::BUCKET_1,
                        0 => WhatsAppEventReminderBuckets::BUCKET_0,
                    };

                    $guests = $event->guests()
                        ->whereNotNull('phone')
                        ->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Accepted))
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
