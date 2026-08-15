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
                'meal_preference' => ['visible' => '1', 'label' => 'Meal preference'],
                'transportation_note' => ['visible' => '1', 'label' => 'Transportation notes'],
                'song_request' => ['visible' => '1', 'label' => 'Song request'],
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
        $response->assertSee('theme_palette_slate-sky', escape: false);
        $response->assertDontSee('theme_palette_noir-gold', escape: false);
    }

    public function test_design_form_for_dark_template_offers_dark_palettes(): void
    {
        $user = User::factory()->pro()->create();
        [$event] = $this->eventFor($user, 'wedding-invitation-2');

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('theme_palette_noir-gold', escape: false);
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
        // Still shown (reads as an upsell) but disabled, plus an upgrade link.
        $response->assertSee('theme_palette_slate-sky', escape: false);
        $response->assertSee('evt-palette-grid--locked', escape: false);
        $response->assertSee('Upgrade to Pro+', escape: false);
    }

    public function test_event_with_off_catalogue_colours_still_renders_the_form(): void
    {
        $user = User::factory()->create();
        [$event] = $this->eventFor($user, 'slate-minimal');

        $event->update(['invitation_customization' => [
            'schema_version' => 2,
            'theme' => [
                'primary' => '#123456',
                'accent' => '#abcdef',
                'background' => '#fedcba',
                'font_heading_key' => 'inter',
                'font_body_key' => 'inter',
            ],
        ]]);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        // Falls back to the first palette of the matching mode rather than showing nothing selected.
        $response->assertSee('theme_palette_'.InvitationPalettes::defaultKeyForMode(InvitationPalettes::MODE_LIGHT), escape: false);
    }
}
