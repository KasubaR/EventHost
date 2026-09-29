<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use App\Support\InvitationPalettes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvitationPaletteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function designPayload(InvitationTemplate $tpl, array $overrides = []): array
    {
        $order = collect($tpl->default_sections)->pluck('type')->values()->all();
        $visibility = [];
        foreach ($order as $type) {
            $visibility[$type] = '1';
        }

        return array_merge([
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
            ],
        ], $overrides);
    }

    private function eventFor(User $user, string $slug): array
    {
        $tpl = InvitationTemplate::query()->where('slug', $slug)->firstOrFail();

        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);

        return [$event, $tpl];
    }

    public function test_valid_palette_persists_its_trio_and_key(): void
    {
        Storage::fake('public');

        $user = User::factory()->proPlus()->create();
        [$event, $tpl] = $this->eventFor($user, 'slate-minimal');

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($tpl, [
                'theme_palette' => 'sage-ivory',
            ]))
            ->assertSessionDoesntHaveErrors();

        $event->refresh();
        $theme = $event->invitation_customization['theme'];
        $expected = InvitationPalettes::get('sage-ivory');

        $this->assertSame('sage-ivory', $theme['palette_key']);
        $this->assertSame($expected['primary'], $theme['primary']);
        $this->assertSame($expected['accent'], $theme['accent']);
        $this->assertSame($expected['background'], $theme['background']);
    }

    public function test_unknown_palette_key_is_rejected(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        [$event, $tpl] = $this->eventFor($user, 'slate-minimal');

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($tpl, [
                'theme_palette' => 'not-a-real-palette',
            ]))
            ->assertSessionHasErrors('theme_palette');
    }

    public function test_free_form_hex_can_no_longer_be_submitted(): void
    {
        Storage::fake('public');

        // Pro+ so the missing theme_palette is rejected for being absent, not
        // merely because this tier can't choose one at all.
        $user = User::factory()->proPlus()->create();
        [$event, $tpl] = $this->eventFor($user, 'slate-minimal');

        $payload = $this->designPayload($tpl);
        unset($payload['theme_palette']);

        // The old contract — three raw hex fields — must not slip through.
        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $payload + [
                'theme_primary' => '#fefefe',
                'theme_accent' => '#ffffff',
                'theme_background' => '#ffffff',
            ])
            ->assertSessionHasErrors('theme_palette');

        $event->refresh();
        $this->assertNull($event->invitation_customization);
    }

    public function test_dark_palette_is_rejected_on_a_light_template(): void
    {
        Storage::fake('public');

        $user = User::factory()->proPlus()->create();
        [$event, $tpl] = $this->eventFor($user, 'slate-minimal');

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($tpl, [
                'theme_palette' => 'noir-gold',
            ]))
            ->assertSessionHasErrors('theme_palette');
    }

    public function test_light_palette_is_rejected_on_a_dark_template(): void
    {
        Storage::fake('public');

        $user = User::factory()->proPlus()->create();
        [$event, $tpl] = $this->eventFor($user, 'wedding-invitation-2');

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($tpl, [
                'theme_palette' => 'slate-sky',
            ]))
            ->assertSessionHasErrors('theme_palette');
    }

    public function test_dark_palette_is_accepted_on_a_dark_template(): void
    {
        Storage::fake('public');

        $user = User::factory()->proPlus()->create();
        [$event, $tpl] = $this->eventFor($user, 'wedding-invitation-2');

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($tpl, [
                'theme_palette' => 'midnight-silver',
            ]))
            ->assertSessionDoesntHaveErrors();

        $event->refresh();
        $this->assertSame('midnight-silver', $event->invitation_customization['theme']['palette_key']);
    }

    public function test_design_form_offers_only_palettes_matching_template_mode(): void
    {
        $user = User::factory()->pro()->create();
        [$event] = $this->eventFor($user, 'slate-minimal');

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('theme_palette_sage-ivory', escape: false);
        $response->assertDontSee('theme_palette_noir-gold', escape: false);
    }

    public function test_design_form_for_dark_template_offers_dark_palettes(): void
    {
        $user = User::factory()->pro()->create();
        [$event] = $this->eventFor($user, 'wedding-invitation-2');

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('theme_palette_midnight-silver', escape: false);
        $response->assertDontSee('theme_palette_slate-sky', escape: false);
    }

    public function test_beauty_for_ashes_hides_the_palette_picker_and_saves_without_one(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        [$event, $tpl] = $this->eventFor($user, 'beauty-for-ashes');

        $response = $this->actingAs($user)->get(route('events.edit', $event));
        $response->assertOk();
        $response->assertDontSee('name="theme_palette"', escape: false);

        $payload = $this->designPayload($tpl);
        unset($payload['theme_palette']);

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $payload)
            ->assertSessionDoesntHaveErrors();

        // Its hardcoded design is preserved rather than replaced by a palette.
        $event->refresh();
        $this->assertSame(
            $tpl->default_theme['primary'],
            $event->invitation_customization['theme']['primary']
        );
    }

    public function test_palette_choice_is_rejected_for_a_user_below_pro_plus(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        [$event, $tpl] = $this->eventFor($user, 'slate-minimal');

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($tpl, [
                'theme_palette' => 'sage-ivory',
            ]))
            ->assertSessionHasErrors('theme_palette');

        // Nothing applied — the field is simply ignored, not partially saved.
        $event->refresh();
        $this->assertNull($event->invitation_customization);
    }

    public function test_palette_field_may_be_omitted_below_pro_plus_and_keeps_template_default(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        [$event, $tpl] = $this->eventFor($user, 'slate-minimal');

        $payload = $this->designPayload($tpl);
        unset($payload['theme_palette']);

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $payload)
            ->assertSessionDoesntHaveErrors();

        $event->refresh();
        $this->assertSame(
            $tpl->default_theme['primary'],
            $event->invitation_customization['theme']['primary']
        );
    }

    public function test_design_form_locks_the_palette_picker_below_pro_plus(): void
    {
        $user = User::factory()->create();
        [$event] = $this->eventFor($user, 'slate-minimal');

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        // Still shown (reads as an upsell) plus an upgrade link. The cards are
        // pickable for previewing, under a name the save path never reads.
        $response->assertSee('theme_palette_sage-ivory', escape: false);
        $response->assertSee('evt-palette-grid--locked', escape: false);
        $response->assertSee('Upgrade to Pro+', escape: false);
        $response->assertSee('name="palette_preview"', escape: false);
        $response->assertDontSee('name="theme_palette"', escape: false);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*evt-palette-radio[^>]*disabled/s', $response->getContent());
    }

    public function test_design_form_links_to_a_preview_in_the_selected_palette(): void
    {
        Storage::fake('public');

        $user = User::factory()->proPlus()->create();
        [$event, $tpl] = $this->eventFor($user, 'slate-minimal');
        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($tpl, [
                'theme_palette' => 'sage-ivory',
            ]))
            ->assertSessionDoesntHaveErrors();

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('data-palette-preview-link', escape: false);
        $response->assertSee(e(route('events.preview', ['event' => $event, 'palette' => 'sage-ivory'])), escape: false);
        $response->assertSee('name="theme_palette"', escape: false);
    }

    public function test_event_with_off_catalogue_colours_still_renders_the_form(): void
    {
        $user = User::factory()->create();
        [$event] = $this->eventFor($user, 'slate-minimal');

        $event->forceFill(['invitation_customization' => [
            'schema_version' => 2,
            'theme' => [
                'primary' => '#123456',
                'accent' => '#abcdef',
                'background' => '#fedcba',
                'font_heading_key' => 'inter',
                'font_body_key' => 'inter',
            ],
        ]])->save();

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        // Falls back to the template's own colours rather than showing nothing selected.
        $this->assertSame(InvitationPalettes::TEMPLATE_DEFAULT_KEY, $this->checkedPalette($response->getContent()));
    }

    private function checkedPalette(string $html): ?string
    {
        preg_match_all('/<input[^>]*id="theme_palette_([a-z-]+)"[^>]*>/', $html, $inputs, PREG_SET_ORDER);

        foreach ($inputs as [$tag, $key]) {
            if (preg_match('/\schecked\b/', $tag)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{string}>
     */
    public static function offCatalogueTemplates(): array
    {
        return [
            'midnight gold' => ['wedding-midnight-gold'],
            'dusty blue' => ['wedding-dusty-blue'],
            'wedding standard' => ['base-wedding'],
        ];
    }

    /**
     * @dataProvider offCatalogueTemplates
     */
    public function test_template_whose_colours_are_not_in_the_catalogue_preselects_its_own_default(string $slug): void
    {
        $user = User::factory()->proPlus()->create();
        [$event, $tpl] = $this->eventFor($user, $slug);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $this->assertSame(InvitationPalettes::TEMPLATE_DEFAULT_KEY, $this->checkedPalette($response->getContent()));
        $response->assertSee($tpl->name, escape: false);
        $response->assertSee('evt-palette-tag', escape: false);
    }

    public function test_template_default_card_is_first_and_catalogue_twin_is_not_repeated(): void
    {
        $user = User::factory()->proPlus()->create();
        [$event] = $this->eventFor($user, 'wedding-invitation');

        $html = $this->actingAs($user)->get(route('events.edit', $event))->assertOk()->getContent();

        preg_match_all('/id="theme_palette_([a-z-]+)"/', $html, $m);
        $this->assertSame(InvitationPalettes::TEMPLATE_DEFAULT_KEY, $m[1][0] ?? null);
        $this->assertNotContains('ivory-gold', $m[1]);
        $this->assertSame(InvitationPalettes::TEMPLATE_DEFAULT_KEY, $this->checkedPalette($html));
    }

    public function test_saving_template_default_stores_the_templates_own_colours(): void
    {
        Storage::fake('public');

        $user = User::factory()->proPlus()->create();
        [$event, $tpl] = $this->eventFor($user, 'wedding-dusty-blue');

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($tpl, [
                'theme_palette' => 'sage-ivory',
            ]))
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event->fresh()), $this->designPayload($tpl, [
                'theme_palette' => InvitationPalettes::TEMPLATE_DEFAULT_KEY,
            ]))
            ->assertSessionDoesntHaveErrors();

        $theme = $event->fresh()->invitation_customization['theme'];
        $this->assertSame(InvitationPalettes::TEMPLATE_DEFAULT_KEY, $theme['palette_key']);
        $this->assertSame(strtolower($tpl->default_theme['primary']), $theme['primary']);
        $this->assertSame(strtolower($tpl->default_theme['accent']), $theme['accent']);
        $this->assertSame(strtolower($tpl->default_theme['background']), $theme['background']);
    }

    public function test_template_default_is_still_rejected_below_pro_plus(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        [$event, $tpl] = $this->eventFor($user, 'wedding-dusty-blue');

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($tpl, [
                'theme_palette' => InvitationPalettes::TEMPLATE_DEFAULT_KEY,
            ]))
            ->assertSessionHasErrors('theme_palette');
    }

    public function test_switching_template_resets_colours_to_the_new_templates_default(): void
    {
        $user = User::factory()->proPlus()->create();
        [$event] = $this->eventFor($user, 'wedding-invitation-2');
        $noir = InvitationPalettes::get('noir-gold');
        $event->forceFill(['invitation_customization' => [
            'schema_version' => 2,
            'theme' => [
                'palette_key' => 'noir-gold',
                'primary' => $noir['primary'],
                'accent' => $noir['accent'],
                'background' => $noir['background'],
                'font_heading_key' => 'bodoni_moda',
                'font_body_key' => 'eb_garamond',
            ],
        ]])->save();

        $light = InvitationTemplate::query()->where('slug', 'wedding-dusty-blue')->firstOrFail();

        $this->actingAs($user)
            ->patch(route('events.choose-template.update', $event), ['invitation_template_id' => $light->id])
            ->assertRedirect(route('events.edit', $event));

        $theme = $event->fresh()->invitation_customization['theme'];
        $this->assertSame(InvitationPalettes::TEMPLATE_DEFAULT_KEY, $theme['palette_key']);
        $this->assertSame(strtolower($light->default_theme['background']), $theme['background']);
        $this->assertSame(strtolower($light->default_theme['primary']), $theme['primary']);
        // Only the colours are reset.
        $this->assertSame('bodoni_moda', $theme['font_heading_key']);

        $html = $this->actingAs($user)->get(route('events.edit', $event))->assertOk()->getContent();
        $this->assertSame(InvitationPalettes::TEMPLATE_DEFAULT_KEY, $this->checkedPalette($html));

        $this->actingAs($user)->get(route('events.preview', $event))
            ->assertOk()
            ->assertDontSee('--evt-background: '.$noir['background'], escape: false);
    }

    public function test_re_choosing_the_same_template_keeps_a_picked_palette(): void
    {
        Storage::fake('public');

        $user = User::factory()->proPlus()->create();
        [$event, $tpl] = $this->eventFor($user, 'slate-minimal');

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($tpl, [
                'theme_palette' => 'sage-ivory',
            ]))
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($user)
            ->patch(route('events.choose-template.update', $event), ['invitation_template_id' => $tpl->id]);

        $this->assertSame('sage-ivory', $event->fresh()->invitation_customization['theme']['palette_key']);
    }
}
