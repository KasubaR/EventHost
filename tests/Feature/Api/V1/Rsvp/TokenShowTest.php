<?php

namespace Tests\Feature\Api\V1\Rsvp;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenShowTest extends TestCase
{
    use RefreshDatabase;

    private function guestFor(array $eventOverrides = [], array $guestOverrides = [], ?User $user = null): Guest
    {
        $user ??= User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(array_merge([
            'is_public' => true,
            'rsvp_deadline' => null,
        ], $eventOverrides));

        return Guest::factory()->for($event)->create(array_merge([
            'invitation_token' => 'tok_'.uniqid(),
        ], $guestOverrides));
    }

    public function test_open_event_with_no_prior_rsvp_shows_null_rsvp_and_max_attendees(): void
    {
        $guest = $this->guestFor(['allow_plus_one' => true], ['plus_one_allowed' => true]);

        $response = $this->getJson(route('api.v1.rsvp.token.show', ['token' => $guest->invitation_token]));

        $response->assertOk()
            ->assertJsonPath('rsvp', null)
            ->assertJsonPath('max_attendees', 2)
            ->assertJsonPath('guest.name', $guest->name);
    }

    public function test_existing_rsvp_is_echoed_back(): void
    {
        $guest = $this->guestFor();
        Rsvp::factory()->forGuest($guest)->accepted(1)->create();

        $response = $this->getJson(route('api.v1.rsvp.token.show', ['token' => $guest->invitation_token]));

        $response->assertOk()
            ->assertJsonPath('rsvp.status', 'accepted')
            ->assertJsonPath('rsvp.attendee_count', 1);
    }

    public function test_closed_event_with_prior_accepted_rsvp_still_shows_it_and_entry_pass(): void
    {
        $host = User::factory()->pro()->create();
        $guest = $this->guestFor(['rsvp_deadline' => now()->subDay()], [], $host);
        Rsvp::factory()->forGuest($guest)->accepted(1)->create();

        $response = $this->getJson(route('api.v1.rsvp.token.show', ['token' => $guest->invitation_token]));

        $response->assertOk()
            ->assertJsonPath('rsvp.status', 'accepted')
            ->assertJsonPath('entry_pass.available', true)
            ->assertJson(fn ($json) => $json->where('entry_pass.check_in_qr_url', fn ($url) => is_string($url))->etc());
    }

    public function test_cancelled_event_returns_a_status_body_not_the_rsvp_shape(): void
    {
        $guest = $this->guestFor(['cancelled_at' => now()]);

        $response = $this->getJson(route('api.v1.rsvp.token.show', ['token' => $guest->invitation_token]));

        $response->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertNull($response->json('rsvp'));
    }

    public function test_paused_event_returns_a_status_body(): void
    {
        $guest = $this->guestFor(['invitation_paused_at' => now()]);

        $this->getJson(route('api.v1.rsvp.token.show', ['token' => $guest->invitation_token]))
            ->assertOk()
            ->assertJsonPath('status', 'unavailable');
    }

    public function test_soft_deleted_event_returns_a_gone_status_body(): void
    {
        $guest = $this->guestFor();
        $guest->event->delete();

        $this->getJson(route('api.v1.rsvp.token.show', ['token' => $guest->invitation_token]))
            ->assertOk()
            ->assertJsonPath('status', 'gone');
    }

    public function test_ended_event_still_returns_the_rsvp_shape_not_a_status_body(): void
    {
        // Ended is deliberately excluded from the soft-status branch (same as web) — an
        // accepted guest still needs to see their existing response, not a generic banner.
        $guest = $this->guestFor(['event_date' => now()->subMonth()->format('Y-m-d')]);

        $response = $this->getJson(route('api.v1.rsvp.token.show', ['token' => $guest->invitation_token]));

        $response->assertOk();
        $this->assertNull($response->json('status'));
        $this->assertArrayHasKey('max_attendees', $response->json());
    }

    public function test_entry_pass_is_unavailable_when_owner_lacks_premium_tools_even_if_accepted(): void
    {
        $host = User::factory()->create(); // base tier
        $guest = $this->guestFor([], [], $host);
        Rsvp::factory()->forGuest($guest)->accepted(1)->create();

        $this->getJson(route('api.v1.rsvp.token.show', ['token' => $guest->invitation_token]))
            ->assertOk()
            ->assertJsonPath('entry_pass.available', false)
            ->assertJsonPath('entry_pass.check_in_qr_url', null);
    }

    public function test_invalid_token_is_not_found(): void
    {
        $this->getJson(route('api.v1.rsvp.token.show', ['token' => 'does-not-exist']))
            ->assertNotFound();
    }
}
