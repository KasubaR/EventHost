<?php

namespace Tests\Feature;

use App\Enums\EventProductKind;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Making an invitation event public (discoverable / open-RSVP) is Base and
 * above — see User::canMakeEventsPublic(). Invite-only stays free at every
 * tier and is now the default for a new event. Ticketed events are exempt
 * entirely (TicketedEventCreator hardcodes is_public = true for them).
 */
class PublicVisibilityPlanGateTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invitationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Garden Party',
            'event_type' => 'birthday',
            'product_kind' => EventProductKind::Invitation->value,
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '15:00',
        ], $overrides);
    }

    use RefreshDatabase;

    public function test_a_new_event_defaults_to_private_when_is_public_is_not_submitted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('events.store'), $this->invitationPayload())->assertRedirect();

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertFalse($event->is_public);
    }

    public function test_base_tier_host_can_make_an_event_public(): void
    {
        $user = User::factory()->create(); // default factory tier is Base

        $this->actingAs($user)
            ->post(route('events.store'), $this->invitationPayload(['is_public' => '1']))
            ->assertRedirect();

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($event->is_public);
    }

    public function test_none_tier_host_cannot_make_an_event_public(): void
    {
        $user = User::factory()->withoutCredits()->create(); // tier none

        $this->actingAs($user)
            ->post(route('events.store'), $this->invitationPayload(['is_public' => '1']))
            ->assertSessionHasErrors('is_public');

        $this->assertSame(0, Event::query()->where('user_id', $user->id)->count());
    }

    public function test_none_tier_host_can_still_create_a_private_event(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->actingAs($user)
            ->post(route('events.store'), $this->invitationPayload(['is_public' => '0']))
            ->assertRedirect();

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertFalse($event->is_public);
    }

    public function test_none_tier_host_cannot_flip_an_existing_private_event_to_public(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $event = Event::factory()->for($user)->create(['is_public' => false]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), [
                'name' => $event->name,
                'event_type' => $event->event_type,
                'event_date' => $event->event_date->format('Y-m-d'),
                'event_time' => '15:00',
                'is_public' => '1',
            ])
            ->assertSessionHasErrors('is_public');

        $this->assertFalse($event->fresh()->is_public);
    }

    public function test_base_tier_host_can_flip_an_existing_private_event_to_public(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['is_public' => false]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), [
                'name' => $event->name,
                'event_type' => $event->event_type,
                'event_date' => $event->event_date->format('Y-m-d'),
                'event_time' => '15:00',
                'is_public' => '1',
            ])
            ->assertSessionDoesntHaveErrors('is_public');

        $this->assertTrue($event->fresh()->is_public);
    }

    public function test_ticketed_events_are_always_public_regardless_of_tier(): void
    {
        $user = User::factory()->withoutCredits()->create(); // tier none

        $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Summer Festival',
            'event_type' => 'corporate',
            'product_kind' => EventProductKind::Ticketed->value,
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'event_time' => '18:00',
        ])->assertRedirect();

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($event->is_public);
    }

    public function test_create_form_disables_the_checkbox_and_shows_an_upgrade_badge_for_a_none_tier_host(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $response = $this->actingAs($user)->get(route('events.create', ['kind' => 'invitation']));

        $response->assertOk()
            ->assertSee('disabled', false)
            ->assertSee('evt-credit-badge', false)
            ->assertSee('upgrade to unlock it', false);
    }

    public function test_create_form_leaves_the_checkbox_enabled_for_a_base_tier_host(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('events.create', ['kind' => 'invitation']));

        $response->assertOk();
        $response->assertDontSee('upgrade to unlock it', false);
    }
}
