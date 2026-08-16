<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function template(): InvitationTemplate
    {
        return InvitationTemplate::query()->where('is_active', true)->firstOrFail();
    }

    public function test_owner_can_preview_an_unpublished_draft(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'is_published' => false,
            'invitation_template_id' => $this->template()->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.preview', $event));

        $response->assertOk();
        $response->assertSee('Preview — this is exactly how your invitation looks to guests.', escape: false);
        $response->assertSee($event->name, escape: false);
    }

    public function test_owner_can_preview_a_published_private_event(): void
    {
        // /e/{slug} 403s for a private event even once published — this is the
        // one place a host can still see it.
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'is_published' => true,
            'is_public' => false,
            'invitation_template_id' => $this->template()->id,
        ]);

        $publicResponse = $this->actingAs($user)->get(route('events.public', $event->slug));
        $publicResponse->assertForbidden();

        $previewResponse = $this->actingAs($user)->get(route('events.preview', $event));
        $previewResponse->assertOk();
        $previewResponse->assertSee('This page is host-only', escape: false);
    }

    public function test_other_users_cannot_preview_the_event(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create([
            'invitation_template_id' => $this->template()->id,
        ]);

        $response = $this->actingAs($stranger)->get(route('events.preview', $event));

        $response->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $event = Event::factory()->create([
            'invitation_template_id' => $this->template()->id,
        ]);

        $response = $this->get(route('events.preview', $event));

        $response->assertRedirect(route('login'));
    }

    public function test_event_without_a_chosen_layout_redirects_to_choose_template(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $response = $this->actingAs($user)->get(route('events.preview', $event));

        $response->assertRedirect(route('events.choose-template', $event));
    }

    public function test_preview_does_not_count_as_a_public_invitation_view(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $this->template()->id,
        ]);

        $this->actingAs($user)->get(route('events.preview', $event));

        $this->assertSame(0, $event->fresh()->invitation_views_count);
    }

    public function test_show_page_links_to_the_preview_once_a_layout_is_chosen(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $this->template()->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.show', $event));

        $response->assertOk();
        $response->assertSee(route('events.preview', $event), escape: false);
    }

    public function test_show_page_hides_the_preview_link_before_a_layout_is_chosen(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $response = $this->actingAs($user)->get(route('events.show', $event));

        $response->assertOk();
        $response->assertDontSee(route('events.preview', $event), escape: false);
    }

    public function test_preview_opened_directly_links_back_to_edit(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $this->template()->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.preview', $event));

        $response->assertOk();
        $response->assertSee('Back to edit', escape: false);
        $response->assertSee(route('events.edit', $event), escape: false);
    }

    public function test_preview_opened_from_the_show_page_links_back_to_it(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $this->template()->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.preview', ['event' => $event, 'from' => 'show']));

        $response->assertOk();
        $response->assertSee('Back to event', escape: false);
        $response->assertSee(route('events.show', $event), escape: false);
        $response->assertDontSee('Back to edit', escape: false);
    }
}
