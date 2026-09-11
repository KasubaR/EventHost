<?php

namespace Tests\Feature\Api\V1\Rsvp;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OpenStoreTest extends TestCase
{
    use RefreshDatabase;

    private function publishedEvent(array $overrides = []): Event
    {
        $user = User::factory()->create();

        return Event::factory()->for($user)->published()->create(array_merge([
            'is_public' => true,
            'rsvp_deadline' => null,
        ], $overrides));
    }

    private function contactPayload(string $email, RsvpStatus $status = RsvpStatus::Accepted, int $attendeeCount = 1): array
    {
        return [
            'name' => 'Jamie Guest',
            'email' => $email,
            'phone' => null,
            'status' => $status->value,
            'attendee_count' => $attendeeCount,
            'message' => null,
            'meal_preference' => null,
            'transportation_note' => null,
            'song_request' => null,
        ];
    }

    public function test_first_submit_creates_a_guest_row_keyed_on_event_and_email(): void
    {
        Notification::fake();
        $event = $this->publishedEvent();

        $this->postJson(route('api.v1.rsvp.open.store', ['slug' => $event->slug]), $this->contactPayload('jamie@example.test'))
            ->assertOk()
            ->assertJsonPath('rsvp.status', 'accepted')
            ->assertJsonPath('max_attendees', 1);

        $this->assertSame(1, Guest::query()->where('event_id', $event->id)->where('email', 'jamie@example.test')->count());
    }

    public function test_resubmitting_the_same_email_updates_the_same_guest_and_rsvp(): void
    {
        Notification::fake();
        $event = $this->publishedEvent();

        $this->postJson(route('api.v1.rsvp.open.store', ['slug' => $event->slug]), $this->contactPayload('jamie@example.test', RsvpStatus::Accepted, 1))
            ->assertOk();
        $this->postJson(route('api.v1.rsvp.open.store', ['slug' => $event->slug]), $this->contactPayload('jamie@example.test', RsvpStatus::Maybe, 0))
            ->assertOk()
            ->assertJsonPath('rsvp.status', 'maybe');

        $this->assertSame(1, Guest::query()->where('event_id', $event->id)->where('email', 'jamie@example.test')->count());
    }

    public function test_plus_one_is_never_granted_even_when_the_event_allows_it(): void
    {
        Notification::fake();
        $event = $this->publishedEvent(['allow_plus_one' => true]);

        $this->postJson(route('api.v1.rsvp.open.store', ['slug' => $event->slug]), $this->contactPayload('plusone@example.test', RsvpStatus::Accepted, 1))
            ->assertOk()
            ->assertJsonPath('max_attendees', 1);

        $this->postJson(route('api.v1.rsvp.open.store', ['slug' => $event->slug]), $this->contactPayload('plusone2@example.test', RsvpStatus::Accepted, 2))
            ->assertUnprocessable();
    }

    public function test_paused_event_is_forbidden_via_the_resolver_not_silently_404(): void
    {
        // Regression test for design decision #3: the old web StoreOpenRsvpRequest's flat
        // duplicate query would have collapsed this to a plain "not found." The API request
        // goes through PublicInvitationResolver::resolveOpenRsvp(), so a paused event is
        // correctly distinguished and rejected with 403, not 404.
        $event = $this->publishedEvent(['invitation_paused_at' => now()]);

        $this->postJson(route('api.v1.rsvp.open.store', ['slug' => $event->slug]), $this->contactPayload('paused@example.test'))
            ->assertForbidden();
    }

    public function test_cancelled_event_is_forbidden(): void
    {
        $event = $this->publishedEvent(['cancelled_at' => now()]);

        $this->postJson(route('api.v1.rsvp.open.store', ['slug' => $event->slug]), $this->contactPayload('cancelled@example.test'))
            ->assertForbidden();
    }

    public function test_private_event_is_forbidden(): void
    {
        $event = $this->publishedEvent(['is_public' => false]);

        $this->postJson(route('api.v1.rsvp.open.store', ['slug' => $event->slug]), $this->contactPayload('private@example.test'))
            ->assertForbidden();
    }

    public function test_unknown_slug_is_not_found(): void
    {
        $this->postJson(route('api.v1.rsvp.open.store', ['slug' => 'does-not-exist']), $this->contactPayload('nobody@example.test'))
            ->assertNotFound();
    }
}
