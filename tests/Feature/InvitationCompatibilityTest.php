<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Plan: plans/invitation-page-compatibility.md. Phase 1 — one failing step must not stop the rest of the page's
 * script, above all the reveals (a layout that hides its sections until they are revealed would stay blank).
 */
class InvitationCompatibilityTest extends TestCase
{
    public function test_a_failing_step_does_not_stop_the_reveals_or_the_steps_after_it(): void
    {
        if (! Process::run('node --version')->successful()) {
            $this->markTestSkipped('node is not installed; run `node tests/js/invitation-boot.cjs` where it is.');
        }

        $result = Process::path(base_path())->run('node tests/js/invitation-boot.cjs');

        $this->assertTrue($result->successful(), trim($result->errorOutput().$result->output()));
        $this->assertSame('ok', trim($result->output()));
    }

    // ── Phase 6: media where the connection cannot be measured ──

    public function test_media_starts_by_itself_only_where_it_should(): void
    {
        if (! Process::run('node --version')->successful()) {
            $this->markTestSkipped('node is not installed; run `node tests/js/invitation-media.cjs` where it is.');
        }

        $result = Process::path(base_path())->run('node tests/js/invitation-media.cjs');

        $this->assertTrue($result->successful(), trim($result->errorOutput().$result->output()));
        $this->assertSame('ok', trim($result->output()));
    }

    public function test_the_video_the_player_and_the_music_all_ask_the_same_rule(): void
    {
        $js = file_get_contents(public_path('js/invitation-public.js'));

        $this->assertStringContainsString('function mayStartMedia()', $js);
        $this->assertStringContainsString('if (mayStartMedia() && !reduceMotion) {', $js, 'the hero video and YouTube player');
        $this->assertStringContainsString('var autoStart = mayStartMedia();', $js, 'the music');
        $this->assertStringContainsString("audio.preload = autoStart ? 'auto' : 'none';", $js, 'nothing is fetched until the guest taps');
        $this->assertStringNotContainsString('connectionAllowsMedia', $js, 'the two-way rule is gone');
        // The audio object is created empty so the file is not fetched before its preload is set.
        $this->assertStringNotContainsString('new Audio(src)', $js);
    }

    public function test_the_in_app_hint_exists_on_the_invitation_and_the_thank_you_page(): void
    {
        $invitation = file_get_contents(public_path('js/invitation-public.js'));
        $thanks = file_get_contents(public_path('js/rsvp-thanks.js'));

        $this->assertStringContainsString('function initInAppHint(root)', $invitation);
        $this->assertStringContainsString("safely('in-app hint'", $invitation);
        $this->assertStringContainsString('rsvp-inapp-hint', $thanks);
        $this->assertStringContainsString('FBAN|FBAV|FB_IAB|Instagram', $thanks);
        $this->assertStringContainsString('.evt-inv-inapp-hint', file_get_contents(public_path('css/events-invitation.css')));
        $this->assertStringContainsString('.rsvp-inapp-hint', file_get_contents(public_path('css/rsvp-public.css')));
    }

    public function test_boot_isolates_every_step_and_reveals_before_anything_heavy(): void
    {
        $js = file_get_contents(public_path('js/invitation-public.js'));
        $boot = substr($js, (int) strpos($js, 'function boot()'));

        foreach (['wedding reveal', 'noir reveal', 'countdown', 'image fallbacks', 'hero media', 'audio', 'gallery', 'event invite lights', 'layout lightboxes'] as $step) {
            $this->assertStringContainsString("safely('{$step}'", $boot, "{$step} must run inside safely()");
        }

        // The reveals come first, then the page is marked ready, then the optional steps.
        $order = ['safely(\'wedding reveal\'', 'safely(\'noir reveal\'', 'markReady();', 'safely(\'countdown\'', 'safely(\'gallery\''];
        $last = -1;
        foreach ($order as $needle) {
            $position = strpos($boot, $needle);
            $this->assertNotFalse($position, $needle);
            $this->assertGreaterThan($last, $position, "{$needle} is out of order");
            $last = $position;
        }
    }

    // ── Phase 3: viewport-height heroes, print, and the no-JS calendar menu ──

    public function test_viewport_height_heroes_also_use_the_small_viewport_unit(): void
    {
        // iOS and in-app browsers (Facebook, WhatsApp) have toolbars that make 100vh taller than the visible screen.
        // The svh line comes after the vh one: an engine without svh drops it and keeps the vh line.
        $expect = [
            'events-invitation-layout-beauty-for-ashes.css' => 'min-height: 100vh;',
            'events-invitation-layout-event-invite.css' => 'min-height: 100vh;',
            'events-invitation-layout-modern-minimal.css' => 'min-height: 100vh;',
            'events-invitation-layout-wedding-invitation-noir.css' => 'height: 100vh;',
            'events-invitation-layout-wedding-invitation.css' => 'height: 100vh;',
            'events-invitation-layout-wedding-midnight-gold.css' => 'min-height: 88vh;',
            'events-public.css' => 'min-height: calc(100vh - 52px);',
            'rsvp-public.css' => 'min-height: calc(100vh - 56px);',
        ];

        foreach ($expect as $file => $declaration) {
            $css = file_get_contents(public_path('css/'.$file));
            $svh = str_replace('vh', 'svh', $declaration);

            $this->assertStringContainsString($declaration."\n    ".$svh, $css, "{$file}: {$declaration} must be followed by {$svh}");
        }

        $botanical = file_get_contents(public_path('css/events-invitation-layout-botanical-graduation.css'));
        $this->assertStringContainsString("min-height: min(92vh, 900px);\n    min-height: 92svh;\n    min-height: min(92svh, 900px);", $botanical);
    }

    public function test_the_print_stylesheet_undoes_what_only_makes_sense_on_a_screen(): void
    {
        $css = file_get_contents(public_path('css/events-invitation.css'));
        $print = substr($css, (int) strpos($css, '@media print'));

        $this->assertStringContainsString('@media print', $css);
        // Reveal-on-scroll sections are transparent until reached; a printout never scrolls.
        $this->assertMatchesRegularExpression('/\.wi-reveal,\s*\.evt-layout-wedding-invitation-noir \.wi2-reveal\s*\{[^}]*opacity:\s*1 !important/', $print);
        // Viewport-sized heroes become content-sized.
        foreach (['.wi-hero', '.wi2-hero', '.bfa-hero', '.mm-hero', '.mg-hero', '.hero'] as $hero) {
            $this->assertStringContainsString($hero, $print, "{$hero} must be released from its viewport height when printing");
        }
        $this->assertMatchesRegularExpression('/height:\s*auto !important;\s*min-height:\s*0 !important/', $print);
        // Moving or interactive extras do not print; the countdown becomes its sentence; the slider becomes a grid.
        foreach (['.evt-inv-audio-wrap', '.evt-inv-hero-video', '.evt-inv-video-play', '.swiper-button-next', '.swiper-slide-duplicate', '.evt-preview-banner'] as $hidden) {
            $this->assertStringContainsString($hidden, $print);
        }
        $this->assertMatchesRegularExpression('/\[data-inv-countdown\] \.evt-inv-countdown-grid\s*\{\s*display:\s*none !important/', $print);
        $this->assertMatchesRegularExpression('/\.evt-inv-countdown-nojs\s*\{\s*display:\s*block !important/', $print);
        $this->assertMatchesRegularExpression('/\.swiper-wrapper\s*\{[^}]*display:\s*grid !important/', $print);
    }

    public function test_the_thank_you_calendar_links_show_without_javascript(): void
    {
        $css = file_get_contents(public_path('css/rsvp-public.css'));

        $this->assertMatchesRegularExpression('/html:not\(\.js\) \.rsvp-thanks-menu\[hidden\]\s*\{[^}]*display:\s*flex;[^}]*position:\s*static/', $css);
        $this->assertMatchesRegularExpression('/html:not\(\.js\) \[data-rsvp-calendar-trigger\]\s*\{[^}]*display:\s*none/', $css);
    }

    public function test_the_script_avoids_string_methods_older_phones_lack(): void
    {
        $js = file_get_contents(public_path('js/invitation-public.js'));

        $this->assertStringNotContainsString('padStart', $js, 'String.prototype.padStart needs Chrome 57');
        // Plain ES5: it must still parse on an engine without arrow functions, const/let or template literals.
        $this->assertDoesNotMatchRegularExpression('/=>|\bconst\b|\blet\b|`/', $js);
    }
}
