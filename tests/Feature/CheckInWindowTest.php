<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The door window itself — Event::isCheckInOpen(). Check-in used to be gated on
 * calendar-day equality against UTC, which refused two ordinary situations at a
 * real door: early entry the evening before, and a session running past midnight
 * onto a new date. Times here are Africa/Lusaka (config('events.timezone')),
 * which is deliberately not the app timezone.
 */
class CheckInWindowTest extends TestCase
{
    use RefreshDatabase;

    private function venueTime(string $expression): Carbon
    {
        return Carbon::parse($expression, config('events.timezone'));
    }

    /**
     * Unsaved: isCheckInOpen() reads nothing but event_date and event_time, and
     * both columns are NOT NULL, so an in-memory model is the only way to cover
     * the missing-date and missing-time branches at all.
     */
    private function eventAt(?string $date, ?string $time): Event
    {
        return new Event(['event_date' => $date, 'event_time' => $time]);
    }

    public function test_the_evening_before_is_open_for_early_entry(): void
    {
        $event = $this->eventAt('2026-09-21', '19:00:00');

        // 23 hours ahead of a 19:00 start — the previous evening, which the old
        // same-day rule refused outright.
        $this->travelTo($this->venueTime('2026-09-20 20:00'));

        $this->assertTrue($event->isCheckInOpen());
    }

    public function test_a_session_running_past_midnight_stays_open_on_the_new_date(): void
    {
        $event = $this->eventAt('2026-09-21', '22:00:00');

        // 03:30 the following calendar day, 5.5 hours into the event.
        $this->travelTo($this->venueTime('2026-09-22 03:30'));

        $this->assertTrue($event->isCheckInOpen());
    }

    public function test_the_window_is_anchored_to_the_venue_clock_not_utc(): void
    {
        $event = $this->eventAt('2026-09-21', '01:00:00');

        // 01:30 in Lusaka is still 23:30 on the 20th in UTC. The old rule
        // compared the event's date against the UTC day and refused this.
        $moment = $this->venueTime('2026-09-21 01:30');
        $this->travelTo($moment);

        $this->assertSame('2026-09-20', $moment->clone()->utc()->toDateString());
        $this->assertTrue($event->isCheckInOpen());
    }

    public function test_the_window_is_shut_more_than_a_day_ahead(): void
    {
        $event = $this->eventAt('2026-09-21', '19:00:00');

        $this->travelTo($this->venueTime('2026-09-20 18:00'));

        $this->assertFalse($event->isCheckInOpen());
        $this->assertStringContainsString('Check-in opens', $event->checkInClosedReason());
    }

    public function test_the_window_is_shut_once_the_tail_has_run_out(): void
    {
        $event = $this->eventAt('2026-09-21', '19:00:00');

        $this->travelTo($this->venueTime('2026-09-22 08:00'));

        $this->assertFalse($event->isCheckInOpen());
        $this->assertStringContainsString('Check-in closed', $event->checkInClosedReason());
    }

    /**
     * Without a start time there is nothing to measure a tail from, so the whole
     * event day counts as the session — otherwise a 12-hour tail from midnight
     * would shut the door at noon on an evening event.
     */
    public function test_an_event_with_no_start_time_stays_open_all_day(): void
    {
        $event = $this->eventAt('2026-09-21', null);

        $this->travelTo($this->venueTime('2026-09-21 21:00'));

        $this->assertTrue($event->isCheckInOpen());
    }

    public function test_an_event_with_no_date_is_never_open(): void
    {
        $event = $this->eventAt(null, '19:00:00');

        $this->assertFalse($event->isCheckInOpen());
        $this->assertStringContainsString('once this event has a date', $event->checkInClosedReason());
    }

    public function test_a_ticket_can_be_scanned_the_evening_before(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create([
            'event_date' => '2026-09-21',
            'event_time' => '19:00:00',
        ]);
        $ticket = Ticket::factory()->for($event)->create();

        $this->travelTo($this->venueTime('2026-09-20 20:00'));

        $this->actingAs($owner)
            ->postJson(route('events.tickets.checkin.confirm-token', [
                'event' => $event,
                'token' => $ticket->public_token,
            ]))
            ->assertOk()
            ->assertJson(['already_checked_in' => false]);

        $this->assertNotNull($ticket->fresh()->checked_in_at);
    }
}
