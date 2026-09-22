<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Shared invite link (Event::open_rsvp_token) — a private event's alternative
 * to adding every guest by hand: the host posts one link (e.g. to a family
 * WhatsApp group) and guests self-RSVP from it. Unlike the public free-
 * registration open-RSVP flow, a guest here always gets a real
 * invitation_token, so they get an entry pass and a personal RSVP link back.
 */
class SharedInviteLinkTest extends TestCase
{
    use RefreshDatabase;

    private function rsvpPayload(RsvpStatus $status, int $attendeeCount = 1): array
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

    public function test_owner_can_enable_regenerate_and_disable_the_link(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->create();

        $this->assertFalse($event->hasSharedInviteLink());

        $this->actingAs($owner)
            ->post(route('events.guests.open-link.enable', $event))
            ->assertRedirect();

        $event->refresh();
        $this->assertTrue($event->hasSharedInviteLink());
        $firstToken = $event->open_rsvp_token;

        $this->actingAs($owner)
            ->post(route('events.guests.open-link.enable', $event))
            ->assertRedirect();

        $event->refresh();
        $this->assertNotSame($firstToken, $event->open_rsvp_token);

        $this->actingAs($owner)
            ->delete(route('events.guests.open-link.disable', $event))
            ->assertRedirect();

        $event->refresh();
        $this->assertFalse($event->hasSharedInviteLink());
    }

    public function test_link_cannot_be_enabled_for_a_public_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->published()->create();

        $this->actingAs($owner)
            ->post(route('events.guests.open-link.enable', $event))
            ->assertNotFound();
    }

    public function test_non_owner_cannot_enable_the_link(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->create();

        $this->actingAs($stranger)
            ->post(route('events.guests.open-link.enable', $event))
            ->assertForbidden();
    }

    public function test_guest_can_self_rsvp_via_the_shared_link_and_receives_a_personal_token(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->create([
            'open_rsvp_token' => 'shared_test_token',
            'rsvp_deadline' => null,
        ]);

        $this->get(route('rsvp.shared.show', ['token' => 'shared_test_token']))
            ->assertOk();

        $payload = array_merge([
            'name' => 'Family Member',
            'email' => 'family@example.test',
            'phone' => '+260971111111',
        ], $this->rsvpPayload(RsvpStatus::Accepted, 1));

        $response = $this->post(route('rsvp.shared.store', ['token' => 'shared_test_token']), $payload);

        $guest = Guest::query()->where('event_id', $event->id)->where('email', 'family@example.test')->first();
        $this->assertNotNull($guest);
        $this->assertNotNull($guest->invitation_token);

        $response->assertRedirect(route('rsvp.token.thanks', ['token' => $guest->invitation_token]));

        $this->assertDatabaseHas('rsvps', [
            'guest_id' => $guest->id,
            'status' => RsvpStatus::Accepted->value,
        ]);
    }

    public function test_shared_invite_requires_email_and_phone(): void
    {
        $event = Event::factory()->published()->create([
            'open_rsvp_token' => 'shared_missing_fields',
            'rsvp_deadline' => null,
        ]);

        $payload = array_merge([
            'name' => 'No Phone',
            'email' => 'nophone@example.test',
            'phone' => null,
        ], $this->rsvpPayload(RsvpStatus::Accepted, 1));

        $this->post(route('rsvp.shared.store', ['token' => 'shared_missing_fields']), $payload)
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseMissing('guests', ['event_id' => $event->id, 'email' => 'nophone@example.test']);
    }

    public function test_shared_invite_respects_the_plan_guest_capacity(): void
    {
        $owner = User::factory()->create(); // Base tier, cap 150
        $event = Event::factory()->for($owner)->published()->create([
            'open_rsvp_token' => 'shared_full_event',
            'rsvp_deadline' => null,
        ]);
        Guest::factory()->count(150)->for($event)->create();

        $this->get(route('rsvp.shared.show', ['token' => 'shared_full_event']))
            ->assertOk()
            ->assertSee('guest list is full');

        $payload = array_merge([
            'name' => 'One Fifty One',
            'email' => 'guest151@example.test',
            'phone' => '+260970000000',
        ], $this->rsvpPayload(RsvpStatus::Accepted, 1));

        $this->post(route('rsvp.shared.store', ['token' => 'shared_full_event']), $payload)
            ->assertForbidden();

        $this->assertSame(150, $event->guests()->count());
    }

    public function test_a_returning_guest_is_not_blocked_by_a_full_capacity(): void
    {
        $event = Event::factory()->published()->create([
            'open_rsvp_token' => 'shared_returning_guest',
            'rsvp_deadline' => null,
        ]);
        Guest::factory()->count(149)->for($event)->create();
        Guest::factory()->for($event)->create([
            'email' => 'returning@example.test',
            'invitation_token' => 'existing_token_123',
        ]);

        $payload = array_merge([
            'name' => 'Returning Guest',
            'email' => 'returning@example.test',
            'phone' => '+260972222222',
        ], $this->rsvpPayload(RsvpStatus::Maybe, 0));

        $this->post(route('rsvp.shared.store', ['token' => 'shared_returning_guest']), $payload)
            ->assertRedirect(route('rsvp.token.thanks', ['token' => 'existing_token_123']));

        $this->assertSame(150, $event->guests()->count());
    }

    public function test_shared_link_is_ignored_for_a_ticketed_event(): void
    {
        // Defence in depth: the toggle route already refuses ticketed/public
        // events, so a stray token (e.g. from a bad migration) must still not
        // open up self-serve RSVP on a product kind with no guest list at all.
        Event::factory()->ticketed()->published()->create([
            'open_rsvp_token' => 'shared_ticketed_stray',
        ]);

        $this->get(route('rsvp.shared.show', ['token' => 'shared_ticketed_stray']))
            ->assertNotFound();
    }
}
