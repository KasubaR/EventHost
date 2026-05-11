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
        $today = now()->startOfDay();
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

                    $deadlineDay = $event->rsvp_deadline->copy()->startOfDay();
                    $daysUntil = (int) $today->diffInDays($deadlineDay, false);

                    if (! in_array($daysUntil, [7, 3, 1], true)) {
                        continue;
                    }

                    $bucket = match ($daysUntil) {
                        7 => RsvpReminderBuckets::BUCKET_7,
                        3 => RsvpReminderBuckets::BUCKET_3,
                        1 => RsvpReminderBuckets::BUCKET_1,
                    };

                    $guests = $event->guests()
                        ->whereNotNull('email')
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
                        if (in_array($bucket, $sent, true)) {
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

                        $idempotencyKey = sprintf('rsvp-reminder:%d:%d:%s', $event->id, $guest->id, $bucket);
                        try {
                            $communication->sendRsvpReminder($event, $guest, $daysUntil, $idempotencyKey);
                        } catch (\Throwable $e) {
                            report($e);

                            continue;
                        }
                        $sentThisEvent++;

                        $guest->forceFill([
                            'rsvp_reminders_sent' => RsvpReminderBuckets::withBucketAppended($sent, $bucket),
                        ])->saveQuietly();
                    }
                }
            });

        return self::SUCCESS;
    }
}
