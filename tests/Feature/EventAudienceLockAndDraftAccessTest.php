<?php

namespace Tests\Feature;

use App\Enums\EventAudience;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Event settings edge cases: an event's audience is fixed once it exists, and an
 * unpublished event is unreachable by anyone but its host.
 */
class EventAudienceLockAndDraftAccessTest extends TestCase
{
    use RefreshDatabase;

    private function webUpdatePayload(Event $event, array $overrides = []): array
    {
        return array_merge([
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => '15:00',
        ], $overrides);
    }

    private function invitedGuest(Event $event): Guest
    {
        return Guest::factory()->for($event)->create([
            'invitation_sent' => true,
            'invitation_sent_at' => now()->subDay(),
        ]);
    }

    public function test_public_event_with_sent_invitations_cannot_be_made_private_on_the_web(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->publicAudience()->create();
        $this->invitedGuest($event);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->webUpdatePayload($event, [
                'is_public' => '0',
                'audience' => EventAudience::Private->value,
            ]))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(EventAudience::Public, $event->fresh()->audience);
        $this->assertTrue($event->fresh()->is_public);
    }

    public function test_public_event_cannot_be_made_private_through_the_api(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->publicAudience()->create();
        $this->invitedGuest($event);

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->patchJson(route('api.v1.host.events.update', $event), [
                'is_public' => false,
                'audience' => EventAudience::Private->value,
            ])
            ->assertOk();

        $this->assertSame(EventAudience::Public, $event->fresh()->audience);
        $this->assertTrue($event->fresh()->is_public);
    }

    public function test_private_event_cannot_be_made_public_through_the_api(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->privateAudience()->create();

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->patchJson(route('api.v1.host.events.update', $event), [
                'is_public' => true,
                'audience' => EventAudience::Public->value,
            ])
            ->assertOk();

        $this->assertSame(EventAudience::Private, $event->fresh()->audience);
        $this->assertFalse($event->fresh()->is_public);
    }

    public function test_the_model_refuses_to_move_an_existing_event_in_either_direction(): void
    {
        $public = Event::factory()->publicAudience()->create();
        $private = Event::factory()->privateAudience()->create();

        $this->assertThrows(fn () => $public->update(['is_public' => false]), \LogicException::class);
        $this->assertThrows(fn () => $private->update(['audience' => EventAudience::Public]), \LogicException::class);

        $this->assertSame(EventAudience::Public, $public->fresh()->audience);
        $this->assertSame(EventAudience::Private, $private->fresh()->audience);
    }

    public function test_guest_cannot_open_an_unpublished_event_by_slug(): void
    {
        $event = Event::factory()->publicAudience()->create();

        $this->get(route('events.public', $event->slug))->assertNotFound();
        $this->get(route('rsvp.open.show', $event->slug))->assertNotFound();
        $this->getJson(route('api.v1.events.show', $event->slug))->assertNotFound();
        $this->getJson(route('api.v1.rsvp.open.show', $event->slug))->assertNotFound();
    }

    public function test_personal_rsvp_link_for_an_unpublished_event_shows_unavailable(): void
    {
        $event = Event::factory()->create(['name' => 'Secret Draft Party']);
        $guest = Guest::factory()->for($event)->create();

        $this->get(route('rsvp.token.show', $guest->invitation_token))
            ->assertOk()
            ->assertSee('Invitation unavailable')
            ->assertDontSee('name="status"', false);

        $this->getJson(route('api.v1.rsvp.token.show', $guest->invitation_token))
            ->assertOk()
            ->assertJsonPath('status', 'unavailable');
    }

    public function test_guest_cannot_rsvp_to_an_unpublished_event_through_a_personal_link(): void
    {
        $event = Event::factory()->create();
        $guest = Guest::factory()->for($event)->create();

        // Web goes back to the invitation's own status page (RsvpUnavailableException); JSON stays a 403.
        $this->post(route('rsvp.token.store', $guest->invitation_token), [
            'status' => 'accepted',
            'attendee_count' => 1,
        ])->assertRedirect(route('rsvp.token.show', $guest->invitation_token));

        $this->postJson(route('api.v1.rsvp.token.store', $guest->invitation_token), [
            'status' => 'accepted',
            'attendee_count' => 1,
        ])->assertForbidden();

        $this->assertNull($guest->fresh()->rsvp);
    }

    public function test_entry_pass_is_withheld_once_an_event_is_unpublished(): void
    {
        $host = User::factory()->pro()->create();
        $event = Event::factory()->for($host)->published()->create();
        $guest = Guest::factory()->for($event)->create();
        Rsvp::factory()->forGuest($guest)->accepted(1)->create();

        $this->get(route('rsvp.token.pass', $guest->invitation_token))->assertOk();

        $event->forceFill(['is_published' => false])->save();

        $this->get(route('rsvp.token.pass', $guest->invitation_token))
            ->assertRedirect(route('rsvp.token.show', $guest->invitation_token));
        $this->get(route('rsvp.token.entry-pass', $guest->invitation_token))->assertNotFound();
    }

    public function test_another_user_cannot_view_or_preview_an_unpublished_event(): void
    {
        $event = Event::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('events.show', $event))->assertForbidden();
        $this->actingAs($stranger)->get(route('events.preview', $event))->assertForbidden();
        $this->actingAs($stranger)->get(route('events.edit', $event))->assertForbidden();
    }

    public function test_whatsapp_invitation_is_not_sent_for_an_unpublished_event(): void
    {
        config(['communications.whatsapp.enabled' => true]);

        $host = User::factory()->pro()->create();
        $event = Event::factory()->for($host)->create();
        $guest = Guest::factory()->for($event)->create(['phone' => '0971234567']);

        $this->actingAs($host)
            ->post(route('events.guests.whatsapp-invite', [$event, $guest]))
            ->assertSessionHas('status', 'guest-whatsapp-unpublished');

        $this->assertDatabaseMissing('notification_logs', ['guest_id' => $guest->id, 'channel' => 'whatsapp']);
    }
}
