<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationTemplateFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_template_library(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('templates.index'));

        $response->assertOk();
        $response->assertSee('Templates', escape: false);
        $response->assertSee('Browse thumbnails', escape: false);
    }

    public function test_authenticated_user_can_preview_template(): void
    {
        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('Template preview', escape: false);
        $response->assertSee('Celebration', escape: false);
    }

    public function test_preview_botanical_graduation_renders_split_hero_markup(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'graduation-template-2-botanical-blush')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-layout-botanical-graduation', escape: false);
        $response->assertSee('evt-bg-nav-strip', escape: false);
        $response->assertSee('photo-frame', escape: false);
    }

    public function test_preview_uses_the_requested_templates_merge_output_not_always_first_template(): void
    {
        $user = User::factory()->pro()->create();
        $botanical = InvitationTemplate::query()->where('slug', 'graduation-template-2-botanical-blush')->firstOrFail();
        $minimal = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $this->actingAs($user)->get(route('templates.preview', $botanical))
            ->assertOk()
            ->assertSee('evt-layout-botanical-graduation', escape: false);

        $this->actingAs($user)->get(route('templates.preview', $minimal))
            ->assertOk()
            ->assertDontSee('evt-layout-botanical-graduation', escape: false);
    }

    public function test_template_library_filters_by_category_and_search(): void
    {
        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $this->actingAs($user)->get(route('templates.index', ['category' => 'wedding']))
            ->assertOk()
            ->assertSee($tpl->name, escape: false);

        $this->actingAs($user)->get(route('templates.index', ['q' => 'classic']))
            ->assertOk()
            ->assertSee($tpl->name, escape: false);
    }

    public function test_owner_can_save_invitation_design(): void
    {
        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);

        $order = collect($tpl->default_sections)->pluck('type')->values()->all();
        $visibility = [];
        foreach ($order as $type) {
            $visibility[$type] = '1';
        }

        $response = $this->actingAs($user)->patch(route('events.invitation-design.update', $event), [
            'theme_primary' => '#101010',
            'theme_accent' => '#0ea5e9',
            'theme_background' => '#fefefe',
            'font_heading_key' => 'inter',
            'font_body_key' => 'inter',
            'animation_subtle' => '0',
            'countdown_enabled' => '1',
            'section_order' => $order,
            'section_visible' => $visibility,
            'clear_video' => '0',
            'clear_audio' => '0',
            'content_story' => '',
            'schedule_items' => [],
            'rsvp_form' => [
                'message'             => ['visible' => '1', 'label' => 'Message to host'],
                'meal_preference'     => ['visible' => '1', 'label' => 'Meal preference'],
                'transportation_note' => ['visible' => '1', 'label' => 'Transportation notes'],
                'song_request'        => ['visible' => '1', 'label' => 'Song request'],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'invitation-design-saved');

        $event->refresh();
        $this->assertIsArray($event->invitation_customization);
        $this->assertSame('#101010', $event->invitation_customization['theme']['primary']);
        $this->assertSame($order, collect($event->invitation_customization['sections'])->pluck('type')->values()->all());
    }

    public function test_intruder_cannot_update_invitation_design(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $tpl = InvitationTemplate::query()->firstOrFail();

        $event = Event::factory()->for($owner)->create([
            'invitation_template_id' => $tpl->id,
        ]);

        $order = collect($tpl->default_sections)->pluck('type')->values()->all();

        $response = $this->actingAs($intruder)->patch(route('events.invitation-design.update', $event), [
            'theme_primary' => '#101010',
            'theme_accent' => '#0ea5e9',
            'theme_background' => '#fefefe',
            'font_heading_key' => 'inter',
            'font_body_key' => 'inter',
            'animation_subtle' => '0',
            'section_order' => $order,
            'section_visible' => array_fill_keys($order, '1'),
            'clear_video' => '0',
            'clear_audio' => '0',
        ]);

        $response->assertForbidden();
    }
}
