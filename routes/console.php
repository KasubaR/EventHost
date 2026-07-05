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
