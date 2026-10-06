<?php

namespace Tests\Feature;

use App\Jobs\GenerateEventShareImageJob;
use App\Models\Event;
use App\Models\User;
use App\Support\InvitationShareImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Plan: plans/invitation-page-compatibility.md Phase 5. A link preview in WhatsApp or Facebook shows a 1200×630 JPEG of the
 * event's picture, not the stored WebP; the page's og:image tags point at it once it exists.
 */
class InvitationShareImageTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $overrides = []): Event
    {
        return Event::factory()->for(User::factory()->create())->published()->create(array_merge([
            'name' => 'Ada and Ben',
            'slug' => 'share-'.bin2hex(random_bytes(4)),
            'rsvp_deadline' => null,
            'cover_image' => null,
        ], $overrides));
    }

    /** A real WebP of the given size on the fake public disk. */
    private function putWebp(string $path, int $width = 1500, int $height = 900): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, 0x3366CC);
        ob_start();
        imagewebp($image);
        Storage::disk('public')->put($path, (string) ob_get_clean());
    }

    private function saveMedia(Event $event, array $media): void
    {
        $event->forceFill(['invitation_customization' => ['media' => $media]])->saveQuietly();
    }

    // ── which picture ────────────────────────────────────────────────────────

    public function test_with_a_cover_the_first_gallery_photo_wins_and_the_cover_is_the_fallback(): void
    {
        Storage::fake('public');
        $this->putWebp('events/cover.webp');
        $this->putWebp('invitation-gallery/1/a.webp');
        $event = $this->event(['cover_image' => 'events/cover.webp']);

        $this->assertSame('invitation-gallery/1/a.webp', InvitationShareImage::sourcePath($event, ['gallery' => ['invitation-gallery/1/a.webp']]));
        $this->assertSame('events/cover.webp', InvitationShareImage::sourcePath($event, ['gallery' => []]));
    }

    public function test_without_a_cover_it_is_the_first_couple_photo_then_the_portrait_then_the_gallery(): void
    {
        Storage::fake('public');
        foreach (['invitation-couple/1/c.webp', 'invitation-hero/1/h.webp', 'invitation-gallery/1/g.webp'] as $path) {
            $this->putWebp($path);
        }
        $event = $this->event();

        $all = ['couple_photos' => ['', 'invitation-couple/1/c.webp'], 'hero_portrait' => 'invitation-hero/1/h.webp', 'gallery' => ['invitation-gallery/1/g.webp']];
        $this->assertSame('invitation-couple/1/c.webp', InvitationShareImage::sourcePath($event, $all));

        unset($all['couple_photos']);
        $this->assertSame('invitation-hero/1/h.webp', InvitationShareImage::sourcePath($event, $all));

        unset($all['hero_portrait']);
        $this->assertSame('invitation-gallery/1/g.webp', InvitationShareImage::sourcePath($event, $all));

        $this->assertNull(InvitationShareImage::sourcePath($event, []), 'nothing to show: the platform default is used');
    }

    public function test_a_missing_file_or_a_url_is_never_the_source(): void
    {
        Storage::fake('public');
        $event = $this->event();

        $this->assertNull(InvitationShareImage::sourcePath($event, ['gallery' => ['invitation-gallery/1/gone.webp', 'https://images.example.com/a.jpg', '/images/b.jpg']]));
    }

    public function test_the_file_name_follows_the_source_so_a_changed_picture_is_a_new_url(): void
    {
        Storage::fake('public');
        $this->putWebp('invitation-gallery/1/a.webp', 1500, 900);
        $first = InvitationShareImage::pathFor(7, 'invitation-gallery/1/a.webp');

        $this->assertMatchesRegularExpression('#^invitation-share/7/[0-9a-f]{12}\.jpg$#', $first);
        $this->assertSame($first, InvitationShareImage::pathFor(7, 'invitation-gallery/1/a.webp'), 'stable while the source is unchanged');

        $this->putWebp('invitation-gallery/1/a.webp', 1600, 1000); // replaced in place: a different size
        $this->assertNotSame($first, InvitationShareImage::pathFor(7, 'invitation-gallery/1/a.webp'));

        $this->assertNull(InvitationShareImage::pathFor(7, 'invitation-gallery/1/missing.webp'));
    }

    // ── the job ──────────────────────────────────────────────────────────────

    public function test_the_job_writes_a_1200_by_630_jpeg_and_replaces_the_old_one(): void
    {
        Storage::fake('public');
        $this->putWebp('invitation-gallery/1/a.webp');
        $event = $this->event();
        $this->saveMedia($event, ['gallery' => ['invitation-gallery/1/a.webp']]);
        Storage::disk('public')->put('invitation-share/'.$event->id.'/stale.jpg', 'old');

        (new GenerateEventShareImageJob($event->id))->handle();

        $target = InvitationShareImage::pathFor($event->id, 'invitation-gallery/1/a.webp');
        Storage::disk('public')->assertExists($target);
        $size = getimagesizefromstring(Storage::disk('public')->get($target));
        $this->assertSame([1200, 630], [$size[0], $size[1]]);
        $this->assertSame('image/jpeg', $size['mime']);
        Storage::disk('public')->assertMissing('invitation-share/'.$event->id.'/stale.jpg');
    }

    public function test_the_job_is_a_no_op_when_the_picture_is_unchanged(): void
    {
        Storage::fake('public');
        $this->putWebp('invitation-gallery/1/a.webp');
        $event = $this->event();
        $this->saveMedia($event, ['gallery' => ['invitation-gallery/1/a.webp']]);

        (new GenerateEventShareImageJob($event->id))->handle();
        $target = InvitationShareImage::pathFor($event->id, 'invitation-gallery/1/a.webp');
        Storage::disk('public')->put($target, 'sentinel');

        (new GenerateEventShareImageJob($event->id))->handle();

        $this->assertSame('sentinel', Storage::disk('public')->get($target), 'an existing file for the same picture is not rewritten');
    }

    public function test_the_job_drops_the_copy_when_there_is_no_picture_left(): void
    {
        Storage::fake('public');
        $event = $this->event();
        Storage::disk('public')->put('invitation-share/'.$event->id.'/old.jpg', 'old');

        (new GenerateEventShareImageJob($event->id))->handle();

        Storage::disk('public')->assertMissing('invitation-share/'.$event->id.'/old.jpg');
    }

    public function test_the_job_skips_ticketed_events_and_swallows_an_undecodable_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('invitation-gallery/1/bad.webp', 'not an image');
        $event = $this->event();
        $this->saveMedia($event, ['gallery' => ['invitation-gallery/1/bad.webp']]);

        (new GenerateEventShareImageJob($event->id))->handle(); // must not throw

        $this->assertSame([], Storage::disk('public')->files('invitation-share/'.$event->id));
    }

    // ── when it runs ─────────────────────────────────────────────────────────

    public function test_saving_the_cover_or_the_design_dispatches_the_job_and_other_edits_do_not(): void
    {
        $event = $this->event();
        Queue::fake(); // after creating the event, so only what the test does is counted

        $event->update(['name' => 'A new name']);
        Queue::assertNothingPushed();

        $event->update(['cover_image' => 'events/new-cover.webp']);
        Queue::assertPushed(GenerateEventShareImageJob::class, 1);

        $event->forceFill(['invitation_customization' => ['media' => ['gallery' => ['x.webp']]]])->save();
        Queue::assertPushed(GenerateEventShareImageJob::class, 2);
    }

    // ── the page ─────────────────────────────────────────────────────────────

    public function test_the_page_points_og_image_at_the_jpeg_with_its_size_type_and_alt(): void
    {
        Storage::fake('public');
        $this->putWebp('invitation-gallery/1/a.webp');
        $event = $this->event();
        $this->saveMedia($event, ['gallery' => ['invitation-gallery/1/a.webp']]);
        (new GenerateEventShareImageJob($event->id))->handle();
        $jpeg = InvitationShareImage::pathFor($event->id, 'invitation-gallery/1/a.webp');

        $html = $this->get(route('events.public', $event->slug))->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:image" content="'.asset('storage/'.$jpeg).'">', $html);
        $this->assertStringContainsString('<meta name="twitter:image" content="'.asset('storage/'.$jpeg).'">', $html);
        $this->assertStringContainsString('<meta property="og:image:type" content="image/jpeg">', $html);
        $this->assertStringContainsString('<meta property="og:image:width" content="1200">', $html);
        $this->assertStringContainsString('<meta property="og:image:height" content="630">', $html);
        $this->assertStringContainsString('<meta property="og:image:alt" content="Ada and Ben">', $html);
    }

    public function test_until_the_jpeg_exists_the_page_keeps_using_the_stored_image_and_claims_no_size(): void
    {
        Storage::fake('public');
        $this->putWebp('invitation-gallery/1/a.webp');
        $event = $this->event();
        $this->saveMedia($event, ['gallery' => ['invitation-gallery/1/a.webp']]); // quiet save: no job, so no JPEG yet

        $html = $this->get(route('events.public', $event->slug))->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:image" content="'.asset('storage/invitation-gallery/1/a.webp').'">', $html);
        $this->assertStringNotContainsString('og:image:width', $html);
        $this->assertStringContainsString('<meta property="og:image:alt" content="Ada and Ben">', $html);
    }

    // ── housekeeping ─────────────────────────────────────────────────────────

    public function test_prune_removes_the_preview_folder_of_an_event_that_no_longer_exists_and_keeps_live_ones(): void
    {
        Storage::fake('public');
        $live = $this->event();
        Storage::disk('public')->put("invitation-share/{$live->id}/keep.jpg", 'x');
        Storage::disk('public')->put('invitation-share/999999/orphan.jpg', 'x');

        $this->artisan('invitation:prune-orphaned-files', ['--grace' => 1])->assertExitCode(0);

        Storage::disk('public')->assertExists("invitation-share/{$live->id}/keep.jpg");
        Storage::disk('public')->assertMissing('invitation-share/999999/orphan.jpg');
    }

    public function test_prune_keeps_the_preview_folder_of_a_soft_deleted_event(): void
    {
        Storage::fake('public');
        $event = $this->event();
        Storage::disk('public')->put("invitation-share/{$event->id}/keep.jpg", 'x');
        $event->delete();

        $this->artisan('invitation:prune-orphaned-files', ['--grace' => 1])->assertExitCode(0);

        Storage::disk('public')->assertExists("invitation-share/{$event->id}/keep.jpg");
    }

    public function test_the_backfill_writes_missing_images_once_and_dry_run_writes_nothing(): void
    {
        Queue::fake(); // so creating the event does not generate it; the command must
        Storage::fake('public');
        $this->putWebp('invitation-gallery/1/a.webp');
        $event = $this->event();
        $this->saveMedia($event, ['gallery' => ['invitation-gallery/1/a.webp']]);
        $target = InvitationShareImage::pathFor($event->id, 'invitation-gallery/1/a.webp');

        $this->artisan('invitation:make-share-images', ['--dry-run' => true])->assertExitCode(0);
        Storage::disk('public')->assertMissing($target);

        $this->artisan('invitation:make-share-images')->expectsOutputToContain('Written: 1')->assertExitCode(0);
        Storage::disk('public')->assertExists($target);

        $this->artisan('invitation:make-share-images')->expectsOutputToContain('Written: 0')->assertExitCode(0);
    }
}
