<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\EventTable;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\GuestPassCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 of plans/invitation-pass-card.md: the guest's entry pass reads as an
 * invitation card (event name, date, venue, guest, plus one, table, QR) and has a
 * page of its own at /rsvp/{token}/pass.
 */
class GuestPassCardTest extends TestCase
{
    use RefreshDatabase;

    private function attendingGuest(?User $owner = null, array $eventOverrides = [], array $rsvpOverrides = [], string $token = 'pass-card-token'): Guest
    {
        $event = Event::factory()->for($owner ?? User::factory()->pro()->create())->create(array_merge([
            'name' => 'Amy and Joe Wedding',
            'venue' => 'Sunset Gardens',
            'event_date' => now()->addDays(20)->startOfDay(),
        ], $eventOverrides));

        $guest = Guest::factory()->for($event)->create([
            'name' => 'Chanda Mwila',
            'invitation_token' => $token,
        ]);
        Rsvp::factory()->for($guest)->create(array_merge([
            'status' => RsvpStatus::Accepted,
            'attendee_count' => 2,
        ], $rsvpOverrides));

        return $guest;
    }

    public function test_pass_page_shows_the_event_name_and_card_details(): void
    {
        $guest = $this->attendingGuest();

        $this->get(route('rsvp.token.pass', $guest->invitation_token))
            ->assertOk()
            ->assertSee('Amy and Joe Wedding')
            ->assertSee('Sunset Gardens')
            ->assertSee('Chanda Mwila')
            ->assertSee('Guest + 1')
            ->assertSee(route('rsvp.token.entry-pass', $guest->invitation_token), false)
            ->assertSee('noindex', false);
    }

    public function test_pass_page_shows_the_table_only_when_assigned(): void
    {
        $guest = $this->attendingGuest();

        $this->get(route('rsvp.token.pass', $guest->invitation_token))
            ->assertDontSee('Table</dt>', false);

        $table = EventTable::factory()->for($guest->event)->create(['label' => 'Table 7']);
        $guest->forceFill(['event_table_id' => $table->id])->save();

        $this->get(route('rsvp.token.pass', $guest->invitation_token))
            ->assertSee('Table 7');
    }

    public function test_a_solo_guest_has_no_plus_one_row(): void
    {
        $guest = $this->attendingGuest(rsvpOverrides: ['attendee_count' => 1]);

        $card = GuestPassCard::for($guest, $guest->event, $guest->rsvp);
        $this->assertNull($card->partyLabel());

        $this->get(route('rsvp.token.pass', $guest->invitation_token))
            ->assertOk()
            ->assertDontSee('Plus one')
            ->assertDontSee('Guest + ');
    }

    public function test_pass_page_omits_the_venue_row_when_the_event_has_none(): void
    {
        $guest = $this->attendingGuest(eventOverrides: ['venue' => null, 'location_name' => null]);

        $this->get(route('rsvp.token.pass', $guest->invitation_token))
            ->assertOk()
            ->assertDontSee('fa-location-dot', false);
    }

    public function test_ineligible_guests_are_redirected_to_their_rsvp_page(): void
    {
        $declined = $this->attendingGuest(rsvpOverrides: ['status' => RsvpStatus::Declined]);
        $this->get(route('rsvp.token.pass', $declined->invitation_token))
            ->assertRedirect(route('rsvp.token.show', $declined->invitation_token));

        $basic = $this->attendingGuest(User::factory()->create(), token: 'basic-host-token');
        $this->get(route('rsvp.token.pass', $basic->invitation_token))
            ->assertRedirect(route('rsvp.token.show', $basic->invitation_token));
    }

    public function test_unknown_token_is_a_404(): void
    {
        $this->get(route('rsvp.token.pass', 'no-such-token-at-all'))->assertNotFound();
    }

    public function test_checked_in_guest_sees_the_checked_in_state(): void
    {
        $guest = $this->attendingGuest();
        $guest->forceFill(['checked_in_at' => now()])->save();

        $this->get(route('rsvp.token.pass', $guest->invitation_token))
            ->assertOk()
            ->assertSee('Checked in')
            ->assertSee('gpass-state--checked-in', false);
    }

    public function test_the_rsvp_page_panel_renders_the_card_and_links_to_the_full_pass(): void
    {
        $guest = $this->attendingGuest();

        $this->get(route('rsvp.token.show', $guest->invitation_token))
            ->assertOk()
            ->assertSee('gpass-title', false)
            ->assertSee(route('rsvp.token.pass', $guest->invitation_token), false);
    }

    public function test_theme_colours_are_contrast_checked_and_only_emit_hex_literals(): void
    {
        $guest = $this->attendingGuest();

        // A near-white primary would make the white header text unreadable.
        $pale = GuestPassCard::for($guest, $guest->event, theme: [
            'primary' => '#fefefe', 'accent' => 'red;background:url(x)', 'background' => '#101010',
        ]);

        $this->assertSame('#1a2a4a', $pale->theme['primary']);
        $this->assertSame('#1e47bb', $pale->theme['accent']);
        $this->assertSame('#101010', $pale->theme['background']);
    }

    public function test_fingerprint_changes_when_anything_printed_on_the_card_changes(): void
    {
        $guest = $this->attendingGuest();
        $before = GuestPassCard::for($guest, $guest->event)->fingerprint();

        $this->assertSame($before, GuestPassCard::for($guest, $guest->event)->fingerprint());

        $guest->rsvp->forceFill(['attendee_count' => 3])->save();
        $this->assertNotSame($before, GuestPassCard::for($guest->fresh(), $guest->event)->fingerprint());
    }
}
