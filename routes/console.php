<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('invitation:prune-orphaned-files')->daily();
Schedule::command('rsvp:send-reminders')->dailyAt('09:00');
Schedule::command('payments:poll-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('credits:audit')->daily();
Schedule::command('tickets:expire-reservations')->everyMinute()->withoutOverlapping();
Schedule::command('tickets:poll-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Shared hosting has no Supervisor to keep `queue:work` running persistently,
// so ride the same cron minute the rest of the scheduler already needs.
// --queue=high,default is required: several notifications (WelcomeNotification,
// PaymentReceiptNotification, TicketOrderConfirmationNotification,
// EmailChangedNotification, ContactMessageNotification) are dispatched onto
// the `high` queue, and a bare `queue:work` only ever drains `default` and
// silently leaves `high` jobs queued forever. --stop-when-empty exits once
// the backlog is drained instead of idling, and --max-time keeps a burst
// from running past the next minute's overlap check even if it does have
// to wait on something slow (e.g. SMTP).
Schedule::command('queue:work --queue=high,default --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
