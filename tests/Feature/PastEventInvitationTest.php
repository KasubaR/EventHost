<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
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

    public function test_the_event_date_closes_rsvps_without_an_explicit_deadline(): void
    {
        $past = Event::factory()->make([
            'event_date' => now()->subDay()->format('Y-m-d'),
            'rsvp_deadline' => null,
        ]);
        $today = Event::factory()->make([
            'event_date' => now()->format('Y-m-d'),
            'rsvp_deadline' => null,
        ]);

        $this->assertFalse($past->isRsvpOpen());
        $this->assertTrue($today->isRsvpOpen(), 'RSVPs stay open on the day of the event.');
    }

    public function test_the_invitation_page_stays_viewable_after_the_event(): void
    {
        $event = $this->pastEvent();

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee($event->name, escape: false)
            ->assertSee('already taken place', escape: false);
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
