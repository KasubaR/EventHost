<?php

namespace Tests\Feature;

use App\Enums\TicketingStatus;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\StagedMedia;
use App\Models\User;
use App\Support\InvitationMediaRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The event cover's edge cases: missing, wrong format, too large, extreme or
 * unreadable pixels, a save that fails halfway, replace, remove, and a database
 * path whose file is gone.
 */
class CoverImageEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function eventFor(User $user, array $attributes = []): Event
    {
        $tpl = InvitationTemplate::query()->where('slug', 'pro-magazine')->firstOrFail();

        return Event::factory()->for($user)->create(array_merge([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(Event $event, array $overrides = []): array
    {
        return array_merge([
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => '18:00',
        ], $overrides);
    }

    private function photo(string $name = 'cover.jpg', int $width = 1400, int $height = 800): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    /**
     * A PNG with a valid header and no image data. getimagesize() reads the
     * declared size from the header alone, so validation sees whatever size
     * we declare here, while Intervention has nothing to decode.
     */
    private function headerOnlyPng(int $width, int $height, string $name = 'cover.png'): UploadedFile
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $chunk = pack('N', strlen($ihdr)).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
        $bytes = "\x89PNG\r\n\x1a\n".$chunk;

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function storedCover(string $path = 'events/old.webp'): string
    {
        Storage::disk('public')->put($path, 'old-cover');

        return $path;
    }

    // No cover

    public function test_saving_without_a_cover_leaves_it_empty_and_shows_the_default_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user, ['cover_image' => null]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event))
            ->assertSessionHasNoErrors();

        $event->refresh();
        $this->assertNull($event->cover_image);
        $this->assertFalse($event->hasCoverImage());
        $this->assertSame(asset('images/default-event.png'), $event->cover_image_url);
    }

    // Unsupported format

    public function test_staging_rejects_a_non_image_cover(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user);

        $this->actingAs($user)
            ->postJson(route('events.media.stage', $event), [
                'slot' => StagedMedia::SLOT_COVER,
                'file' => UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, StagedMedia::query()->count());
    }

    public function test_staging_rejects_a_gif_cover(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user);

        $this->actingAs($user)
            ->postJson(route('events.media.stage', $event), [
                'slot' => StagedMedia::SLOT_COVER,
                'file' => $this->photo('cover.gif'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'That file type is not supported.']);
    }

    public function test_event_update_rejects_a_gif_cover(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user, ['cover_image' => null]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'cover_image' => $this->photo('cover.gif'),
            ]))
            ->assertSessionHasErrors('cover_image');

        $this->assertNull($event->fresh()->cover_image);
    }

    public function test_api_update_rejects_a_non_image_cover(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user, ['cover_image' => null]);

        $this->actingAs($user, 'sanctum')
            ->patchJson(route('api.v1.host.events.update', $event), $this->updatePayload($event, [
                'cover_image' => UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf'),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cover_image');
    }

    // Too large

    public function test_staging_rejects_a_cover_over_four_megabytes(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user);

        $this->actingAs($user)
            ->postJson(route('events.media.stage', $event), [
                'slot' => StagedMedia::SLOT_COVER,
                'file' => $this->photo()->size(InvitationMediaRules::COVER_MAX_KB + 1),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'That file is too large.']);
    }

    public function test_event_update_rejects_a_cover_over_four_megabytes(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user, ['cover_image' => null]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'cover_image' => $this->photo()->size(InvitationMediaRules::COVER_MAX_KB + 1),
            ]))
            ->assertSessionHasErrors('cover_image');

        $this->assertNull($event->fresh()->cover_image);
    }

    // Extreme dimensions

    public function test_staging_rejects_a_cover_smaller_than_the_minimum(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user);

        $this->actingAs($user)
            ->postJson(route('events.media.stage', $event), [
                'slot' => StagedMedia::SLOT_COVER,
                'file' => $this->photo('tiny.jpg', 100, 100),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => InvitationMediaRules::COVER_DIMENSIONS_MESSAGE]);
    }

    public function test_staging_rejects_a_cover_with_an_enormous_pixel_size(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user);

        $this->actingAs($user)
            ->postJson(route('events.media.stage', $event), [
                'slot' => StagedMedia::SLOT_COVER,
                'file' => $this->headerOnlyPng(12000, 12000),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => InvitationMediaRules::COVER_DIMENSIONS_MESSAGE]);

        $this->assertSame([], Storage::disk('public')->allFiles('events'));
    }

    public function test_event_update_rejects_a_cover_smaller_than_the_minimum(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user, ['cover_image' => null]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'cover_image' => $this->photo('tiny.jpg', 100, 100),
            ]))
            ->assertSessionHasErrors(['cover_image' => InvitationMediaRules::COVER_DIMENSIONS_MESSAGE]);
    }

    public function test_a_very_wide_cover_is_cropped_to_the_sharing_ratio(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user);

        $staged = $this->actingAs($user)
            ->postJson(route('events.media.stage', $event), [
                'slot' => StagedMedia::SLOT_COVER,
                'file' => $this->photo('panorama.jpg', 4000, 400),
            ])
            ->assertCreated()
            ->json();

        $path = StagedMedia::query()->findOrFail($staged['id'])->path;
        $this->assertStringStartsWith('events/', $path);
        $this->assertStringEndsWith('.webp', $path);

        $size = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([1200, 630], [$size[0], $size[1]]);
    }

    public function test_staging_reports_an_unreadable_cover_instead_of_failing(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user);

        $this->actingAs($user)
            ->postJson(route('events.media.stage', $event), [
                'slot' => StagedMedia::SLOT_COVER,
                'file' => $this->headerOnlyPng(1400, 800),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => InvitationMediaRules::COVER_UNREADABLE_MESSAGE]);

        $this->assertSame(0, StagedMedia::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles('events'));
    }

    public function test_event_update_reports_an_unreadable_cover_and_keeps_the_old_one(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $old = $this->storedCover();
        $event = $this->eventFor($user, ['cover_image' => $old]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'cover_image' => $this->headerOnlyPng(1400, 800),
            ]))
            ->assertSessionHasErrors(['cover_image' => InvitationMediaRules::COVER_UNREADABLE_MESSAGE]);

        $this->assertSame($old, $event->fresh()->cover_image);
        Storage::disk('public')->assertExists($old);
    }

    // Upload fails halfway

    public function test_a_failed_save_deletes_the_new_cover_and_keeps_the_old_one(): void
    {
        Storage::fake('public');

        $user = User::factory()->withoutCredits()->create();
        $old = $this->storedCover();
        $event = $this->eventFor($user, ['cover_image' => $old, 'is_published' => false]);

        // Publishing with no credits throws inside the save transaction, after the
        // new cover has already been written to disk.
        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'cover_image' => $this->photo(),
                'publish' => '1',
            ]))
            ->assertRedirect(route('billing.show'));

        $this->assertSame($old, $event->fresh()->cover_image);
        Storage::disk('public')->assertExists($old);
        $this->assertSame([$old], Storage::disk('public')->allFiles('events'));
    }

    public function test_discarding_a_staged_cover_deletes_its_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user);

        $staged = $this->actingAs($user)
            ->postJson(route('events.media.stage', $event), [
                'slot' => StagedMedia::SLOT_COVER,
                'file' => $this->photo(),
            ])
            ->assertCreated()
            ->json();

        $path = StagedMedia::query()->findOrFail($staged['id'])->path;

        $this->actingAs($user)
            ->deleteJson(route('events.media.unstage', [$event, $staged['id']]))
            ->assertOk()
            ->assertJson(['deleted' => true]);

        Storage::disk('public')->assertMissing($path);
    }

    // Replace

    public function test_replacing_the_cover_deletes_the_previous_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $old = $this->storedCover();
        $event = $this->eventFor($user, ['cover_image' => $old]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'cover_image' => $this->photo(),
            ]))
            ->assertSessionHasNoErrors();

        $new = $event->fresh()->cover_image;
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertExists($new);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_replacing_through_a_staged_pick_deletes_the_previous_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $old = $this->storedCover();
        $event = $this->eventFor($user, ['cover_image' => $old]);

        $staged = $this->actingAs($user)
            ->postJson(route('events.media.stage', $event), [
                'slot' => StagedMedia::SLOT_COVER,
                'file' => $this->photo(),
            ])
            ->assertCreated()
            ->json();

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'staged_media' => [$staged['id']],
            ]))
            ->assertSessionHasNoErrors();

        $new = $event->fresh()->cover_image;
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertExists($new);
        Storage::disk('public')->assertMissing($old);
    }

    // Remove

    public function test_edit_page_offers_to_remove_a_saved_cover(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user, ['cover_image' => $this->storedCover()]);

        $this->actingAs($user)
            ->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('name="remove_cover"', false)
            ->assertSee('Remove cover image', false);
    }

    public function test_edit_page_hides_remove_when_there_is_no_cover(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user, ['cover_image' => null]);

        $this->actingAs($user)
            ->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('Upload cover', false)
            ->assertDontSee('name="remove_cover"', false);
    }

    public function test_removing_the_cover_clears_the_column_and_deletes_the_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $old = $this->storedCover();
        $event = $this->eventFor($user, ['cover_image' => $old]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'remove_cover' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $event->refresh();
        $this->assertNull($event->cover_image);
        $this->assertSame(asset('images/default-event.png'), $event->cover_image_url);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_an_unticked_remove_box_keeps_the_cover(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $old = $this->storedCover();
        $event = $this->eventFor($user, ['cover_image' => $old]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'remove_cover' => '0',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($old, $event->fresh()->cover_image);
        Storage::disk('public')->assertExists($old);
    }

    public function test_a_new_cover_in_the_same_save_wins_over_remove(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $old = $this->storedCover();
        $event = $this->eventFor($user, ['cover_image' => $old]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'remove_cover' => '1',
                'cover_image' => $this->photo(),
            ]))
            ->assertSessionHasNoErrors();

        $new = $event->fresh()->cover_image;
        $this->assertNotNull($new);
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertExists($new);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_api_can_remove_the_cover(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $old = $this->storedCover();
        $event = $this->eventFor($user, ['cover_image' => $old]);

        $this->actingAs($user, 'sanctum')
            ->patchJson(route('api.v1.host.events.update', $event), $this->updatePayload($event, [
                'remove_cover' => true,
            ]))
            ->assertOk();

        $this->assertNull($event->fresh()->cover_image);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_a_ticketed_host_cannot_remove_the_hero(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $hero = $this->storedCover('events/hero.webp');
        $event = Event::factory()->for($user)->ticketed()->create(['cover_image' => $hero]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'remove_cover' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($hero, $event->fresh()->cover_image);
        Storage::disk('public')->assertExists($hero);
    }

    // Deleted from storage, still in the database

    public function test_a_cover_whose_file_is_missing_falls_back_to_the_default_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user, ['cover_image' => 'events/gone.webp']);

        $this->assertFalse($event->hasCoverImage());
        $this->assertSame(asset('images/default-event.png'), $event->cover_image_url);
        $this->assertSame('events/gone.webp', $event->fresh()->cover_image);
    }

    public function test_a_cover_whose_file_exists_uses_the_storage_url(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $path = $this->storedCover('events/here.webp');
        $event = $this->eventFor($user, ['cover_image' => $path]);

        $this->assertTrue($event->hasCoverImage());
        $this->assertSame(asset('storage/'.$path), $event->cover_image_url);
    }

    public function test_the_ticketed_landing_page_uses_the_fallback_hero_when_the_file_is_missing(): void
    {
        Storage::fake('public');

        $event = Event::factory()->ticketed()->published()->create([
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'cover_image' => 'events/gone.webp',
        ]);

        $this->get(route('events.public', ['slug' => $event->slug]))
            ->assertOk()
            ->assertSee('tev-hero--fallback', false)
            ->assertDontSee('storage/events/gone.webp', false);
    }

    public function test_a_missing_cover_file_can_still_be_removed(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $event = $this->eventFor($user, ['cover_image' => 'events/gone.webp']);

        $this->actingAs($user)
            ->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('name="remove_cover"', false)
            ->assertDontSee('storage/events/gone.webp', false);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->updatePayload($event, [
                'remove_cover' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull($event->fresh()->cover_image);
    }
}
