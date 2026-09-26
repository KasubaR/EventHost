<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\CommunicationService;
use Illuminate\Console\Command;

class SendHostEventRemindersCommand extends Command
{
    /** Days before event_date on which the host is emailed. */
    private const LEAD_DAYS = [7, 1];

    protected $signature = 'events:send-host-reminders';

    protected $description = 'Email hosts a reminder 7 days and 1 day before their published events';

    public function handle(CommunicationService $communication): int
    {
        $today = now()->startOfDay();
        $dates = collect(self::LEAD_DAYS)
            ->mapWithKeys(fn (int $days): array => [$today->copy()->addDays($days)->toDateString() => $days]);
        $sent = 0;

        Event::query()
            ->with('user')
            ->where(function ($q) use ($dates): void {
                // whereDate, not whereIn: SQLite stores the date cast with a
                // midnight time part, so a plain string match would miss.
                foreach ($dates->keys() as $date) {
                    $q->orWhereDate('event_date', $date);
                }
            })
            ->where('is_published', true)
            ->whereNull('cancelled_at')
            ->chunkById(50, function ($events) use ($communication, $dates, &$sent): void {
                foreach ($events as $event) {
                    $host = $event->user;
                    if ($host === null) {
                        continue;
                    }

                    $daysUntil = $dates[$event->event_date->format('Y-m-d')] ?? null;
                    if ($daysUntil === null) {
                        continue;
                    }

                    try {
                        if ($communication->notifyHostEventReminder($host, $event, $daysUntil)) {
                            $sent++;
                        }
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        $this->info("Host event reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
