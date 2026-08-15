<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\EventStaffLink;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phases 2 and 5 of plans/guest-entry-pass.md: the guest-facing entry pass, and
 * making one QR work at the door no matter which scanner page reads it.
 */
class GuestEntryPassTest extends TestCase
{
    use RefreshDatabase;

    private function attendingGuest(User $owner, Event $event): Guest
    {
        $guest = Guest::factory()->for($event)->create(['invitation_token' => 'entry-pass-token']);
        Rsvp::factory()->for($guest)->create(['status' => RsvpStatus::Accepted]);

        return $guest;
    }

    // ── Phase 2: the guest-facing pass ──────────────────────────────────────

    public function test_rsvp_page_shows_the_pass_for_an_attending_guest_on_a_premium_event(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = $this->attendingGuest($owner, $event);

        $response = $this->get(route('rsvp.token.show', $guest->invitation_token));

        $response->assertOk();
        $response->assertSee(route('rsvp.token.entry-pass', $guest->invitation_token), false);
    }

    public function test_rsvp_page_hides_the_pass_when_the_host_is_not_premium(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = $this->attendingGuest($owner, $event);

        $response = $this->get(route('rsvp.token.show', $guest->invitation_token));

        $response->assertOk();
        $response->assertDontSee(route('rsvp.token.entry-pass', $guest->invitation_token), false);
    }

    public function test_rsvp_page_hides_the_pass_for_a_declined_guest(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['invitation_token' => 'declined-token']);
        Rsvp::factory()->for($guest)->create(['status' => RsvpStatus::Declined]);

        $response = $this->get(route('rsvp.token.show', $guest->invitation_token));

        $response->assertOk();
        $response->assertDontSee(route('rsvp.token.entry-pass', $guest->invitation_token), false);
    }

    public function test_rsvp_page_hides_the_pass_for_a_guest_who_has_not_responded(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['invitation_token' => 'no-response-token']);

        $response = $this->get(route('rsvp.token.show', $guest->invitation_token));

        $response->assertOk();
        $response->assertDontSee(route('rsvp.token.entry-pass', $guest->invitation_token), false);
    }

    public function test_entry_pass_svg_renders_for_an_eligible_guest(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = $this->attendingGuest($owner, $event);

        $response = $this->get(route('rsvp.token.entry-pass', $guest->invitation_token));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');
        $response->assertSee('<svg', false);
    }

    public function test_entry_pass_svg_404s_for_an_unknown_token(): void
    {
        $this->get(route('rsvp.token.entry-pass', 'not-a-real-token'))->assertNotFound();
    }

    public function test_entry_pass_svg_404s_for_a_declined_guest(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['invitation_token' => 'declined-svg-token']);
        Rsvp::factory()->for($guest)->create(['status' => RsvpStatus::Declined]);

        $this->get(route('rsvp.token.entry-pass', $guest->invitation_token))->assertNotFound();
    }

    public function test_entry_pass_svg_404s_when_the_host_is_not_premium(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = $this->attendingGuest($owner, $event);

        $this->get(route('rsvp.token.entry-pass', $guest->invitation_token))->assertNotFound();
    }

    public function test_closed_rsvp_window_still_shows_the_pass_for_an_upcoming_event(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create([
            'event_date' => now()->addWeek(),
            'rsvp_deadline' => now()->subDay(),
        ]);
        $guest = $this->attendingGuest($owner, $event);

        $response = $this->get(route('rsvp.token.show', $guest->invitation_token));

        $response->assertOk();
        $response->assertSee(route('rsvp.token.entry-pass', $guest->invitation_token), false);
    }

    public function test_closed_rsvp_window_hides_the_pass_once_the_event_is_over(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create([
            'event_date' => now()->subWeek(),
        ]);
        $guest = $this->attendingGuest($owner, $event);

        $response = $this->get(route('rsvp.token.show', $guest->invitation_token));

        $response->assertOk();
        $response->assertDontSee(route('rsvp.token.entry-pass', $guest->invitation_token), false);
    }

    // ── Phase 5: one QR works from either scanner page ──────────────────────

    public function test_dashboard_scanner_page_uses_the_same_base_for_its_own_and_the_guest_qr_shape(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();

        $response = $this->actingAs($owner)->get(route('events.checkin.scan', $event));

        $response->assertOk();
        $response->assertSee('data-guest-qr-base="'.url('/events/'.$event->id.'/checkin').'"', false);
        $response->assertSee('data-checkin-base="'.url('/events/'.$event->id.'/checkin').'"', false);
    }

    public function test_staff_link_scanner_page_carries_a_different_guest_qr_base_than_its_own(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $link = EventStaffLink::factory()->for($event)->create();

        $response = $this->get(route('checkin.public.scan', ['staffToken' => $link->token]));

        $response->assertOk();
        // Its own confirm endpoint stays the staff-link one...
        $response->assertSee('data-checkin-base="'.url('/checkin/'.$link->token).'"', false);
        // ...but it now also recognizes the shape a guest's own QR actually carries,
        // which is the dashboard route, not this one. Without this attribute the
        // page silently ignores every real guest badge — see plans/guest-entry-pass.md §6.
        $response->assertSee('data-guest-qr-base="'.url('/events/'.$event->id.'/checkin').'"', false);
    }

    public function test_a_guests_own_qr_url_resolves_correctly_via_the_staff_link_endpoint(): void
    {
        // Confirms the endpoint the fixed JS now correctly routes a decoded guest
        // QR to — checkin.public.confirm-token — accepts exactly the token shape
        // Guest::checkInQrUrl() extracts it from, end to end.
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $link = EventStaffLink::factory()->for($event)->create();
        $guest = Guest::factory()->for($event)->create(['invitation_token' => 'staff-scan-token']);

        $qrUrl = $guest->checkInQrUrl();
        $this->assertNotNull($qrUrl);
        $this->assertStringContainsString('/events/'.$event->id.'/checkin/staff-scan-token', $qrUrl);

        $this->postJson(route('checkin.public.confirm-token', [
            'staffToken' => $link->token,
            'token' => 'staff-scan-token',
        ]))->assertOk();

        $this->assertNotNull($guest->fresh()->checked_in_at);
    }
}
