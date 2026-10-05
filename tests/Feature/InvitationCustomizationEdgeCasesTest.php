<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateInvitationDesignRequest;
use App\Jobs\ProcessInvitationDesignImageJob;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\StagedMedia;
use App\Models\User;
use App\Services\InvitationCustomizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Invitation customization edge cases: an invalid colour, a removed font, hiding every
 * section / RSVP / details, an image the layout cannot use, unreadable JSON, a template
 * switch under an open form, and two tabs saving the same design.
 */
class InvitationCustomizationEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $slug): InvitationTemplate
    {
        return InvitationTemplate::query()->where('slug', $slug)->firstOrFail();
    }

    private function eventFor(User $user, string $slug = 'slate-minimal', array $attributes = []): Event
    {
        return Event::factory()->for($user)->create(array_merge([
            'invitation_template_id' => $this->template($slug)->id,
            'invitation_customization' => null,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function designPayload(Event $event, array $overrides = []): array
    {
        $tpl = InvitationTemplate::findOrFail($event->invitation_template_id);
        $order = collect($tpl->default_sections)->pluck('type')->values()->all();

        return array_merge([
            'font_heading_key' => 'inter',
            'font_body_key' => 'inter',
            'animation_subtle' => '0',
            'countdown_enabled' => '1',
            'section_order' => $order,
            'section_visible' => array_fill_keys($order, '1'),
            'clear_video' => '0',
            'clear_audio' => '0',
            'content_story' => '',
            'schedule_items' => [],
            'rsvp_form' => [
                'message' => ['visible' => '1', 'label' => 'Message to host'],
            ],
        ], $overrides);
    }

    /** Writes the column raw: the model cast would re-encode, and the point is a bad value. */
    private function storeRaw(Event $event, string $column, string $json): void
    {
        DB::table('events')->where('id', $event->id)->update([$column => $json]);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // --- Invalid colour ---

    public function test_a_malformed_stored_colour_renders_the_template_colours(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner, 'slate-minimal', ['is_published' => true]);
        $this->storeRaw($event, 'invitation_customization', json_encode([
            'theme' => ['primary' => 'red;}</style><script>', 'accent' => '#0ea5e9', 'background' => '#ffffff'],
        ]));

        $merged = app(InvitationCustomizationService::class)->merge($event->fresh());
        $default = $this->template('slate-minimal')->default_theme;

        $this->assertSame(strtolower($default['primary']), strtolower($merged['theme']['primary']));
        $this->assertSame(strtolower($default['accent']), strtolower($merged['theme']['accent']));

        $this->get(route('events.public', ['slug' => $event->slug]))
            ->assertOk()
            ->assertDontSee('</style><script>', escape: false);
    }

    public function test_a_host_below_pro_plus_with_legacy_bad_colours_can_still_save(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);
        $this->storeRaw($event, 'invitation_customization', json_encode([
            'theme' => ['primary' => 'not-a-colour', 'accent' => '#0ea5e9', 'background' => '#ffffff'],
        ]));

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($event))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'invitation-design-saved');

        $theme = $event->fresh()->invitation_customization['theme'];
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $theme['primary']);
        $this->assertSame(strtolower($this->template('slate-minimal')->default_theme['primary']), strtolower($theme['primary']));
    }

    // --- Removed font ---

    public function test_a_removed_font_falls_back_to_the_template_font_and_the_form_says_so(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner, 'slate-minimal', ['invitation_customization' => [
            'theme' => ['font_heading_key' => 'inter', 'font_body_key' => 'inter'],
        ]]);
        $this->storeRaw($event, 'invitation_customization', json_encode([
            'theme' => ['font_heading_key' => 'comic_sans_classic', 'font_body_key' => 'inter'],
        ]));

        $merged = app(InvitationCustomizationService::class)->merge($event->fresh());
        $this->assertSame($this->template('slate-minimal')->default_theme['font_heading_key'], $merged['theme']['font_heading_key']);
        $this->assertSame(['heading' => 'comic_sans_classic'], $merged['theme']['font_replacements']);

        $this->actingAs($owner)
            ->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('Comic Sans Classic is no longer available', escape: false);
    }

    public function test_saving_a_font_that_no_longer_exists_is_refused_with_a_clear_message(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($event, [
                'font_heading_key' => 'comic_sans_classic',
            ]))
            ->assertSessionHasErrors(['font_heading_key' => 'That font is no longer offered. Pick another font.']);
    }

    // --- Section visibility ---

    public function test_rsvp_cannot_be_hidden_on_the_web(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);
        $payload = $this->designPayload($event);
        $payload['section_visible']['rsvp'] = '0';

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $payload)
            ->assertSessionHasErrors(['section_visible' => UpdateInvitationDesignRequest::RSVP_REQUIRED_MESSAGE]);
    }

    public function test_rsvp_cannot_be_hidden_through_the_api(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);
        $payload = $this->designPayload($event);
        $payload['section_visible']['rsvp'] = '0';

        $this->withHeaders($this->bearer($owner))
            ->patchJson("/api/v1/host/events/{$event->id}/design", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.section_visible.0', UpdateInvitationDesignRequest::RSVP_REQUIRED_MESSAGE);
    }

    public function test_hiding_every_section_is_refused(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);
        $payload = $this->designPayload($event);
        $payload['section_visible'] = array_fill_keys(array_keys($payload['section_visible']), '0');

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $payload)
            ->assertSessionHasErrors('section_visible');

        $this->assertNull($event->fresh()->invitation_customization);
    }

    public function test_a_stored_hidden_rsvp_still_renders_for_guests(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner, 'slate-minimal', ['is_published' => true]);
        $this->storeRaw($event, 'invitation_customization', json_encode([
            'sections' => [['type' => 'rsvp', 'visible' => false]],
        ]));

        $rsvp = collect(app(InvitationCustomizationService::class)->merge($event->fresh())['sections'])
            ->firstWhere('type', 'rsvp');

        $this->assertTrue($rsvp['visible']);
    }

    public function test_hiding_details_saves_but_warns_on_the_edit_and_event_pages(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);
        $payload = $this->designPayload($event);
        $payload['section_visible']['details'] = '0';

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $payload)
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('The Event details section is hidden', escape: false);

        $this->actingAs($owner)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('The Event details section is hidden', escape: false)
            ->assertSee('Show it again', escape: false);
    }

    public function test_the_edit_form_locks_the_rsvp_row(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);

        $this->actingAs($owner)
            ->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('name="section_visible[rsvp]" value="1"', escape: false)
            ->assertSee('Always shown', escape: false);
    }

    // --- Incompatible images ---

    public function test_a_tiny_image_is_refused(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);

        $this->actingAs($owner)
            ->postJson(route('events.media.stage', $event), [
                'slot' => StagedMedia::SLOT_GALLERY,
                'file' => UploadedFile::fake()->image('icon.png', 120, 600),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_an_image_with_huge_pixel_dimensions_is_refused(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($event, [
                'gallery_images' => [UploadedFile::fake()->image('panorama.jpg', 10001, 400)],
            ]))
            ->assertSessionHasErrors('gallery_images.0');
    }

    // --- Unreadable or malformed customization ---

    public function test_malformed_shapes_do_not_break_any_page(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner, 'slate-minimal', ['is_published' => true]);
        $this->storeRaw($event, 'invitation_customization', json_encode([
            'theme' => ['primary' => ['nested'], 'font_heading_key' => 42, 'font_body_key' => ['x']],
            'sections' => 'hero,rsvp',
            'media' => ['gallery' => 'one.jpg', 'couple_photos' => 7, 'hero_portrait' => ['x']],
            'content' => ['story' => ['not', 'text'], 'schedule' => 'evening'],
            'effects' => 'none',
            'rsvp_form' => 'all',
        ]));

        $this->get(route('events.public', ['slug' => $event->slug]))->assertOk();
        $this->actingAs($owner)->get(route('events.preview', $event))->assertOk();
        $this->actingAs($owner)->get(route('events.edit', $event))->assertOk();
        $this->withHeaders($this->bearer($owner))->getJson("/api/v1/host/events/{$event->id}/design")->assertOk();
        $this->getJson("/api/v1/events/{$event->slug}")->assertOk();
    }

    public function test_a_save_over_malformed_shapes_succeeds(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);
        $this->storeRaw($event, 'invitation_customization', json_encode([
            'media' => ['gallery' => 'one.jpg', 'couple_photos' => 7],
            'effects' => ['video_background' => ['x'], 'countdown_enabled' => true],
        ]));

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($event))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'invitation-design-saved');

        $this->assertSame([], $event->fresh()->invitation_customization['media']['gallery']);
    }

    public function test_undecodable_json_renders_from_the_previous_copy_and_tells_the_host(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner, 'slate-minimal', ['is_published' => true]);
        $this->storeRaw($event, 'invitation_customization', '{"content": {"story": "trunc');
        $this->storeRaw($event, 'invitation_customization_previous', json_encode([
            'content' => ['story' => 'Our earlier story'],
        ]));

        $merged = app(InvitationCustomizationService::class)->merge($event->fresh());
        $this->assertTrue($merged['restored_from_previous']);
        $this->assertSame('Our earlier story', $merged['content']['story']);

        $this->get(route('events.public', ['slug' => $event->slug]))->assertOk();

        $this->actingAs($owner)
            ->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('could not be read', escape: false);
    }

    // --- Template switch while editing ---

    public function test_a_template_switch_invalidates_an_open_form_even_when_sections_match(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->eventFor($owner, 'modern-minimal');
        $openForm = $this->designPayload($event, [
            'customization_token' => $event->customizationToken(),
            'template_fingerprint' => app(InvitationCustomizationService::class)->templateFingerprint($this->template('modern-minimal')),
        ]);

        // Same section set, different layout.
        $event->update(['invitation_template_id' => $this->template('wedding-invitation')->id]);

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $openForm)
            ->assertSessionHasErrors(['customization_token' => UpdateInvitationDesignRequest::STALE_FORM_MESSAGE]);
    }

    public function test_the_fingerprint_alone_catches_a_switch_between_matching_layouts(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->eventFor($owner, 'modern-minimal');
        $fingerprint = app(InvitationCustomizationService::class)->templateFingerprint($this->template('modern-minimal'));

        $event->update(['invitation_template_id' => $this->template('wedding-invitation')->id]);

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($event, [
                'template_fingerprint' => $fingerprint,
            ]))
            ->assertSessionHasErrors(['section_order' => UpdateInvitationDesignRequest::LAYOUT_CHANGED_MESSAGE]);
    }

    public function test_switching_to_a_layout_without_a_hero_slot_keeps_the_photo(): void
    {
        Storage::fake('public');
        $owner = User::factory()->pro()->create();
        $event = $this->eventFor($owner, 'graduation-template-2-botanical-blush');
        $hero = 'invitation-hero/'.$event->id.'/hp_kept.webp';
        Storage::disk('public')->put($hero, 'x');
        $event->forceFill(['invitation_customization' => ['media' => ['hero_portrait' => $hero]]])->save();

        $event->update(['invitation_template_id' => $this->template('slate-minimal')->id]);
        $event->refresh();

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($event, [
                'customization_token' => $event->customizationToken(),
            ]))
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertExists($hero);
        $this->assertSame($hero, $event->fresh()->invitation_customization['media']['hero_portrait']);
    }

    // --- Two tabs ---

    public function test_the_second_tab_to_save_is_refused(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);
        $token = $event->customizationToken();

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($event, [
                'customization_token' => $token,
                'content_story' => 'Tab one',
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($event, [
                'customization_token' => $token,
                'content_story' => 'Tab two',
            ]))
            ->assertSessionHasErrors(['customization_token' => UpdateInvitationDesignRequest::STALE_FORM_MESSAGE]);

        $this->assertSame('Tab one', $event->fresh()->invitation_customization['content']['story']);
    }

    public function test_the_api_refuses_a_stale_revision_token(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);
        $token = $this->withHeaders($this->bearer($owner))
            ->getJson("/api/v1/host/events/{$event->id}/design")
            ->assertOk()
            ->json('customization_token');

        $this->withHeaders($this->bearer($owner))
            ->patchJson("/api/v1/host/events/{$event->id}/design", $this->designPayload($event, ['customization_token' => $token]))
            ->assertOk();

        $this->withHeaders($this->bearer($owner))
            ->patchJson("/api/v1/host/events/{$event->id}/design", $this->designPayload($event, ['customization_token' => $token]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customization_token');
    }

    public function test_the_stale_token_error_wins_over_field_errors(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);
        $stale = $event->customizationToken();
        $event->forceFill(['invitation_customization_revision' => 5])->save();

        $response = $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($event, [
                'customization_token' => $stale,
                'font_heading_key' => 'comic_sans_classic',
                'gallery_remove' => ['invitation-gallery/'.$event->id.'/gone.webp'],
            ]));

        $response->assertSessionHasErrors('customization_token');
        $response->assertSessionDoesntHaveErrors(['font_heading_key', 'gallery_remove']);
    }

    public function test_background_image_optimisation_does_not_conflict_with_an_open_form(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);
        $original = 'invitation-gallery/'.$event->id.'/gal_src_original.jpg';
        Storage::disk('public')->put($original, UploadedFile::fake()->image('a.jpg', 400, 300)->getContent());
        $event->forceFill(['invitation_customization' => ['media' => ['gallery' => [$original]]]])->save();

        $token = $event->fresh()->customizationToken();

        (new ProcessInvitationDesignImageJob($event->id, $original, 'gallery'))->handle();

        $gallery = $event->fresh()->invitation_customization['media']['gallery'];
        $this->assertNotSame([$original], $gallery);
        $this->assertSame($token, $event->fresh()->customizationToken());

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($event, [
                'customization_token' => $token,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($gallery, $event->fresh()->invitation_customization['media']['gallery']);
    }

    public function test_a_staged_photo_replaced_in_another_tab_is_reported(): void
    {
        Storage::fake('public');
        Queue::fake();
        $owner = User::factory()->create();
        $event = $this->eventFor($owner, 'graduation-template-2-botanical-blush');

        $first = $this->actingAs($owner)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_HERO_PORTRAIT,
            'file' => UploadedFile::fake()->image('one.jpg', 400, 500),
        ])->assertCreated()->json('id');

        // Another tab picks a new hero portrait: single-value slots replace the staged row.
        $this->actingAs($owner)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_HERO_PORTRAIT,
            'file' => UploadedFile::fake()->image('two.jpg', 400, 500),
        ])->assertCreated();

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($event, [
                'staged_media' => [$first],
            ]))
            ->assertSessionHasErrors(['staged_media' => UpdateInvitationDesignRequest::STAGED_GONE_MESSAGE]);
    }

    // --- Optimisation failure ---

    public function test_a_failed_optimisation_marks_the_photo_and_the_form_shows_it(): void
    {
        Storage::fake('public');
        $owner = User::factory()->pro()->create();
        $event = $this->eventFor($owner, 'modern-minimal');
        $path = 'invitation-gallery/'.$event->id.'/gal_src_broken.jpg';
        Storage::disk('public')->put($path, 'not really an image');
        $event->forceFill(['invitation_customization' => ['media' => ['gallery' => [$path]]]])->save();
        $token = $event->fresh()->customizationToken();

        (new ProcessInvitationDesignImageJob($event->id, $path, 'gallery'))->failed(new RuntimeException('decode'));

        $media = $event->fresh()->invitation_customization['media'];
        $this->assertSame([$path], $media['gallery']);
        $this->assertSame([$path], $media['unoptimised']);
        $this->assertSame($token, $event->fresh()->customizationToken());

        $this->actingAs($owner)
            ->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('Not optimised', escape: false);
    }

    public function test_removing_an_unoptimised_photo_clears_its_mark(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $event = $this->eventFor($owner);
        $path = 'invitation-gallery/'.$event->id.'/gal_src_broken.jpg';
        Storage::disk('public')->put($path, 'x');
        $event->forceFill(['invitation_customization' => ['media' => ['gallery' => [$path], 'unoptimised' => [$path]]]])->save();

        $this->actingAs($owner)
            ->patch(route('events.invitation-design.update', $event), $this->designPayload($event, [
                'gallery_remove' => [$path],
            ]))
            ->assertSessionHasNoErrors();

        $media = $event->fresh()->invitation_customization['media'];
        $this->assertSame([], $media['gallery']);
        $this->assertSame([], $media['unoptimised'] ?? []);
    }
}
