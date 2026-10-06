<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationTemplate;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\EventAttendance;
use App\Support\InvitationCountdown;
use App\Support\InvitationLayoutVariant;
use App\Support\InvitationVideoBackground;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan: plans/invitation-page-resilience.md. Phase 1 — the page is readable without JavaScript, with it slow,
 * and with it failing: nothing stays invisible, the countdown never reads "0 0 0 0", and the guest is told what
 * needs scripts. Phase 2 — the RSVP form works without JavaScript.
 */
class InvitationResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function event(array $overrides = []): Event
    {
        return Event::factory()->for(User::factory()->create())->published()->create(array_merge([
            'slug' => 'resilient-party',
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => null,
        ], $overrides));
    }

    private function atVenue(string $venueTime): void
    {
        Carbon::setTestNow(Carbon::parse($venueTime, config('events.timezone'))->utc());
    }

    // ── scripts-off safety ───────────────────────────────────────────────────

    public function test_the_head_marks_js_and_has_a_stall_guard_that_the_script_cancels(): void
    {
        $this->atVenue('2026-11-10 12:00:00');
        $event = $this->event();

        $html = $this->get(route('events.public', $event->slug))->assertOk()->getContent();

        $this->assertStringContainsString("classList.add('js')", $html);
        $this->assertStringContainsString('js-stalled', $html);
        $this->assertStringContainsString('data-inv-ready', $html);

        $script = file_get_contents(public_path('js/invitation-public.js'));
        $this->assertStringContainsString("setAttribute('data-inv-ready'", $script);
        $this->assertStringContainsString("classList.remove('js-stalled')", $script);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function revealLayouts(): array
    {
        return [
            'wedding invitation' => ['events-invitation-layout-wedding-invitation.css', 'wi-reveal'],
            'wedding noir' => ['events-invitation-layout-wedding-invitation-noir.css', 'wi2-reveal'],
        ];
    }

    /**
     * @dataProvider revealLayouts
     */
    public function test_a_reveal_layout_only_hides_content_when_scripts_will_reveal_it(string $file, string $class): void
    {
        $css = file_get_contents(public_path('css/'.$file));

        // Every rule that sets opacity 0 on the reveal class is scoped to html.js (via :where, no extra specificity).
        preg_match_all('/([^{}]+\.'.preg_quote($class, '/').')\s*\{[^}]*opacity:\s*0;/', $css, $hidden);
        $this->assertNotEmpty($hidden[1], 'the hidden state exists');
        foreach ($hidden[1] as $selector) {
            $this->assertStringContainsString(':where(html.js)', $selector);
        }

        // And there is a rule that undoes it when the script stalls.
        $this->assertMatchesRegularExpression('/html\.js-stalled[^{]*\.'.preg_quote($class, '/').'\s*\{[^}]*opacity:\s*1;/', $css);
    }

    // ── countdown ────────────────────────────────────────────────────────────

    public function test_countdown_parts_are_the_real_remaining_time_and_zero_after_the_start(): void
    {
        $this->atVenue('2026-11-10 12:00:00');
        $startsAt = Carbon::parse('2026-11-20 15:00:00', config('events.timezone'));

        $this->assertSame(['days' => '10', 'hours' => '03', 'minutes' => '00', 'seconds' => '00'], InvitationCountdown::parts($startsAt));

        $this->atVenue('2026-11-20 15:00:01');
        $this->assertSame(['days' => '0', 'hours' => '00', 'minutes' => '00', 'seconds' => '00'], InvitationCountdown::parts($startsAt));
    }

    public function test_the_countdown_targets_the_venue_start_not_utc_and_starts_with_real_values(): void
    {
        $this->atVenue('2026-11-10 12:00:00');
        $event = $this->event();

        $html = view('events.invitations.sections.countdown', [
            'event' => $event,
            'invitation' => ['effects' => ['countdown_enabled' => true]],
        ])->render();

        // 15:00 in Lusaka is +02:00; read as UTC it would be two hours late.
        $this->assertStringContainsString('data-target="2026-11-20T15:00:00+02:00"', $html);
        $this->assertStringContainsString('data-inv-cd-days>10<', $html);
        $this->assertStringContainsString('data-inv-cd-hours>03<', $html);
        $this->assertStringNotContainsString('data-inv-cd-days>0<', $html);
        // The sentence for guests whose ticker cannot run.
        $this->assertStringContainsString('Starts Friday, November 20, 2026 at 3:00 PM', $html);
    }

    public function test_the_botanical_countdown_also_targets_the_venue_start(): void
    {
        $this->atVenue('2026-11-10 12:00:00');
        $event = $this->event();

        $html = view('events.invitations.layouts.botanical_graduation.sections.countdown', [
            'event' => $event,
            'invitation' => ['effects' => ['countdown_enabled' => true]],
        ])->render();

        $this->assertStringContainsString('data-target="2026-11-20T15:00:00+02:00"', $html);
        $this->assertStringContainsString('data-inv-cd-days>10<', $html);
        $this->assertStringContainsString('Starts Friday, November 20, 2026 at 3:00 PM', $html);
    }

    public function test_the_static_countdown_shows_the_venue_time_not_utc(): void
    {
        $event = $this->event();

        $html = view('events.invitations.sections.countdown', [
            'event' => $event,
            'invitation' => ['effects' => ['countdown_enabled' => false]],
        ])->render();

        $this->assertStringContainsString('Friday, November 20, 2026 at 3:00 PM', $html);
    }

    public function test_the_no_script_stylesheet_swaps_the_ticker_for_the_sentence(): void
    {
        $css = file_get_contents(public_path('css/events-invitation.css'));

        $this->assertMatchesRegularExpression('/\.evt-inv-countdown-nojs\s*\{[^}]*display:\s*none;/', $css);
        $this->assertMatchesRegularExpression('/html:not\(\.js\)\s+\.evt-inv-countdown-nojs\s*\{[^}]*display:\s*block;/', $css);
        $this->assertMatchesRegularExpression('/html:not\(\.js\)\s+\[data-inv-countdown\]\s+\.evt-inv-countdown-grid[^{]*\{[^}]*display:\s*none;/', $css);
    }

    // ── <noscript> note ──────────────────────────────────────────────────────

    public function test_the_noscript_note_appears_only_when_something_needs_scripts(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('inv/photo.webp', 'x');

        $withGallery = $this->event(['slug' => 'with-gallery']);
        $withGallery->forceFill(['invitation_customization' => ['media' => ['gallery' => ['inv/photo.webp']]]])->save();
        $plain = $this->event(['slug' => 'plain-one']);

        $this->get(route('events.public', $withGallery->slug))->assertOk()->assertSee('need JavaScript');
        $this->get(route('events.public', $plain->slug))->assertOk()->assertDontSee('need JavaScript');
    }

    // ── Phase 2: RSVP works without JavaScript ───────────────────────────────

    private function guest(string $token = 'tok_nojs', bool $plusOne = false): Guest
    {
        $event = $this->event(['slug' => 'nojs-'.$token, 'allow_plus_one' => true]);

        return Guest::factory()->for($event)->create(['invitation_token' => $token, 'plus_one_allowed' => $plusOne]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonAttendingStatuses(): array
    {
        return ['declined' => ['declined'], 'maybe' => ['maybe']];
    }

    /**
     * @dataProvider nonAttendingStatuses
     */
    public function test_a_form_without_js_that_posts_the_default_count_still_saves_a_non_attending_answer(string $status): void
    {
        Notification::fake();
        $guest = $this->guest();

        // What the browser sends when rsvp-form.js never reset the dropdown to 0.
        $this->post(route('rsvp.token.store', ['token' => 'tok_nojs']), ['status' => $status, 'attendee_count' => 1])
            ->assertSessionHasNoErrors();

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame($status, $rsvp->status->value);
        $this->assertSame(0, $rsvp->attendee_count, 'stored as zero seats whatever was sent');
    }

    /**
     * @dataProvider nonAttendingStatuses
     */
    public function test_the_count_may_be_left_out_or_oversized_for_a_non_attending_answer(string $status): void
    {
        Notification::fake();
        $guest = $this->guest();

        $this->post(route('rsvp.token.store', ['token' => 'tok_nojs']), ['status' => $status])->assertSessionHasNoErrors();
        $this->assertSame(0, $guest->fresh()->rsvp->attendee_count);

        $this->post(route('rsvp.token.store', ['token' => 'tok_nojs']), ['status' => $status, 'attendee_count' => 9])->assertSessionHasNoErrors();
        $this->assertSame(0, $guest->fresh()->rsvp->attendee_count);
    }

    public function test_declining_after_accepting_with_a_plus_one_frees_both_seats(): void
    {
        Notification::fake();
        $guest = $this->guest(plusOne: true);
        $guest->event->update(['guest_limit' => 5]);
        Rsvp::factory()->forGuest($guest)->accepted(2)->create();

        $this->post(route('rsvp.token.store', ['token' => 'tok_nojs']), ['status' => 'declined', 'attendee_count' => 2])->assertSessionHasNoErrors();

        $this->assertSame(0, $guest->fresh()->rsvp->attendee_count);
        $this->assertSame(0, EventAttendance::heldSeats($guest->event_id));
    }

    public function test_accepting_still_needs_a_valid_count(): void
    {
        Notification::fake();
        $this->guest();

        $this->post(route('rsvp.token.store', ['token' => 'tok_nojs']), ['status' => 'accepted', 'attendee_count' => 0])->assertSessionHasErrors('attendee_count');
        $this->post(route('rsvp.token.store', ['token' => 'tok_nojs']), ['status' => 'accepted', 'attendee_count' => 2])->assertSessionHasErrors('attendee_count');
        $this->post(route('rsvp.token.store', ['token' => 'tok_nojs']), ['status' => 'accepted'])->assertSessionHasErrors('attendee_count');
    }

    public function test_the_open_form_and_the_api_accept_a_non_attending_answer_with_the_default_count(): void
    {
        Notification::fake();
        $event = $this->event(['slug' => 'open-nojs']);

        $this->post(route('rsvp.open.store', $event->slug), ['name' => 'Sam Open', 'email' => 'sam@example.com', 'phone' => '0977123456', 'status' => 'declined', 'attendee_count' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, Rsvp::query()->whereHas('guest', fn ($q) => $q->where('email', 'sam@example.com'))->value('attendee_count'));

        $guest = Guest::factory()->for($event)->create(['invitation_token' => 'tok_api_nojs']);
        $this->postJson(route('api.v1.rsvp.token.store', ['token' => 'tok_api_nojs']), ['status' => 'maybe', 'attendee_count' => 1])
            ->assertOk()
            ->assertJsonPath('rsvp.attendee_count', 0);

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => 'tok_api_nojs']), ['status' => 'accepted', 'attendee_count' => 5])->assertUnprocessable();
        $this->assertSame('maybe', $guest->fresh()->rsvp->status->value);
    }

    public function test_the_form_hint_says_the_count_only_counts_when_attending(): void
    {
        $this->guest(plusOne: true);

        $this->get(route('rsvp.token.show', 'tok_nojs'))->assertOk()->assertSee('Only counted when you are attending.');
    }

    // ── Phase 3: our own libraries, only when needed ─────────────────────────

    private function eventWithGallery(string $slug): Event
    {
        Storage::fake('public');
        Storage::disk('public')->put('inv/photo.webp', 'x');
        $event = $this->event(['slug' => $slug]);
        $event->forceFill(['invitation_customization' => ['media' => ['gallery' => ['inv/photo.webp']]]])->save();

        return $event->fresh();
    }

    public function test_a_gallery_page_loads_the_libraries_from_our_server_and_not_a_cdn(): void
    {
        $event = $this->eventWithGallery('gallery-local');

        $html = $this->get(route('events.public', $event->slug))->assertOk()->getContent();

        foreach (['vendor/swiper/swiper-bundle.min.css', 'vendor/swiper/swiper-bundle.min.js', 'vendor/glightbox/glightbox.min.css', 'vendor/glightbox/glightbox.min.js'] as $asset) {
            $this->assertStringContainsString($asset, $html);
        }
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);

        // Deferred scripts run in document order: the libraries must come before the script that uses them.
        $this->assertLessThan(strpos($html, 'js/invitation-public.js'), strpos($html, 'vendor/swiper/swiper-bundle.min.js'));
        $this->assertLessThan(strpos($html, 'js/invitation-public.js'), strpos($html, 'vendor/glightbox/glightbox.min.js'));
    }

    public function test_a_page_without_a_gallery_loads_neither_library(): void
    {
        $event = $this->event(['slug' => 'no-gallery']);

        $html = $this->get(route('events.public', $event->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString('swiper', strtolower($html));
        $this->assertStringNotContainsString('glightbox', strtolower($html));
    }

    public function test_the_personal_rsvp_page_uses_the_same_local_libraries(): void
    {
        $event = $this->eventWithGallery('gallery-token');
        Guest::factory()->for($event)->create(['invitation_token' => 'tok_gallery']);

        $html = $this->get(route('rsvp.token.show', 'tok_gallery'))->assertOk()->getContent();

        $this->assertStringContainsString('vendor/swiper/swiper-bundle.min.js', $html);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
    }

    public function test_the_vendored_files_exist_and_are_the_pinned_versions(): void
    {
        $this->assertStringContainsString('Swiper 11.2.10', file_get_contents(public_path('vendor/swiper/swiper-bundle.min.js')));
        $this->assertStringContainsString('Swiper 11.2.10', file_get_contents(public_path('vendor/swiper/swiper-bundle.min.css')));
        $this->assertGreaterThan(10000, filesize(public_path('vendor/glightbox/glightbox.min.js')));
        $this->assertGreaterThan(1000, filesize(public_path('vendor/glightbox/glightbox.min.css')));
        $this->assertStringNotContainsString('sourceMappingURL', file_get_contents(public_path('vendor/swiper/swiper-bundle.min.js')));
    }

    public function test_the_gallery_is_a_plain_grid_without_js_or_when_the_library_fails(): void
    {
        $css = file_get_contents(public_path('css/events-invitation.css'));
        $this->assertMatchesRegularExpression('/html:not\(\.js\)\s+\.evt-inv-gallery-swiper\s+\.swiper-wrapper,\s*\.evt-inv-gallery--static\s+\.evt-inv-gallery-swiper\s+\.swiper-wrapper\s*\{[^}]*display:\s*grid;/', $css);
        $this->assertMatchesRegularExpression('/\.evt-inv-gallery--static\s+\.swiper-pagination\s*\{[^}]*display:\s*none;/', $css);

        $js = file_get_contents(public_path('js/invitation-public.js'));
        $this->assertStringContainsString("wrap.classList.add('evt-inv-gallery--static')", $js);
    }

    public function test_fonts_are_one_request_with_the_font_host_preconnected(): void
    {
        $html = Blade::render(
            "@include('events.invitations.partials.google-fonts', ['invitation' => \$invitation])@stack('head')",
            ['invitation' => ['theme' => ['google_font_families' => ['Lato:wght@400', 'Jost:wght@300;400']]]],
        );

        // One request for all the families: a stylesheet link (loaded without blocking) and its <noscript> copy.
        $this->assertSame(2, substr_count($html, 'fonts.googleapis.com/css2'));
        $this->assertSame(1, substr_count($html, '<noscript>'));
        $this->assertStringContainsString("media=\"print\" onload=\"this.media='all'\"", $html);
        $this->assertStringContainsString('family=Lato:wght@400&family=Jost:wght@300;400&display=swap', html_entity_decode($html));
        $this->assertStringContainsString('<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>', $html);

        $none = Blade::render("@include('events.invitations.partials.google-fonts', ['invitation' => \$invitation])@stack('head')", ['invitation' => ['theme' => ['google_font_families' => []]]]);
        $this->assertStringNotContainsString('fonts.googleapis.com', $none);
    }

    public function test_the_unsplash_preconnect_is_dropped_from_real_invitations_only(): void
    {
        $event = $this->event(['slug' => 'no-unsplash']);

        $this->get(route('events.public', $event->slug))->assertOk()->assertDontSee('images.unsplash.com');
        $this->get('/')->assertOk()->assertSee('images.unsplash.com');
    }

    // ── Phase 4: heavy media waits for the guest ─────────────────────────────

    private function heroVideo(array $vars = []): string
    {
        $event = $this->event(['slug' => 'hero-video-'.Str::random(8)]);

        return view('events.invitations.partials.hero-video', array_merge([
            'event' => $event,
            'videoRaw' => null,
            'videoEmbedSrc' => null,
            'videoFilePath' => null,
        ], $vars))->render();
    }

    public function test_a_video_file_does_not_load_or_play_with_the_page(): void
    {
        $html = $this->heroVideo(['videoFilePath' => 'invitation-video/clip.mp4']);

        $this->assertStringContainsString('<video', $html);
        $this->assertStringContainsString('preload="none"', $html);
        $this->assertStringContainsString('data-inv-video', $html);
        $this->assertStringContainsString('poster=', $html);
        // autoplay would override preload="none" and fetch the file anyway.
        $this->assertDoesNotMatchRegularExpression('/\sautoplay[\s=>]/', $html);
    }

    public function test_a_youtube_background_is_a_placeholder_with_a_plain_link_and_no_iframe(): void
    {
        $embed = InvitationVideoBackground::embedUrl('dQw4w9WgXcQ');
        $html = $this->heroVideo(['videoRaw' => 'youtube:dQw4w9WgXcQ', 'videoEmbedSrc' => $embed]);

        $this->assertStringNotContainsString('<iframe', $html, 'the player is not in the initial HTML');
        $this->assertStringContainsString('data-inv-video-embed', $html);
        $this->assertStringContainsString('data-embed-src="'.e($embed).'"', $html);
        $this->assertStringContainsString('href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function test_layout_class_hooks_are_passed_through(): void
    {
        $html = $this->heroVideo([
            'videoFilePath' => 'invitation-video/clip.mp4',
            'videoClass' => 'bfa-hero-video',
            'scrimClass' => 'bfa-hero-video-scrim',
        ]);

        $this->assertStringContainsString('class="bfa-hero-video"', $html);
        $this->assertStringContainsString('class="bfa-hero-video-scrim"', $html);

        $embed = $this->heroVideo(['videoRaw' => 'youtube:dQw4w9WgXcQ', 'videoEmbedSrc' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'embedClass' => 'bfa-hero-video-embed']);
        $this->assertStringContainsString('evt-inv-hero-video-embed bfa-hero-video-embed', $embed);
    }

    public function test_the_three_heroes_use_the_shared_partial_and_no_longer_autoplay_or_embed_inline(): void
    {
        foreach ([
            'sections/hero.blade.php',
            'layouts/pro_magazine/sections/hero.blade.php',
            'layouts/beauty_for_ashes/sections/hero.blade.php',
        ] as $file) {
            $source = file_get_contents(resource_path('views/events/invitations/'.$file));

            $this->assertStringContainsString("partials.hero-video'", $source, $file);
            $this->assertStringNotContainsString('<iframe', $source, $file);
            $this->assertStringNotContainsString('<video', $source, $file);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function videoHeroLayouts(): array
    {
        return [
            'pro magazine' => [InvitationLayoutVariant::PRO_MAGAZINE],
            'beauty for ashes' => [InvitationLayoutVariant::BEAUTY_FOR_ASHES],
        ];
    }

    /**
     * @dataProvider videoHeroLayouts
     */
    public function test_a_real_invitation_page_renders_its_hero_video_through_the_partial(string $variant): void
    {
        $template = InvitationTemplate::query()->where('layout_variant', $variant)->first();
        $this->assertNotNull($template, "a {$variant} template is seeded");

        Storage::fake('public');
        Storage::disk('public')->put('invitation-video/clip.mp4', 'x');
        $file = $this->event(['slug' => 'real-file-'.$variant, 'invitation_template_id' => $template->id]);
        $file->forceFill(['invitation_customization' => ['effects' => ['video_background' => 'invitation-video/clip.mp4']]])->save();
        $youtube = $this->event(['slug' => 'real-yt-'.$variant, 'invitation_template_id' => $template->id]);
        $youtube->forceFill(['invitation_customization' => ['effects' => ['video_background' => 'youtube:dQw4w9WgXcQ']]])->save();

        $fileHtml = $this->get(route('events.public', $file->slug))->assertOk()->getContent();
        $ytHtml = $this->get(route('events.public', $youtube->slug))->assertOk()->getContent();

        $this->assertStringContainsString('preload="none"', $fileHtml);
        $this->assertDoesNotMatchRegularExpression('/<video[^>]*\sautoplay[\s=>]/', $fileHtml);
        $this->assertStringNotContainsString('<iframe', $ytHtml);
        $this->assertStringContainsString('data-inv-video-embed', $ytHtml);
    }

    public function test_the_script_starts_media_only_on_a_normal_connection_and_otherwise_offers_a_button(): void
    {
        $js = file_get_contents(public_path('js/invitation-public.js'));

        $this->assertStringContainsString('function mayStartMedia()', $js);
        $this->assertStringContainsString('function connectionQuality()', $js);
        $this->assertStringContainsString('saveData', $js);
        $this->assertStringContainsString("'2g'", $js);
        $this->assertStringContainsString("'slow-2g'", $js);
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        $this->assertStringContainsString("button.className = 'evt-inv-video-play'", $js);
        $this->assertStringContainsString('initHeroMedia(root);', $js);
    }

    public function test_the_plain_link_shows_only_without_scripts_or_when_they_stall(): void
    {
        $css = file_get_contents(public_path('css/events-invitation.css'));

        $this->assertMatchesRegularExpression('/html\.js\s+\.evt-inv-video-link\s*\{[^}]*display:\s*none;/', $css);
        $this->assertMatchesRegularExpression('/html\.js\.js-stalled\s+\.evt-inv-video-link\s*\{[^}]*display:\s*inline-flex;/', $css);
    }
}
