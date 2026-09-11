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

class TokenStoreTest extends TestCase
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

    private function guestFor(array $eventOverrides = [], array $guestOverrides = [], ?User $user = null): Guest
    {
        $user ??= User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(array_merge([
            'is_public' => true,
            'rsvp_deadline' => null,
            'guest_limit' => null,
        ], $eventOverrides));

        return Guest::factory()->for($event)->create(array_merge([
            'invitation_token' => 'tok_'.uniqid(),
        ], $guestOverrides));
    }

    public function test_accept_within_plus_one_cap_succeeds(): void
    {
        Notification::fake();

        $guest = $this->guestFor(['allow_plus_one' => true], ['plus_one_allowed' => true]);

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->payload(RsvpStatus::Accepted, 2))
            ->assertOk()
            ->assertJsonPath('rsvp.status', 'accepted')
            ->assertJsonPath('rsvp.attendee_count', 2);
    }

    public function test_decline_forces_attendee_count_to_zero(): void
    {
        $guest = $this->guestFor();

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->payload(RsvpStatus::Declined, 0))
            ->assertOk()
            ->assertJsonPath('rsvp.status', 'declined')
            ->assertJsonPath('rsvp.attendee_count', 0);
    }

    public function test_attendee_count_above_the_allowed_max_is_rejected(): void
    {
        // rsvpFieldRules() itself rejects an out-of-range attendee_count before
        // RsvpSubmissionService ever gets a chance to clamp it — a non-plus-one guest's
        // max is 1, so 5 fails validation.
        $guest = $this->guestFor();

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->payload(RsvpStatus::Accepted, 5))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attendee_count');
    }

    public function test_guest_limit_exceeded_returns_422_with_status_error(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'guest_limit' => 1,
            'rsvp_deadline' => null,
        ]);
        $g1 = Guest::factory()->for($event)->create(['invitation_token' => 'tok_cap_a']);
        $g2 = Guest::factory()->for($event)->create(['invitation_token' => 'tok_cap_b']);
        Rsvp::factory()->forGuest($g1)->accepted(1)->create();

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => 'tok_cap_b']), $this->payload(RsvpStatus::Accepted, 1))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_closed_after_deadline_is_forbidden(): void
    {
        $guest = $this->guestFor(['rsvp_deadline' => now()->subDay()]);

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->payload(RsvpStatus::Accepted))
            ->assertForbidden();
    }

    public function test_invalid_token_is_not_found(): void
    {
        $this->postJson(route('api.v1.rsvp.token.store', ['token' => 'nope']), $this->payload(RsvpStatus::Accepted))
            ->assertNotFound();
    }

    public function test_resubmit_updates_the_same_rsvp_row(): void
    {
        Notification::fake();
        $guest = $this->guestFor();

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->payload(RsvpStatus::Accepted, 1))
            ->assertOk();
        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->payload(RsvpStatus::Declined, 0))
            ->assertOk();

        $this->assertSame(1, Rsvp::query()->where('guest_id', $guest->id)->count());
        $this->assertSame(RsvpStatus::Declined, $guest->fresh()->rsvp?->status);
    }

    public function test_response_includes_entry_pass_qr_url_when_accepted_and_owner_premium(): void
    {
        Notification::fake();
        $host = User::factory()->proPlus()->create();
        $guest = $this->guestFor([], [], $host);

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->payload(RsvpStatus::Accepted, 1))
            ->assertOk()
            ->assertJsonPath('entry_pass.available', true)
            ->assertJson(fn ($json) => $json->where('entry_pass.check_in_qr_url', fn ($url) => is_string($url) && $url !== '')->etc());
    }

    public function test_response_omits_entry_pass_qr_url_when_owner_is_not_premium(): void
    {
        Notification::fake();
        $host = User::factory()->create(); // base tier
        $guest = $this->guestFor([], [], $host);

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->payload(RsvpStatus::Accepted, 1))
            ->assertOk()
            ->assertJsonPath('entry_pass.available', false)
            ->assertJsonPath('entry_pass.check_in_qr_url', null);
    }
}
