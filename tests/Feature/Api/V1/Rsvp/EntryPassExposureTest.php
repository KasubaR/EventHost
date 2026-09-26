<?php

namespace Tests\Feature\Api\V1\Rsvp;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cross-cutting truth table for Guest::hasEntryPassFor() (accepted + a personal token +
 * the host's plan includes check-in tools) as it's exposed through both the show and
 * store RSVP endpoints. Web's guestHasEntryPass() gate must not drift between surfaces.
 */
class EntryPassExposureTest extends TestCase
{
    use RefreshDatabase;

    private function payload(RsvpStatus $status, int $attendeeCount = 1): array
    {
        return [
            'status' => $status->value,
            'attendee_count' => $attendeeCount,
            'message' => null,
            'meal_preference' => null,
            'transportation_note' => null,
            'song_request' => null,
        ];
    }

    private function guestFor(User $host, array $eventOverrides = []): Guest
    {
        $event = Event::factory()->for($host)->published()->create(array_merge([
            'is_public' => true,
            'rsvp_deadline' => null,
        ], $eventOverrides));

        return Guest::factory()->for($event)->create(['invitation_token' => 'tok_'.uniqid()]);
    }

    public function test_accepted_premium_not_locked_is_available_on_show_and_store(): void
    {
        Notification::fake();
        $guest = $this->guestFor(User::factory()->proPlus()->create());

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->payload(RsvpStatus::Accepted))
            ->assertOk()
            ->assertJsonPath('entry_pass.available', true);

        $this->getJson(route('api.v1.rsvp.token.show', ['token' => $guest->invitation_token]))
            ->assertOk()
            ->assertJsonPath('entry_pass.available', true);
    }

    // ── Phase 5 of plans/invitation-pass-card.md: additive pass fields ─────────

    public function test_an_available_pass_exposes_urls_and_the_card_fields_without_touching_the_original_contract(): void
    {
        $host = User::factory()->proPlus()->create();
        $guest = $this->guestFor($host, [
            'name' => 'Amy and Joe Wedding',
            'venue' => 'Sunset Gardens',
            'event_date' => now()->addDays(10)->format('Y-m-d'),
            'event_time' => '14:30:00',
        ]);
        Rsvp::factory()->forGuest($guest)->accepted(2)->create();

        $response = $this->getJson(route('api.v1.rsvp.token.show', ['token' => $guest->invitation_token]))->assertOk();

        // Original contract, unchanged.
        $response->assertJsonPath('entry_pass.available', true);
        $response->assertJsonPath('entry_pass.check_in_qr_url', $guest->checkInQrUrl());

        // Additive fields.
        $response->assertJsonPath('entry_pass.pass_url', route('rsvp.token.pass', $guest->invitation_token));
        $response->assertJsonPath('entry_pass.pdf_url', route('rsvp.token.pass-download', $guest->invitation_token));
        $response->assertJsonPath('entry_pass.image_url', route('rsvp.token.pass-image', $guest->invitation_token));
        $response->assertJsonPath('entry_pass.card.event_name', 'Amy and Joe Wedding');
        $response->assertJsonPath('entry_pass.card.venue', 'Sunset Gardens');
        $response->assertJsonPath('entry_pass.card.guest_name', $guest->name);
        $response->assertJsonPath('entry_pass.card.party_size', 2);
        $response->assertJsonPath('entry_pass.card.party_label', 'Guest + 1');
        $response->assertJsonPath('entry_pass.card.table', null);
        $response->assertJsonPath('entry_pass.card.state', 'valid');
        $response->assertJsonPath('entry_pass.card.has_start_time', true);
        $this->assertStringContainsString('T14:30:00', $response->json('entry_pass.card.starts_at'));
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $response->json('entry_pass.card.theme.primary'));
    }

    public function test_a_solo_guest_has_no_party_label_and_a_checked_in_guest_says_so(): void
    {
        $host = User::factory()->proPlus()->create();
        $guest = $this->guestFor($host, ['event_time' => '']);
        $guest->forceFill(['checked_in_at' => now()])->save();
        Rsvp::factory()->forGuest($guest)->accepted(1)->create();

        $this->getJson(route('api.v1.rsvp.token.show', ['token' => $guest->invitation_token]))
            ->assertOk()
            ->assertJsonPath('entry_pass.card.party_label', null)
            ->assertJsonPath('entry_pass.card.has_start_time', false)
            ->assertJsonPath('entry_pass.card.state', 'checked_in');
    }

    public function test_every_additive_field_is_null_when_there_is_no_pass(): void
    {
        Notification::fake();
        $guest = $this->guestFor(User::factory()->proPlus()->create());

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->payload(RsvpStatus::Declined, 0))
            ->assertOk()
            ->assertJsonPath('entry_pass.available', false)
            ->assertJsonPath('entry_pass.check_in_qr_url', null)
            ->assertJsonPath('entry_pass.pass_url', null)
            ->assertJsonPath('entry_pass.pdf_url', null)
            ->assertJsonPath('entry_pass.image_url', null)
            ->assertJsonPath('entry_pass.card', null);
    }

    public function test_the_store_endpoint_returns_the_same_pass_fields_as_show(): void
    {
        Notification::fake();
        $guest = $this->guestFor(User::factory()->proPlus()->create());

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->payload(RsvpStatus::Accepted, 1))
            ->assertOk()
            ->assertJsonPath('entry_pass.available', true)
            ->assertJsonPath('entry_pass.pass_url', route('rsvp.token.pass', $guest->invitation_token))
            ->assertJsonPath('entry_pass.card.state', 'valid');
    }

    public function test_the_urls_it_hands_out_actually_serve_the_pass(): void
    {
        Storage::fake('local');
        $guest = $this->guestFor(User::factory()->proPlus()->create());
        Rsvp::factory()->forGuest($guest)->accepted(1)->create();

        $pass = $this->getJson(route('api.v1.rsvp.token.show', ['token' => $guest->invitation_token]))->json('entry_pass');

        $this->get($pass['pass_url'])->assertOk();
        $this->get($pass['pdf_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($pass['image_url'])->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_accepted_non_premium_owner_is_unavailable(): void
    {
        Notification::fake();
        $guest = $this->guestFor(User::factory()->create()); // base tier

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->payload(RsvpStatus::Accepted))
            ->assertOk()
            ->assertJsonPath('entry_pass.available', false)
            ->assertJsonPath('entry_pass.check_in_qr_url', null);
    }

    public function test_declined_is_unavailable_even_for_a_premium_owner(): void
    {
        Notification::fake();
        $guest = $this->guestFor(User::factory()->proPlus()->create());

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->payload(RsvpStatus::Declined, 0))
            ->assertOk()
            ->assertJsonPath('entry_pass.available', false);
    }

    public function test_accepted_but_event_locked_past_date_is_unavailable_on_show(): void
    {
        $host = User::factory()->proPlus()->create();
        $guest = $this->guestFor($host, ['event_date' => now()->subMonth()->format('Y-m-d')]);
        Rsvp::factory()->forGuest($guest)->accepted(1)->create();

        $this->getJson(route('api.v1.rsvp.token.show', ['token' => $guest->invitation_token]))
            ->assertOk()
            ->assertJsonPath('entry_pass.available', false);
    }
}
