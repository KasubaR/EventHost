<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 item 4 of plans/public-private-portals.md: a free-registration
 * event's guest list reads as "Registrations", not an invite list, since its
 * attendees mostly arrive through open self-registration
 * (RsvpController::storeOpen()) rather than a host-issued personal invite.
 * GuestController itself is unchanged — isInvitation() already gates both
 * audiences — this only covers the copy that branches on
 * Event::isFreeRegistration().
 */
class GuestRegistrationCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_private_events_guest_index_uses_guest_wording(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->privateAudience()->create();

        $this->actingAs($owner)
            ->get(route('events.guests.index', $event))
            ->assertOk()
            ->assertSee('Add guest')
            ->assertSee('Total guests')
            ->assertDontSee('Registrations')
            ->assertDontSee('Add registration');
    }

    public function test_a_free_registration_events_guest_index_uses_registration_wording(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create();

        $this->actingAs($owner)
            ->get(route('events.guests.index', $event))
            ->assertOk()
            ->assertSee('Registrations')
            ->assertSee('Add registration')
            ->assertSee('Total registrations')
            ->assertDontSee('Add guest')
            ->assertDontSee('Total guests');
    }

    public function test_a_self_registered_row_shows_registered_not_a_pending_invite(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create();

        // Mirrors RsvpController::storeOpen()'s shape for a self-registered
        // attendee: no invitation_token, invitation_sent left false.
        Guest::factory()->for($event)->create([
            'name' => 'Self Registered Person',
            'invitation_token' => null,
            'invitation_sent' => false,
        ]);

        $response = $this->actingAs($owner)->get(route('events.guests.index', $event));

        // The filter dropdown's own "Not marked sent" option always renders
        // regardless of any guest row, so assert against the pill markup
        // itself rather than the bare word to avoid that false collision.
        $response->assertOk()
            ->assertSee('Registered')
            ->assertDontSee('evt-pill--pending">Not marked</span>', false);
    }

    public function test_a_manually_added_guest_on_a_free_registration_event_still_shows_invite_status(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create();

        // A host-added entry still gets a real invitation_token
        // (GuestController::store() doesn't branch on audience), so its
        // invite status is still meaningful and should still render.
        Guest::factory()->for($event)->create([
            'name' => 'Manually Added Person',
            'invitation_token' => 'a-real-token',
            'invitation_sent' => false,
        ]);

        $this->actingAs($owner)
            ->get(route('events.guests.index', $event))
            ->assertOk()
            ->assertSee('Not marked');
    }

    public function test_add_and_edit_guest_pages_use_registration_wording_for_free_registration_events(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create();
        $guest = Guest::factory()->for($event)->create();

        $this->actingAs($owner)
            ->get(route('events.guests.create', $event))
            ->assertOk()
            ->assertSee('Add registration')
            ->assertDontSee('Add guest');

        $this->actingAs($owner)
            ->get(route('events.guests.edit', ['event' => $event, 'guest' => $guest]))
            ->assertOk()
            ->assertSee('Edit registration')
            ->assertDontSee('Edit guest');
    }

    public function test_event_show_page_links_to_registrations_for_a_free_registration_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create();

        $this->actingAs($owner)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Registrations')
            ->assertDontSee('Guests & RSVPs');
    }

    public function test_public_events_index_card_links_to_registrations_for_a_free_registration_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create(['name' => 'Open Mic Night']);

        $this->actingAs($owner)
            ->get(route('public-events.index'))
            ->assertOk()
            ->assertSee('Open Mic Night')
            ->assertSee('Registrations')
            ->assertDontSee('Guests & RSVPs');
    }
}
