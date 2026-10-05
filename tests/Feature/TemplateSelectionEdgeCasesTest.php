<?php

namespace Tests\Feature;

use App\Enums\PublicRegistrationStatus;
use App\Enums\SubscriptionTier;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationTemplate;
use App\Models\User;
use Database\Seeders\InvitationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Template selection edge cases: an event with no layout, a retired layout, switching after
 * invitations went out, a layout made for another event type, and a plan that no longer covers
 * the chosen layout.
 */
class TemplateSelectionEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $slug): InvitationTemplate
    {
        return InvitationTemplate::query()->where('slug', $slug)->firstOrFail();
    }

    // --- No template ---

    public function test_publishing_without_a_template_is_refused_and_sends_the_host_to_the_picker(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $this->actingAs($user)
            ->patch(route('events.publish', $event))
            ->assertRedirect(route('events.choose-template', $event))
            ->assertSessionHasErrors(['invitation_template_id' => 'Choose an invitation layout before publishing.']);

        $this->assertFalse($event->fresh()->is_published);
        $this->assertSame(1, $user->fresh()->event_credits);
    }

    public function test_save_and_publish_without_a_template_returns_a_publish_error(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => null,
            'event_type' => 'wedding',
            'host_contact_phone' => '+260971234567',
        ]);

        $this->actingAs($user)
            ->patchJson(route('events.update', $event), [
                'name' => $event->name,
                'event_type' => 'wedding',
                'event_date' => $event->event_date->format('Y-m-d'),
                'event_time' => '18:00',
                'host_contact_phone' => '+260971234567',
                'publish' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.publish.0', 'Choose an invitation layout before publishing.');

        $this->assertFalse($event->fresh()->is_published);
        $this->assertSame(1, $user->fresh()->event_credits);
    }

    public function test_api_publish_without_a_template_is_refused(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('test')->plainTextToken)
            ->patchJson("/api/v1/host/events/{$event->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('error', 'needs_template');

        $this->assertFalse($event->fresh()->is_published);
    }

    public function test_free_registration_cannot_be_submitted_for_review_without_a_template(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create(['invitation_template_id' => null]);

        $this->actingAs($owner)
            ->post(route('events.public-registration.submit', $event))
            ->assertRedirect(route('events.edit', $event))
            ->assertSessionHasErrors('public_registration');

        $this->assertSame(PublicRegistrationStatus::Draft, $event->fresh()->public_registration_status);
    }

    public function test_a_live_invitation_with_no_template_reads_as_unavailable_to_guests(): void
    {
        $event = Event::factory()->published()->create(['invitation_template_id' => null]);
        $guest = Guest::factory()->for($event)->create();

        $this->get(route('events.public', ['slug' => $event->slug]))
            ->assertOk()
            ->assertSee('Invitation unavailable')
            ->assertDontSee('evt-invitation-page', escape: false);

        $this->get(route('rsvp.token.show', $guest->invitation_token))
            ->assertOk()
            ->assertSee('Invitation unavailable')
            ->assertDontSee('evt-invitation-page', escape: false);

        $this->getJson("/api/v1/events/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('status', 'unavailable');
    }

    public function test_the_host_is_told_a_live_invitation_has_no_layout(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(['invitation_template_id' => null]);

        $this->actingAs($user)->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('This invitation has no layout')
            ->assertSee(route('events.choose-template', $event), escape: false);
    }

    // --- Retired (inactive) template ---

    public function test_a_retired_template_keeps_rendering_for_guests(): void
    {
        $owner = User::factory()->pro()->create();
        $template = $this->template('modern-minimal');
        $event = Event::factory()->for($owner)->published()->create(['invitation_template_id' => $template->id]);
        $template->update(['is_active' => false]);

        $this->get(route('events.public', ['slug' => $event->slug]))
            ->assertOk()
            ->assertSee('evt-layout-modern-minimal', escape: false);

        $this->assertSame($template->id, $event->fresh()->invitation_template_id);
    }

    public function test_the_edit_page_keeps_the_retired_template_and_warns_the_host(): void
    {
        $owner = User::factory()->pro()->create();
        $template = $this->template('modern-minimal');
        $event = Event::factory()->for($owner)->published()->create([
            'invitation_template_id' => $template->id,
            'event_type' => 'wedding',
        ]);
        $template->update(['is_active' => false]);

        $this->actingAs($owner)->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('Modern Minimal is no longer offered', escape: false)
            ->assertSee('Choose a replacement');
    }

    public function test_publishing_onto_a_retired_template_is_refused(): void
    {
        $owner = User::factory()->pro()->withCredits(1)->create();
        $template = $this->template('modern-minimal');
        $event = Event::factory()->for($owner)->create(['invitation_template_id' => $template->id]);
        $template->update(['is_active' => false]);

        $this->actingAs($owner)
            ->patch(route('events.publish', $event))
            ->assertRedirect(route('events.choose-template', $event))
            ->assertSessionHasErrors('invitation_template_id');

        $this->assertFalse($event->fresh()->is_published);
    }

    public function test_the_seeder_retires_a_removed_template_that_events_use_and_deletes_an_unused_one(): void
    {
        $inUse = InvitationTemplate::factory()->create(['slug' => 'retired-in-use', 'is_featured' => true, 'preview_image' => 'templates/x.webp']);
        $unused = InvitationTemplate::factory()->create(['slug' => 'retired-unused']);
        $event = Event::factory()->published()->create(['invitation_template_id' => $inUse->id]);

        $this->seed(InvitationTemplateSeeder::class);

        $inUse->refresh();
        $this->assertFalse($inUse->is_active);
        $this->assertFalse($inUse->is_featured);
        $this->assertSame($inUse->id, $event->fresh()->invitation_template_id);
        $this->assertNull(InvitationTemplate::query()->find($unused->id));
    }

    public function test_the_seeder_reactivates_a_seeded_template(): void
    {
        $this->template('slate-minimal')->update(['is_active' => false]);

        $this->seed(InvitationTemplateSeeder::class);

        $this->assertTrue($this->template('slate-minimal')->is_active);
    }

    // --- Switching after invitations were sent ---

    public function test_switching_a_live_layout_tells_the_host_how_many_guests_have_it(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->create(['invitation_template_id' => $this->template('slate-minimal')->id]);
        Guest::factory()->for($event)->create(['invitation_sent' => true]);
        Guest::factory()->for($event)->create(['invitation_sent' => true]);
        Guest::factory()->for($event)->create();

        $this->actingAs($owner)->get(route('events.choose-template', $event))
            ->assertOk()
            ->assertSeeInOrder(['2 guests', 'already have this invitation']);

        $this->actingAs($owner)
            ->patch(route('events.choose-template.update', $event), [
                'invitation_template_id' => (string) $this->template('base-wedding')->id,
            ])
            ->assertRedirect(route('events.edit', $event))
            ->assertSessionHas('template_switched_guests', [
                'count' => 2,
                'url' => route('events.guests.index', $event),
            ]);

        $this->actingAs($owner)->get(route('events.edit', $event))
            ->assertSee('You switched layouts.');
    }

    public function test_choosing_a_first_layout_or_keeping_the_same_one_does_not_warn(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->create(['invitation_template_id' => null]);
        Guest::factory()->for($event)->create(['invitation_sent' => true]);
        $slate = $this->template('slate-minimal');

        $this->actingAs($owner)
            ->patch(route('events.choose-template.update', $event), ['invitation_template_id' => (string) $slate->id])
            ->assertSessionMissing('template_switched_guests');

        $this->actingAs($owner)
            ->patch(route('events.choose-template.update', $event), ['invitation_template_id' => (string) $slate->id])
            ->assertSessionMissing('template_switched_guests');
    }

    public function test_switching_a_draft_does_not_warn(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['invitation_template_id' => $this->template('slate-minimal')->id]);
        Guest::factory()->for($event)->create(['invitation_sent' => true]);

        $this->actingAs($owner)
            ->patch(route('events.choose-template.update', $event), [
                'invitation_template_id' => (string) $this->template('base-wedding')->id,
            ])
            ->assertSessionMissing('template_switched_guests');
    }

    // --- Event type ---

    public function test_the_picker_opens_on_the_event_types_category(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create(['invitation_template_id' => null, 'event_type' => 'church']);

        $this->actingAs($owner)->get(route('events.choose-template', $event))
            ->assertOk()
            ->assertSee('Showing Church layouts to match your event type')
            ->assertSee('Beauty for Ashes')
            ->assertDontSee('Modern Minimal');

        $this->actingAs($owner)->get(route('events.choose-template', ['event' => $event, 'category' => '']))
            ->assertOk()
            ->assertDontSee('to match your event type')
            ->assertSee('Beauty for Ashes')
            ->assertSee('Modern Minimal');
    }

    public function test_a_type_without_its_own_layouts_sees_every_layout(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['invitation_template_id' => null, 'event_type' => 'funeral']);

        $this->actingAs($owner)->get(route('events.choose-template', $event))
            ->assertOk()
            ->assertDontSee('to match your event type')
            ->assertSee('Modern Minimal')
            ->assertSee('Beauty for Ashes');
    }

    public function test_a_layout_made_for_another_type_can_be_chosen_and_the_host_gets_a_note(): void
    {
        $owner = User::factory()->pro()->create();
        $template = $this->template('modern-minimal');
        $event = Event::factory()->for($owner)->create(['invitation_template_id' => null, 'event_type' => 'funeral']);

        $this->actingAs($owner)
            ->patch(route('events.choose-template.update', $event), ['invitation_template_id' => (string) $template->id])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame($template->id, $event->fresh()->invitation_template_id);

        $this->actingAs($owner)->get(route('events.edit', $event))
            ->assertSee('Modern Minimal was made for Wedding events', escape: false);
    }

    public function test_no_type_note_when_the_layout_matches(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create([
            'invitation_template_id' => $this->template('modern-minimal')->id,
            'event_type' => 'wedding',
        ]);

        $this->actingAs($owner)->get(route('events.edit', $event))
            ->assertOk()
            ->assertDontSee('was made for');
    }

    // --- Plan downgrade ---

    public function test_a_downgraded_host_keeps_the_premium_layout_and_is_told(): void
    {
        $owner = User::factory()->pro()->withCredits(1)->create();
        $template = $this->template('modern-minimal');
        $event = Event::factory()->for($owner)->create([
            'invitation_template_id' => $template->id,
            'event_type' => 'wedding',
        ]);

        $owner->forceFill(['subscription_tier' => SubscriptionTier::Base])->save();

        $this->actingAs($owner)->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('Your plan no longer includes Modern Minimal', escape: false);

        // Grandfathered: publishing the layout they already chose still works.
        $this->actingAs($owner)->patch(route('events.publish', $event))->assertSessionHasNoErrors();
        $this->assertTrue($event->fresh()->is_published);
        $this->assertSame($template->id, $event->fresh()->invitation_template_id);

        $this->get(route('events.public', ['slug' => $event->fresh()->slug]))
            ->assertOk()
            ->assertSee('evt-layout-modern-minimal', escape: false);
    }

    public function test_a_downgraded_host_who_switches_away_cannot_switch_back(): void
    {
        $owner = User::factory()->pro()->create();
        $premium = $this->template('modern-minimal');
        $event = Event::factory()->for($owner)->create(['invitation_template_id' => $premium->id]);
        $owner->forceFill(['subscription_tier' => SubscriptionTier::Base])->save();

        $this->actingAs($owner)
            ->patch(route('events.choose-template.update', $event), [
                'invitation_template_id' => (string) $this->template('base-wedding')->id,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($owner)
            ->patch(route('events.choose-template.update', $event), ['invitation_template_id' => (string) $premium->id])
            ->assertSessionHasErrors('invitation_template_id');

        $this->assertSame($this->template('base-wedding')->id, $event->fresh()->invitation_template_id);
    }
}
