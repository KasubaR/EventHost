<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use App\Support\InvitationPalettes;
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
        // Pinned to a template that uses the unmodified sample event — previewSampleEvent()
        // renames the couple for five layout-specific slugs, so relying on whichever
        // template happens to be first would break whenever the seeder is reordered.
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('Template preview', escape: false);
        $response->assertSee('Celebration', escape: false);
    }

    public function test_preview_modern_minimal_renders_clean_markup(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'modern-minimal')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-layout-modern-minimal', escape: false);
        $response->assertSee('mm-hero', escape: false);
        $response->assertSee('Sofia', escape: false);
        $response->assertSee('The Countdown', escape: false);
    }

    public function test_preview_wedding_invitation_noir_renders_dark_markup(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'wedding-invitation-2')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-layout-wedding-invitation-noir', escape: false);
        $response->assertSee('wi2-hero', escape: false);
        $response->assertSee('Nadia', escape: false);
        $response->assertSee('wi2-timeline', escape: false);
    }

    public function test_preview_wedding_invitation_renders_ivory_gold_markup(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'wedding-invitation')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-layout-wedding-invitation', escape: false);
        $response->assertSee('wi-hero', escape: false);
        $response->assertSee('Amara', escape: false);
        $response->assertSee('Save the', escape: false);
    }

    public function test_preview_event_invite_renders_blush_card_markup(): void
    {
        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'event-invite')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-layout-event-invite', escape: false);
        $response->assertSee('ei-card', escape: false);
        $response->assertSee('Join Us For', escape: false);
        $response->assertSee('Denim and Brown', escape: false);
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
            'theme_palette' => 'slate-sky',
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
                'message' => ['visible' => '1', 'label' => 'Message to host'],
                'meal_preference' => ['visible' => '1', 'label' => 'Meal preference'],
                'transportation_note' => ['visible' => '1', 'label' => 'Transportation notes'],
                'song_request' => ['visible' => '1', 'label' => 'Song request'],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'invitation-design-saved');

        $event->refresh();
        $this->assertIsArray($event->invitation_customization);
        $this->assertSame('slate-sky', $event->invitation_customization['theme']['palette_key']);
        $this->assertSame(
            InvitationPalettes::get('slate-sky')['primary'],
            $event->invitation_customization['theme']['primary']
        );
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
            'theme_palette' => 'slate-sky',
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
