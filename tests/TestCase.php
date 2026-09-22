<?php

namespace Tests;

use Carbon\Carbon;
use Database\Seeders\InvitationTemplateSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Invitation rendering expects at least one active template.
     */
    protected bool $seed = true;

    protected string $seeder = InvitationTemplateSeeder::class;

    /**
     * event_date/event_time for an event that started an hour ago, guaranteed
     * inside Event::isCheckInOpen()'s window regardless of the current wall
     * clock. A bare `'event_date' => now()->toDateString()` looked "today" but
     * left event_time to the factory's random value; startsAt() combines the
     * two in the venue timezone (Africa/Lusaka, UTC+2 — see
     * config('events.timezone')), not the app timezone now() uses, so a date
     * that was "today" in UTC and a time anywhere in that 24h range could land
     * outside the +24h/-12h check-in window and make the test flaky.
     *
     * @return array{event_date: string, event_time: string}
     */
    protected function eventDateTimeInsideCheckInWindow(): array
    {
        $start = Carbon::now(config('events.timezone'))->subHour();

        return [
            'event_date' => $start->toDateString(),
            'event_time' => $start->format('H:i:s'),
        ];
    }
}
