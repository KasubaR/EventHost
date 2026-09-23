<?php

namespace Tests\Feature;

use App\Enums\EventAudience;
use App\Enums\EventProductKind;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Host-chosen vanity slugs are Pro and above — see User::canChooseCustomEventSlug().
 * Base (and lower) always auto-generate from the event name on create and cannot
 * change the slug on edit.
 */
class CustomEventSlugPlanGateTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function updatePayload(Event $event, array $overrides = []): array
    {
        return array_merge([
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => substr((string) $event->event_time, 0, 5),
            'venue' => $event->venue,
            'location_name' => $event->location_name,
            'latitude' => $event->latitude,
            'longitude' => $event->longitude,
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ], $overrides);
    }

    public function test_base_tier_host_cannot_choose_a_custom_slug_on_create(): void
    {
        $user = User::factory()->create(); // default Base

        $this->actingAs($user)
            ->post(route('events.store'), $this->invitationPayload(['slug' => 'my-garden-party']))
            ->assertSessionHasErrors('slug');

        $this->assertSame(0, Event::query()->where('user_id', $user->id)->count());
    }

    public function test_base_tier_host_gets_an_auto_generated_slug_when_left_blank(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('events.store'), $this->invitationPayload(['name' => 'Sunny Wedding']))
            ->assertRedirect();

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('sunny-wedding', $event->slug);
    }

    public function test_pro_tier_host_can_choose_a_custom_slug_on_create(): void
    {
        $user = User::factory()->pro()->create();

        $this->actingAs($user)
            ->post(route('events.store'), $this->invitationPayload([
                'name' => 'Sunny Wedding',
                'slug' => 'john-and-mary',
            ]))
            ->assertRedirect();

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('john-and-mary', $event->slug);
    }

    public function test_base_tier_host_cannot_change_slug_on_update(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['slug' => 'original-slug']);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'slug' => 'brand-new-slug',
            ]))
            ->assertSessionHasErrors('slug');

        $this->assertSame('original-slug', $event->fresh()->slug);
    }

    public function test_base_tier_host_can_save_when_slug_is_omitted_or_unchanged(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'slug' => 'keep-me',
            'venue' => 'Old Hall',
        ]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'venue' => 'New Hall',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('keep-me', $event->fresh()->slug);
        $this->assertSame('New Hall', $event->fresh()->venue);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event->fresh(), [
                'slug' => 'keep-me',
                'venue' => 'Newer Hall',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('keep-me', $event->fresh()->slug);
        $this->assertSame('Newer Hall', $event->fresh()->venue);
    }

    public function test_pro_tier_host_can_change_slug_on_update(): void
    {
        $user = User::factory()->pro()->create();
        $event = Event::factory()->for($user)->create(['slug' => 'old-slug']);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'slug' => 'new-custom-slug',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('new-custom-slug', $event->fresh()->slug);
    }

    public function test_create_form_hides_slug_input_for_base_and_shows_it_for_pro(): void
    {
        $base = User::factory()->create();
        $pro = User::factory()->pro()->create();

        $this->actingAs($base)
            ->get(route('events.create', ['audience' => 'private']))
            ->assertOk()
            ->assertDontSee('name="slug"', escape: false)
            ->assertSee('Requires the Pro plan', escape: false);

        $this->actingAs($pro)
            ->get(route('events.create', ['audience' => 'private']))
            ->assertOk()
            ->assertSee('name="slug"', escape: false)
            ->assertDontSee('Requires the Pro plan', escape: false);
    }

    public function test_edit_form_hides_slug_input_for_base_and_shows_it_for_pro(): void
    {
        $base = User::factory()->create();
        $pro = User::factory()->pro()->create();
        $baseEvent = Event::factory()->for($base)->create(['slug' => 'base-event']);
        $proEvent = Event::factory()->for($pro)->create(['slug' => 'pro-event']);

        $this->actingAs($base)
            ->get(route('events.edit', $baseEvent))
            ->assertOk()
            ->assertDontSee('name="slug"', escape: false)
            ->assertSee('base-event', escape: false)
            ->assertSee('Requires the Pro plan', escape: false);

        $this->actingAs($pro)
            ->get(route('events.edit', $proEvent))
            ->assertOk()
            ->assertSee('name="slug"', escape: false)
            ->assertDontSee('Requires the Pro plan', escape: false);
    }
}
