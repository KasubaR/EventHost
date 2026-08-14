<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplatePreviewFromWizardTest extends TestCase
{
    use RefreshDatabase;

    private function template(): InvitationTemplate
    {
        return InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();
    }

    public function test_preview_opened_from_step_two_links_back_to_that_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $tpl = $this->template();

        $response = $this->actingAs($user)->get(
            route('templates.preview', ['invitation_template' => $tpl, 'from_event' => $event->id])
        );

        $response->assertOk();
        $response->assertSee(route('events.choose-template', $event), escape: false);
        $response->assertSee('Back to layouts', escape: false);
    }

    public function test_preview_from_step_two_applies_the_layout_to_the_event_in_hand(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $tpl = $this->template();

        $response = $this->actingAs($user)->get(
            route('templates.preview', ['invitation_template' => $tpl, 'from_event' => $event->id])
        );

        // The wizard CTA must patch this event, not start a fresh one.
        $response->assertSee(route('events.choose-template.update', $event), escape: false);
        $response->assertDontSee('Use this template', escape: false);
    }

    public function test_preview_without_event_context_still_points_at_the_library(): void
    {
        $user = User::factory()->create();
        $tpl = $this->template();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee(route('templates.index'), escape: false);
        $response->assertDontSee('Back to layouts', escape: false);
    }

    public function test_another_users_event_id_is_ignored(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $strangerEvent = Event::factory()->for($stranger)->create();
        $tpl = $this->template();

        $response = $this->actingAs($user)->get(
            route('templates.preview', ['invitation_template' => $tpl, 'from_event' => $strangerEvent->id])
        );

        $response->assertOk();
        $response->assertDontSee(route('events.choose-template', $strangerEvent), escape: false);
        $response->assertDontSee('Back to layouts', escape: false);
    }

    public function test_preview_page_does_not_render_the_site_header(): void
    {
        $user = User::factory()->create();
        $tpl = $this->template();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertDontSee('nav-hamburger', escape: false);
        $response->assertDontSee('nav-account-toggle', escape: false);
    }
}
