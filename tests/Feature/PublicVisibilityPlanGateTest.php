<?php

namespace Tests\Feature;

use App\Enums\EventAudience;
use App\Enums\EventProductKind;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Making an invitation event public — "free registration": open RSVP,
 * discoverable, no payment — is Base and above, see User::canMakeEventsPublic().
 * Invite-only stays free at every tier and is the default for a new event.
 * Ticketed events are exempt entirely (always public, any tier).
 *
 * Since plans/public-private-portals.md Phase 4, this is chosen once on the
 * create wizard's first two steps (audience, then — for Public — ticketed vs
 * free registration) and is immutable afterwards; there is no longer an
 * is_public checkbox on the details form or the edit page.
 */
class PublicVisibilityPlanGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invitationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Garden Party',
            'event_type' => 'birthday',
            'audience' => EventAudience::Private->value,
            'product_kind' => EventProductKind::Invitation->value,
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '15:00',
        ], $overrides);
    }

    public function test_a_new_private_event_is_not_public(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('events.store'), $this->invitationPayload())->assertRedirect();

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertFalse($event->is_public);
        $this->assertSame(EventAudience::Private, $event->audience);
    }

    public function test_base_tier_host_can_create_a_free_registration_public_event(): void
    {
        $user = User::factory()->create(); // default factory tier is Base

        $this->actingAs($user)
            ->post(route('events.store'), $this->invitationPayload(['audience' => EventAudience::Public->value]))
            ->assertRedirect();

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($event->is_public);
        $this->assertSame(EventAudience::Public, $event->audience);
    }

    public function test_none_tier_host_cannot_create_a_free_registration_public_event(): void
    {
        $user = User::factory()->withoutCredits()->create(); // tier none

        $this->actingAs($user)
            ->post(route('events.store'), $this->invitationPayload(['audience' => EventAudience::Public->value]))
            ->assertSessionHasErrors('audience');

        $this->assertSame(0, Event::query()->where('user_id', $user->id)->count());
    }

    public function test_none_tier_host_can_still_create_a_private_event(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->actingAs($user)
            ->post(route('events.store'), $this->invitationPayload())
            ->assertRedirect();

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertFalse($event->is_public);
    }

    public function test_a_private_ticketed_event_is_rejected_at_create_time(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('events.store'), [
                'name' => 'Summer Festival',
                'event_type' => 'corporate',
                'audience' => EventAudience::Private->value,
                'product_kind' => EventProductKind::Ticketed->value,
                'event_date' => now()->addMonth()->format('Y-m-d'),
                'event_time' => '18:00',
            ])
            ->assertSessionHasErrors('audience');

        $this->assertSame(0, Event::query()->where('user_id', $user->id)->count());
    }

    public function test_ticketed_events_are_always_public_regardless_of_tier(): void
    {
        $user = User::factory()->withoutCredits()->create(); // tier none

        $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Summer Festival',
            'event_type' => 'corporate',
            'audience' => EventAudience::Public->value,
            'product_kind' => EventProductKind::Ticketed->value,
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'event_time' => '18:00',
        ])->assertRedirect();

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($event->is_public);
        $this->assertSame(EventAudience::Public, $event->audience);
    }

    public function test_audience_cannot_be_changed_after_creation(): void
    {
        // The update form no longer has an is_public/audience field at all —
        // this asserts a submitted one is silently ignored rather than
        // reaching a special-cased "you can't do that" error, because there
        // is no legitimate way for a client to submit it in the first place.
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->privateAudience()->create();

        $this->actingAs($user)
            ->patch(route('events.update', $event), [
                'name' => $event->name,
                'event_type' => $event->event_type,
                'event_date' => $event->event_date->format('Y-m-d'),
                'event_time' => '15:00',
                'is_public' => '1',
                'audience' => EventAudience::Public->value,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertFalse($event->fresh()->is_public);
        $this->assertSame(EventAudience::Private, $event->fresh()->audience);
    }

    public function test_audience_chooser_shows_ticketed_and_locked_free_registration_for_a_none_tier_host(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $response = $this->actingAs($user)->get(route('events.create', ['audience' => 'public']));

        $response->assertOk()
            ->assertSee('Ticketed event', false)
            ->assertSee('evt-kind-card--locked', false)
            ->assertSee('evt-credit-badge', false)
            ->assertSee('upgrade to unlock it', false);
    }

    public function test_audience_chooser_offers_free_registration_for_a_base_tier_host(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('events.create', ['audience' => 'public']));

        $response->assertOk()
            ->assertSee('Free registration', false)
            ->assertDontSee('evt-kind-card--locked', false)
            ->assertDontSee('upgrade to unlock it', false);
    }
}
