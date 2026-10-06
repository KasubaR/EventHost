<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan: plans/invitation-page-compatibility.md Phase 4. Guest pages draw their icons from public/css/guest-icons.css (built
 * from the SVGs in resources/icons/fa) and load web fonts without blocking, so neither a slow third-party host nor a blocked one
 * can hold up the first paint. Pages that are not guest pages keep the full Font Awesome CDN stylesheet.
 */
class GuestIconsTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function guestSources(): array
    {
        $views = resource_path('views');
        $files = [
            "{$views}/events/public.blade.php",
            "{$views}/events/preview.blade.php",
            "{$views}/events/invitation-status.blade.php",
            "{$views}/templates/preview.blade.php",
            "{$views}/components/event-host-bar.blade.php",
            app_path('Enums/PublicInvitationStatus.php'),
            public_path('js/invitation-public.js'),
        ];
        foreach (['events/invitations', 'rsvp'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$views}/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /** @return array<string, true> icon names (fa-xyz) that have a rule in guest-icons.css */
    private function covered(): array
    {
        preg_match_all('/\.fa-(?:solid|regular|brands)\.(fa-[a-z0-9-]+)\s*\{/', file_get_contents(public_path('css/guest-icons.css')), $m);

        return array_fill_keys($m[1], true);
    }

    public function test_the_committed_stylesheet_matches_the_svgs(): void
    {
        $this->artisan('icons:build-guest-css', ['--check' => true])->assertExitCode(0);
    }

    public function test_every_svg_has_a_rule_and_the_licence_is_kept_with_them(): void
    {
        $covered = $this->covered();

        foreach (glob(resource_path('icons/fa/*/*.svg')) as $svg) {
            $this->assertArrayHasKey('fa-'.basename($svg, '.svg'), $covered, basename($svg).' has no rule');
        }

        $css = file_get_contents(public_path('css/guest-icons.css'));
        $this->assertStringContainsString('CC BY 4.0', $css, 'the attribution must stay in the generated file');
        $this->assertFileExists(resource_path('icons/fa/LICENSE.txt'));
    }

    public function test_every_icon_a_guest_page_uses_is_in_the_guest_set(): void
    {
        $covered = $this->covered();
        $missing = [];

        foreach ($this->guestSources() as $file) {
            preg_match_all('/\bfa-[a-z0-9-]+/', file_get_contents($file), $m);
            foreach (array_unique($m[0]) as $token) {
                if (in_array($token, ['fa-solid', 'fa-regular', 'fa-brands'], true)) {
                    continue;
                }
                if (! isset($covered[$token])) {
                    $missing[$token][] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }

        $report = collect($missing)->map(fn ($files, $icon) => $icon.' (in '.$files[0].')')->implode(', ');
        $this->assertSame([], $missing, "Add an SVG to resources/icons/fa and run icons:build-guest-css for: {$report}");
    }

    public function test_the_footer_icons_are_covered_so_footer_showing_guest_pages_can_drop_the_cdn(): void
    {
        $covered = $this->covered();

        foreach (['fa-whatsapp', 'fa-facebook-f', 'fa-instagram', 'fa-linkedin-in', 'fa-x-twitter', 'fa-envelope', 'fa-location-dot'] as $icon) {
            $this->assertArrayHasKey($icon, $covered, "{$icon} is used by the site footer");
        }
    }

    public function test_a_guest_page_uses_local_icons_and_non_blocking_fonts(): void
    {
        $event = Event::factory()->for(User::factory()->create())->published()->create(['slug' => 'icons-party', 'rsvp_deadline' => null]);

        $html = $this->get(route('events.public', $event->slug))->assertOk()->getContent();

        $this->assertStringContainsString('css/guest-icons.css', $html);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $html);
        // The web fonts do not block first paint: media=print until loaded, with a plain <noscript> copy.
        $this->assertStringContainsString("media=\"print\" onload=\"this.media='all'\"", $html);
        $this->assertMatchesRegularExpression('/<noscript><link[^>]*fonts\.googleapis\.com[^>]*><\/noscript>/', $html);
    }

    public function test_the_open_rsvp_page_which_shows_the_footer_also_uses_local_icons(): void
    {
        $event = Event::factory()->for(User::factory()->create())->published()->create(['slug' => 'footer-party', 'rsvp_deadline' => null]);

        $html = $this->get(route('rsvp.open.show', $event->slug))->assertOk()->getContent();

        $this->assertStringContainsString('css/guest-icons.css', $html);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $html);
    }

    public function test_a_page_that_is_not_a_guest_page_keeps_the_full_icon_stylesheet(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('cdnjs.cloudflare.com/ajax/libs/font-awesome', $html);
        $this->assertStringNotContainsString('css/guest-icons.css', $html);
        $this->assertStringNotContainsString("media=\"print\" onload=\"this.media='all'\"", $html);
    }
}
