<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\StagedMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Images upload the moment they are picked, and the save that follows carries ids
 * instead of binaries. These cover the staging endpoint, the save that consumes it,
 * and the two ways it can go wrong: a forged id, and a rejected save deleting files
 * the form is still showing.
 */
class StagedMediaUploadTest extends TestCase
{
    use RefreshDatabase;

    private function eventFor(User $user, string $templateSlug = 'slate-minimal'): Event
    {
        $tpl = InvitationTemplate::query()->where('slug', $templateSlug)->firstOrFail();

        return Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function designPayload(Event $event, array $overrides = []): array
    {
        $tpl = $event->invitationTemplate ?? InvitationTemplate::findOrFail($event->invitation_template_id);
        $order = collect($tpl->default_sections)->pluck('type')->values()->all();
        $visibility = [];
        foreach ($order as $type) {
            $visibility[$type] = '1';
        }

        return array_merge([
            // Omitted by default: theme_palette is a Pro+ feature and these tests
            // use base-tier users. It's optional now — the save keeps the
            // template's default theme without it, same as a real base-tier submit.
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

    public function test_staging_an_image_stores_it_and_returns_an_id(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $response = $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_GALLERY,
            'file' => UploadedFile::fake()->image('party.jpg', 400, 300),
        ]);

        $response->assertCreated()->assertJsonStructure(['id', 'slot', 'url', 'name', 'bytes']);

        $staged = StagedMedia::query()->firstOrFail();
        $this->assertSame(StagedMedia::SLOT_GALLERY, $staged->slot);
        $this->assertSame($event->id, $staged->event_id);
        $this->assertSame($user->id, $staged->user_id);
        $this->assertSame('party.jpg', $staged->original_name);
        Storage::disk('public')->assertExists($staged->path);
    }

    public function test_staging_rejects_a_file_that_is_too_large(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_GALLERY,
            'file' => UploadedFile::fake()->image('huge.jpg')->size(6000),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(0, StagedMedia::query()->count());
    }

    public function test_staging_rejects_a_non_image_in_an_image_slot(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_GALLERY,
            'file' => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_staging_a_seventh_gallery_image_is_rejected(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->postJson(route('events.media.stage', $event), [
                'slot' => StagedMedia::SLOT_GALLERY,
                'file' => UploadedFile::fake()->image("g{$i}.jpg", 80, 80),
            ])->assertCreated();
        }

        $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_GALLERY,
            'file' => UploadedFile::fake()->image('seventh.jpg', 80, 80),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(6, StagedMedia::query()->count());
    }

    public function test_staging_a_hero_portrait_is_rejected_on_a_layout_without_one(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        // slate-minimal is the standard layout — no separate hero portrait slot.
        $event = $this->eventFor($user);

        $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_HERO_PORTRAIT,
            'file' => UploadedFile::fake()->image('hero.jpg', 200, 300),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_a_single_value_slot_replaces_its_previous_upload(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user, 'graduation-template-2-botanical-blush');

        $first = $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_HERO_PORTRAIT,
            'file' => UploadedFile::fake()->image('one.jpg', 200, 300),
        ])->assertCreated()->json();

        $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_HERO_PORTRAIT,
            'file' => UploadedFile::fake()->image('two.jpg', 200, 300),
        ])->assertCreated();

        $this->assertSame(1, StagedMedia::query()->where('slot', StagedMedia::SLOT_HERO_PORTRAIT)->count());
        $this->assertNull(StagedMedia::query()->find($first['id']));
    }

    public function test_a_user_cannot_stage_media_on_another_users_event(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->eventFor($owner);

        $this->actingAs($stranger)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_GALLERY,
            'file' => UploadedFile::fake()->image('sneaky.jpg', 80, 80),
        ])->assertForbidden();
    }

    public function test_saving_with_a_staged_id_publishes_the_image_and_consumes_the_row(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $staged = $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_GALLERY,
            'file' => UploadedFile::fake()->image('party.jpg', 400, 300),
        ])->json();

        $this->actingAs($user)
            ->patch(
                route('events.invitation-design.update', $event),
                $this->designPayload($event, ['staged_media' => [$staged['id']]])
            )
            ->assertSessionHas('status', 'invitation-design-saved');

        $event->refresh();
        $gallery = $event->invitation_customization['media']['gallery'] ?? [];

        $this->assertCount(1, $gallery);
        // The queued job converts to WebP after commit, exactly as for a form upload.
        $this->assertMatchesRegularExpression('/\.webp$/', $gallery[0]);
        Storage::disk('public')->assertExists($gallery[0]);

        $this->assertNull(StagedMedia::query()->find($staged['id']));
    }

    public function test_a_staged_id_from_another_users_session_is_ignored(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        $event = $this->eventFor($owner);

        // Row exists and belongs to this event, but to a different user.
        $foreign = StagedMedia::create([
            'event_id' => $event->id,
            'user_id' => $collaborator->id,
            'slot' => StagedMedia::SLOT_GALLERY,
            'path' => 'invitation-gallery/'.$event->id.'/gal_src_foreign.jpg',
            'original_name' => 'foreign.jpg',
            'bytes' => 1024,
        ]);

        $this->actingAs($owner)
            ->patch(
                route('events.invitation-design.update', $event),
                $this->designPayload($event, ['staged_media' => [$foreign->id]])
            )
            ->assertSessionHas('status', 'invitation-design-saved');

        $event->refresh();
        $this->assertSame([], $event->invitation_customization['media']['gallery'] ?? []);
        // Untouched — not consumed, not deleted.
        $this->assertNotNull(StagedMedia::query()->find($foreign->id));
    }

    public function test_a_rejected_save_leaves_staged_rows_and_files_intact(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $staged = $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_GALLERY,
            'file' => UploadedFile::fake()->image('party.jpg', 400, 300),
        ])->json();

        $row = StagedMedia::query()->findOrFail($staged['id']);

        // A bad palette fails validation before anything is written.
        $this->actingAs($user)
            ->patch(
                route('events.invitation-design.update', $event),
                $this->designPayload($event, [
                    'staged_media' => [$staged['id']],
                    'theme_palette' => 'not-a-real-palette',
                ])
            )
            ->assertSessionHasErrors('theme_palette');

        $this->assertNotNull(StagedMedia::query()->find($staged['id']));
        Storage::disk('public')->assertExists($row->path);
    }

    public function test_unstaging_deletes_the_row_and_the_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $staged = $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_GALLERY,
            'file' => UploadedFile::fake()->image('party.jpg', 400, 300),
        ])->json();

        $row = StagedMedia::query()->findOrFail($staged['id']);

        $this->actingAs($user)
            ->deleteJson(route('events.media.unstage', ['event' => $event, 'staged' => $staged['id']]))
            ->assertOk();

        $this->assertNull(StagedMedia::query()->find($staged['id']));
        Storage::disk('public')->assertMissing($row->path);
    }

    public function test_gallery_cap_counts_staged_uploads_against_saved_images(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        // Five already saved.
        $existing = [];
        for ($i = 0; $i < 5; $i++) {
            $path = 'invitation-gallery/'.$event->id.'/saved'.$i.'.webp';
            Storage::disk('public')->put($path, 'x');
            $existing[] = $path;
        }
        $event->invitation_customization = ['media' => ['gallery' => $existing]];
        $event->save();

        $ids = [];
        foreach (['a', 'b'] as $name) {
            $ids[] = $this->actingAs($user)->postJson(route('events.media.stage', $event), [
                'slot' => StagedMedia::SLOT_GALLERY,
                'file' => UploadedFile::fake()->image($name.'.jpg', 80, 80),
            ])->assertCreated()->json('id');
        }

        // 5 saved + 2 staged = 7.
        $this->actingAs($user)
            ->patch(
                route('events.invitation-design.update', $event),
                $this->designPayload($event, ['staged_media' => $ids])
            )
            ->assertSessionHasErrors('gallery_images');
    }

    public function test_removing_saved_images_makes_room_for_staged_ones(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $existing = [];
        for ($i = 0; $i < 6; $i++) {
            $path = 'invitation-gallery/'.$event->id.'/saved'.$i.'.webp';
            Storage::disk('public')->put($path, 'x');
            $existing[] = $path;
        }
        $event->invitation_customization = ['media' => ['gallery' => $existing]];
        $event->save();

        // Staging must not consider the saved six — the form has pending removals
        // it has not submitted yet.
        $id = $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_GALLERY,
            'file' => UploadedFile::fake()->image('replacement.jpg', 80, 80),
        ])->assertCreated()->json('id');

        $this->actingAs($user)
            ->patch(
                route('events.invitation-design.update', $event),
                $this->designPayload($event, [
                    'staged_media' => [$id],
                    'gallery_remove' => [$existing[0], $existing[1]],
                ])
            )
            ->assertSessionHas('status', 'invitation-design-saved');

        $event->refresh();
        $this->assertCount(5, $event->invitation_customization['media']['gallery']);
    }

    public function test_staged_cover_is_applied_on_event_update(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $staged = $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_COVER,
            'file' => UploadedFile::fake()->image('cover.jpg', 1400, 800),
        ])->assertCreated()->json();

        $this->actingAs($user)->patch(route('events.update', $event), [
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => '18:00',
            'staged_media' => [$staged['id']],
        ])->assertSessionHasNoErrors();

        $event->refresh();
        $this->assertNotNull($event->cover_image);
        $this->assertMatchesRegularExpression('/\.webp$/', $event->cover_image);
        Storage::disk('public')->assertExists($event->cover_image);
        $this->assertNull(StagedMedia::query()->find($staged['id']));
    }
}
