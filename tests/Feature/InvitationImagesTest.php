<?php

namespace Tests\Feature;

use App\Jobs\ProcessInvitationDesignImageJob;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use App\Services\InvitationCustomizationService;
use App\Support\InvitationLayoutVariant;
use App\Support\InvitationMediaUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Plan: plans/invitation-page-resilience.md Phase 5 — the hero image is fetched first, gallery photos have a small
 * copy served through srcset, a failed image degrades quietly, and every image says what it is.
 */
class InvitationImagesTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $overrides = []): Event
    {
        return Event::factory()->for(User::factory()->create())->published()->create(array_merge([
            'name' => 'Ada and Ben',
            'slug' => 'images-'.bin2hex(random_bytes(4)),
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'event_time' => '15:00:00',
            'rsvp_deadline' => null,
        ], $overrides));
    }

    /** A real WebP of the given width, written to the fake public disk. */
    private function putWebp(string $path, int $width, int $height = 400): void
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagewebp($image);
        Storage::disk('public')->put($path, (string) ob_get_clean());
    }

    // ── naming ───────────────────────────────────────────────────────────────

    public function test_the_small_copy_is_named_beside_its_photo_and_only_for_processed_gallery_webps(): void
    {
        $this->assertSame('invitation-gallery/7/gal_a-600.webp', InvitationMediaUrl::variantName('invitation-gallery/7/gal_a.webp'));
        $this->assertSame('invitation-gallery/7/gal_a.webp', InvitationMediaUrl::parentOfVariant('invitation-gallery/7/gal_a-600.webp'));

        $this->assertNull(InvitationMediaUrl::variantName('invitation-gallery/7/gal_a-600.webp'), 'a small copy has no small copy');
        $this->assertNull(InvitationMediaUrl::variantName('invitation-gallery/7/gal_src_a.jpg'), 'originals are not processed yet');
        $this->assertNull(InvitationMediaUrl::variantName('invitation-couple/7/cp_a.webp'), 'only gallery photos get one');
        $this->assertNull(InvitationMediaUrl::variantName('https://example.com/a.webp'));
        $this->assertNull(InvitationMediaUrl::parentOfVariant('invitation-gallery/7/gal_a.webp'));
    }

    public function test_responsive_attributes_exist_only_when_the_small_copy_does(): void
    {
        Storage::fake('public');
        $this->putWebp('invitation-gallery/1/gal_with.webp', 1200);
        $this->putWebp('invitation-gallery/1/gal_with-600.webp', 600);
        $this->putWebp('invitation-gallery/1/gal_without.webp', 500);

        $with = InvitationMediaUrl::responsiveAttributes('invitation-gallery/1/gal_with.webp');
        $this->assertStringContainsString('srcset="', $with);
        $this->assertStringContainsString('gal_with-600.webp 600w', $with);
        $this->assertStringContainsString('gal_with.webp 1200w', $with);
        $this->assertStringContainsString('sizes="', $with);

        $this->assertSame('', InvitationMediaUrl::responsiveAttributes('invitation-gallery/1/gal_without.webp'));
        $this->assertSame('', InvitationMediaUrl::responsiveAttributes('https://example.com/a.webp'));
    }

    public function test_with_variants_adds_only_the_copies_that_exist(): void
    {
        Storage::fake('public');
        $this->putWebp('invitation-gallery/1/gal_a.webp', 1200);
        $this->putWebp('invitation-gallery/1/gal_a-600.webp', 600);
        $this->putWebp('invitation-gallery/1/gal_b.webp', 500);

        $this->assertEqualsCanonicalizing(
            ['invitation-gallery/1/gal_a.webp', 'invitation-gallery/1/gal_a-600.webp', 'invitation-gallery/1/gal_b.webp'],
            InvitationMediaUrl::withVariants(['invitation-gallery/1/gal_a.webp', 'invitation-gallery/1/gal_b.webp']),
        );
    }

    // ── the job writes the copy ──────────────────────────────────────────────

    private function eventWithOriginal(int $width, string $name): array
    {
        Storage::fake('public');
        $event = $this->event();
        $original = 'invitation-gallery/'.$event->id.'/gal_src_'.$name.'.jpg';
        Storage::disk('public')->put($original, UploadedFile::fake()->image($name.'.jpg', $width, (int) ($width * 0.66))->getContent());
        $event->forceFill(['invitation_customization' => ['media' => ['gallery' => [$original]]]])->save();

        return [$event, $original];
    }

    public function test_the_job_writes_a_600px_copy_next_to_a_wide_photo(): void
    {
        [$event, $original] = $this->eventWithOriginal(1500, 'wide');

        (new ProcessInvitationDesignImageJob($event->id, $original, 'gallery'))->handle();

        $path = $event->fresh()->invitation_customization['media']['gallery'][0];
        $small = InvitationMediaUrl::variantName($path);

        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertExists($small);
        $this->assertSame(1200, getimagesizefromstring(Storage::disk('public')->get($path))[0]);
        $this->assertSame(600, getimagesizefromstring(Storage::disk('public')->get($small))[0]);
        $this->assertNotContains($small, $event->fresh()->invitation_customization['media']['gallery'], 'the copy is never listed in the customization');
    }

    public function test_the_job_writes_no_copy_for_a_photo_that_is_not_wider_than_600px(): void
    {
        [$event, $original] = $this->eventWithOriginal(500, 'narrow');

        (new ProcessInvitationDesignImageJob($event->id, $original, 'gallery'))->handle();

        $path = $event->fresh()->invitation_customization['media']['gallery'][0];
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing(InvitationMediaUrl::variantName($path));
    }

    // ── every layout's gallery uses it, with real alt text ───────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function galleryLayouts(): array
    {
        return [
            'standard' => [InvitationLayoutVariant::STANDARD],
            'wedding invitation' => [InvitationLayoutVariant::WEDDING_INVITATION],
            'wedding noir' => [InvitationLayoutVariant::WEDDING_INVITATION_NOIR],
            'modern minimal' => [InvitationLayoutVariant::MODERN_MINIMAL],
            'midnight gold' => [InvitationLayoutVariant::WEDDING_MIDNIGHT_GOLD],
            'dusty blue' => [InvitationLayoutVariant::WEDDING_DUSTY_BLUE],
            'botanical' => [InvitationLayoutVariant::BOTANICAL_GRADUATION],
        ];
    }

    /**
     * @dataProvider galleryLayouts
     */
    public function test_a_layouts_gallery_has_alt_text_and_srcset(string $variant): void
    {
        $template = InvitationTemplate::query()->where('layout_variant', $variant)->first();
        $this->assertNotNull($template, "a {$variant} template is seeded");

        Storage::fake('public');
        $event = $this->event(['invitation_template_id' => $template->id]);
        $a = 'invitation-gallery/'.$event->id.'/gal_one.webp';
        $b = 'invitation-gallery/'.$event->id.'/gal_two.webp';
        $this->putWebp($a, 1200);
        $this->putWebp(InvitationMediaUrl::variantName($a), 600);
        $this->putWebp($b, 500);
        $event->forceFill(['invitation_customization' => ['media' => ['gallery' => [$a, $b]]]])->save();

        // The standard template does not list a gallery section by default, so its partial is rendered directly.
        $html = $variant === InvitationLayoutVariant::STANDARD
            ? view('events.invitations.sections.gallery', ['event' => $event, 'invitation' => app(InvitationCustomizationService::class)->merge($event)])->render()
            : $this->get(route('events.public', $event->slug))->assertOk()->getContent();

        $this->assertStringContainsString('alt="Photo 1 of 2 from Ada and Ben"', $html);
        $this->assertStringContainsString('alt="Photo 2 of 2 from Ada and Ben"', $html);
        $this->assertStringContainsString('gal_one-600.webp 600w', $html);
        $this->assertStringNotContainsString('gal_two-600.webp', $html);
    }

    // ── hero cover: named and fetched first ──────────────────────────────────

    public function test_the_hero_cover_is_named_and_fetched_first(): void
    {
        $template = InvitationTemplate::query()->where('layout_variant', InvitationLayoutVariant::PRO_MAGAZINE)->firstOrFail();
        $event = $this->event(['invitation_template_id' => $template->id]);

        $html = $this->get(route('events.public', $event->slug))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<img[^>]*class="evt-public-cover[^"]*"[^>]*alt="Ada and Ben"[^>]*fetchpriority="high"|<img[^>]*alt="Ada and Ben"[^>]*fetchpriority="high"[^>]*class="evt-public-cover/', $html);
    }

    public function test_no_gallery_photo_is_marked_high_priority(): void
    {
        $html = '';
        foreach (glob(resource_path('views/events/invitations/layouts/*/sections/gallery.blade.php')) as $file) {
            $html .= file_get_contents($file);
        }
        $html .= file_get_contents(resource_path('views/events/invitations/sections/gallery.blade.php'));

        $this->assertStringNotContainsString('fetchpriority', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    // ── deleting a photo deletes its copy ────────────────────────────────────

    public function test_removing_a_gallery_photo_removes_its_small_copy_too(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $template = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => $template->id, 'invitation_customization' => null]);

        $keep = 'invitation-gallery/'.$event->id.'/gal_keep.webp';
        $drop = 'invitation-gallery/'.$event->id.'/gal_drop.webp';
        foreach ([$keep, $drop] as $path) {
            $this->putWebp($path, 1200);
            $this->putWebp(InvitationMediaUrl::variantName($path), 600);
        }
        $event->forceFill(['invitation_customization' => ['media' => ['gallery' => [$keep, $drop]]]])->save();

        $order = collect($template->default_sections)->pluck('type')->values()->all();
        $visibility = array_fill_keys($order, '1');

        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), [
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
            'gallery_remove' => [$drop],
            'rsvp_form' => ['message' => ['visible' => '1', 'label' => 'Message to host']],
        ])->assertSessionHas('status', 'invitation-design-saved');

        Storage::disk('public')->assertMissing($drop);
        Storage::disk('public')->assertMissing(InvitationMediaUrl::variantName($drop));
        Storage::disk('public')->assertExists($keep);
        Storage::disk('public')->assertExists(InvitationMediaUrl::variantName($keep));
    }

    // ── prune: a small copy lives while its photo does ───────────────────────

    public function test_prune_keeps_the_small_copy_of_a_live_photo_and_removes_orphans_with_theirs(): void
    {
        Storage::fake('public');
        $event = $this->event();
        $live = 'invitation-gallery/'.$event->id.'/gal_live.webp';
        $orphan = 'invitation-gallery/'.$event->id.'/gal_orphan.webp';
        foreach ([$live, $orphan] as $path) {
            $this->putWebp($path, 1200);
            $this->putWebp(InvitationMediaUrl::variantName($path), 600);
        }
        $event->forceFill(['invitation_customization' => ['media' => ['gallery' => [$live]]]])->save();

        $this->travel(2)->hours();
        $this->artisan('invitation:prune-orphaned-files', ['--grace' => 1])->assertExitCode(0);

        Storage::disk('public')->assertExists($live);
        Storage::disk('public')->assertExists(InvitationMediaUrl::variantName($live));
        Storage::disk('public')->assertMissing($orphan);
        Storage::disk('public')->assertMissing(InvitationMediaUrl::variantName($orphan));
    }

    public function test_prune_also_protects_a_photo_that_only_the_previous_design_references(): void
    {
        Storage::fake('public');
        $event = $this->event();
        $path = 'invitation-gallery/'.$event->id.'/gal_prev.webp';
        $this->putWebp($path, 1200);
        $this->putWebp(InvitationMediaUrl::variantName($path), 600);
        $event->forceFill(['invitation_customization_previous' => ['media' => ['gallery' => [$path]]]])->save();

        $this->travel(2)->hours();
        $this->artisan('invitation:prune-orphaned-files', ['--grace' => 1])->assertExitCode(0);

        Storage::disk('public')->assertExists(InvitationMediaUrl::variantName($path));
    }

    // ── backfill ─────────────────────────────────────────────────────────────

    public function test_the_backfill_command_writes_missing_copies_once_and_dry_run_writes_nothing(): void
    {
        Storage::fake('public');
        $event = $this->event();
        $wide = 'invitation-gallery/'.$event->id.'/gal_wide.webp';
        $narrow = 'invitation-gallery/'.$event->id.'/gal_narrow.webp';
        $done = 'invitation-gallery/'.$event->id.'/gal_done.webp';
        $this->putWebp($wide, 1200);
        $this->putWebp($narrow, 500);
        $this->putWebp($done, 1200);
        $this->putWebp(InvitationMediaUrl::variantName($done), 600);
        $event->forceFill(['invitation_customization' => ['media' => ['gallery' => [$wide, $narrow, $done]]]])->save();

        $this->artisan('invitation:make-gallery-variants', ['--dry-run' => true])->assertExitCode(0);
        Storage::disk('public')->assertMissing(InvitationMediaUrl::variantName($wide));

        $this->artisan('invitation:make-gallery-variants')->expectsOutputToContain('Written: 1')->assertExitCode(0);
        Storage::disk('public')->assertExists(InvitationMediaUrl::variantName($wide));
        Storage::disk('public')->assertMissing(InvitationMediaUrl::variantName($narrow));
        $this->assertSame(600, getimagesizefromstring(Storage::disk('public')->get(InvitationMediaUrl::variantName($wide)))[0]);

        $this->artisan('invitation:make-gallery-variants')->expectsOutputToContain('Written: 0')->assertExitCode(0);
    }

    // ── a failed image, in the browser ───────────────────────────────────────

    public function test_the_script_and_styles_handle_an_image_that_fails(): void
    {
        $js = file_get_contents(public_path('js/invitation-public.js'));
        $css = file_get_contents(public_path('css/events-invitation.css'));

        $this->assertStringContainsString('function initImageFallbacks(root)', $js);
        $this->assertStringContainsString("root.addEventListener('error'", $js, 'error events do not bubble, so it listens in the capture phase');
        $this->assertStringContainsString("img.removeAttribute('srcset')", $js, 'a missing small copy falls back to the full-size file once');
        $this->assertStringContainsString("'evt-inv-img-gone'", $js);
        $this->assertStringContainsString('img.style.aspectRatio', $js, 'a failed cover keeps its frame');
        $this->assertStringContainsString('initImageFallbacks(root);', $js);

        $this->assertMatchesRegularExpression('/\.evt-inv-img-gone\s*\{[^}]*display:\s*none/', $css);
        $this->assertMatchesRegularExpression('/\.evt-inv-img-failed\s*\{[^}]*background:/', $css);
    }
}
