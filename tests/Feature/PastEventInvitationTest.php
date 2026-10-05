<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PastEventInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function pastEvent(): Event
    {
        return Event::factory()->published()->create([
            'user_id' => User::factory(),
            'is_public' => true,
            'event_date' => now()->subMonth()->format('Y-m-d'),
            'rsvp_deadline' => null,
        ]);
    }

    public function test_without_an_explicit_deadline_rsvps_close_when_the_event_starts(): void
    {
        // plans/rsvp-deadline-fixes.md D2: the implicit deadline is the event start, not the end of
        // the event day, so nobody can accept something that is already under way. Venue clock.
        $venue = config('events.timezone');
        $event = Event::factory()->make([
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-19 23:30:00', $venue)->utc());
        $this->assertTrue($event->isRsvpOpen(), 'open the evening before');

        Carbon::setTestNow(Carbon::parse('2026-11-20 14:59:00', $venue)->utc());
        $this->assertTrue($event->isRsvpOpen(), 'open until the start');

        Carbon::setTestNow(Carbon::parse('2026-11-20 15:00:01', $venue)->utc());
        $this->assertFalse($event->isRsvpOpen(), 'closed once the event has started');

        Carbon::setTestNow(Carbon::parse('2026-11-21 01:30:00', $venue)->utc());
        $this->assertFalse($event->isRsvpOpen(), 'still closed after midnight');
    }

    public function test_a_past_event_is_closed(): void
    {
        $past = Event::factory()->make([
            'event_date' => now()->subDays(2)->format('Y-m-d'),
            'rsvp_deadline' => null,
        ]);

        $this->assertFalse($past->isRsvpOpen());
    }

    public function test_the_invitation_page_shows_ended_status_after_the_event(): void
    {
        $event = $this->pastEvent();

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee('Event has ended', escape: false)
            ->assertSee($event->name, escape: false)
            ->assertDontSee('already taken place', escape: false);
    }

    public function test_the_open_rsvp_page_is_closed_after_the_event(): void
    {
        $event = $this->pastEvent();

        $this->get(route('rsvp.open.show', $event->slug))
            ->assertOk()
            ->assertSee('already taken place', escape: false)
            ->assertDontSee('The RSVP window for this event is closed.', escape: false);
    }

    public function test_a_personal_invitation_link_is_closed_after_the_event(): void
    {
        $event = $this->pastEvent();
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->get(route('rsvp.token.show', $guest->invitation_token))
            ->assertOk()
            ->assertSee('already taken place', escape: false);
    }

    public function test_an_upcoming_event_still_accepts_rsvps(): void
    {
        $event = Event::factory()->published()->create([
            'user_id' => User::factory(),
            'is_public' => true,
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'rsvp_deadline' => null,
        ]);

        $this->get(route('rsvp.open.show', $event->slug))
            ->assertOk()
            ->assertDontSee('already taken place', escape: false);
    }
}
