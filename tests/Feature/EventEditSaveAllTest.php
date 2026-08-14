<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventEditSaveAllTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_page_renders_the_single_save_all_bar(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['is_published' => false]);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('id="evt-save-all-bar"', escape: false);
        $response->assertSee('Save all changes', escape: false);
        $response->assertSee('Save &amp; publish', escape: false);
        $response->assertSee('js/event-edit-save.js', escape: false);
    }

    public function test_per_form_buttons_remain_in_the_markup_as_the_no_js_fallback(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['is_published' => false]);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        // The script hides these at runtime; they must still ship so the page
        // stays usable without JavaScript.
        $response->assertSee('evt-per-form-actions', escape: false);
        $response->assertSee('Save changes', escape: false);
        $response->assertSee('Publish event', escape: false);
    }

    public function test_published_event_shows_save_all_without_a_publish_button(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['is_published' => true]);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('Save all changes', escape: false);
        $response->assertDontSee('Save &amp; publish', escape: false);
    }

    public function test_details_endpoint_returns_json_errors_for_the_save_all_script(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        // The script posts with Accept: application/json and relies on a 422 body
        // it can render; a redirect back would leave it unable to report failure.
        $response = $this->actingAs($user)
            ->patchJson(route('events.update', $event), [
                'name' => '',
                'event_type' => $event->event_type,
                'event_date' => $event->event_date->format('Y-m-d'),
                'event_time' => '18:00',
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['name']]);
    }

    public function test_design_endpoint_returns_json_errors_for_the_save_all_script(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('is_active', true)->firstOrFail();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => $tpl->id]);

        $response = $this->actingAs($user)
            ->patchJson(route('events.invitation-design.update', $event), [
                'font_heading_key' => 'not-a-real-font',
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors']);
    }
}
