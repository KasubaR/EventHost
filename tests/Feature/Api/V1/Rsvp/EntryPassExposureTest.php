<?php

namespace Tests\Feature\Api\V1\Rsvp;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
