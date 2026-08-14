<?php

namespace Tests\Unit\Support;

use App\Support\InvitationPalettes;
use Tests\TestCase;

class InvitationPalettesTest extends TestCase
{
    /**
     * The catalogue is the only thing standing between a host and an unreadable
     * invitation, so every trio must clear the thresholds the layout stylesheets
     * assume. Adding a palette that fails here means shipping invisible text.
     */
    public function test_every_palette_meets_its_mode_contrast_constraints(): void
    {
        foreach (InvitationPalettes::all() as $key => $palette) {
            $primary = $palette['primary'];
            $accent = $palette['accent'];
            $background = $palette['background'];

            $primaryOnBackground = InvitationPalettes::contrast($primary, $background);
            $accentOnBackground = InvitationPalettes::contrast($accent, $background);
            $backgroundLuminance = InvitationPalettes::luminance($background);

            $this->assertGreaterThanOrEqual(
                7.0,
                $primaryOnBackground,
                "[$key] primary on background must clear 7:1 — it carries all body and heading text."
            );

            if ($palette['mode'] === InvitationPalettes::MODE_LIGHT) {
                $this->assertGreaterThanOrEqual(
                    0.5,
                    $backgroundLuminance,
                    "[$key] light palettes need a light background — layout CSS lifts surfaces with color-mix(..., white)."
                );

                // Buttons fill with --evt-primary and hardcode `color: #fff`
                // (e.g. .ei-rsvp-btn in events-invitation-layout-event-invite.css).
                $this->assertGreaterThanOrEqual(
                    4.5,
                    InvitationPalettes::contrast('#ffffff', $primary),
                    "[$key] white button labels sit on primary, so primary must stay dark enough."
                );

                $this->assertGreaterThanOrEqual(
                    1.4,
                    $accentOnBackground,
                    "[$key] accent is decorative, but must not vanish into the background."
                );

                continue;
            }

            $this->assertLessThan(
                0.5,
                $backgroundLuminance,
                "[$key] dark palettes need a dark background — noir-style layouts dim tokens toward it."
            );

            $this->assertGreaterThan(
                $backgroundLuminance,
                InvitationPalettes::luminance($primary),
                "[$key] dark palettes invert: primary is the light ink on a dark background."
            );

            $this->assertGreaterThanOrEqual(
                4.5,
                $accentOnBackground,
                "[$key] on dark backgrounds the accent is load-bearing for headings and rules."
            );
        }
    }

    public function test_catalogue_offers_both_modes(): void
    {
        $this->assertNotEmpty(InvitationPalettes::forMode(InvitationPalettes::MODE_LIGHT));
        $this->assertNotEmpty(InvitationPalettes::forMode(InvitationPalettes::MODE_DARK));
    }

    public function test_for_mode_only_returns_palettes_of_that_mode(): void
    {
        foreach ([InvitationPalettes::MODE_LIGHT, InvitationPalettes::MODE_DARK] as $mode) {
            foreach (InvitationPalettes::forMode($mode) as $key => $palette) {
                $this->assertSame($mode, $palette['mode'], "[$key] leaked into the $mode set.");
            }
        }
    }

    public function test_match_key_round_trips_a_catalogue_trio(): void
    {
        $palette = InvitationPalettes::get('ivory-gold');

        $this->assertNotNull($palette);
        $this->assertSame('ivory-gold', InvitationPalettes::matchKey(
            $palette['primary'],
            $palette['accent'],
            $palette['background'],
        ));
    }

    public function test_match_key_is_case_insensitive(): void
    {
        $this->assertSame('ivory-gold', InvitationPalettes::matchKey('#2C2520', '#B8965A', '#FAF6F0'));
    }

    public function test_match_key_returns_null_for_colours_outside_the_catalogue(): void
    {
        $this->assertNull(InvitationPalettes::matchKey('#123456', '#abcdef', '#fedcba'));
    }

    public function test_mode_for_background_splits_on_luminance(): void
    {
        $this->assertSame(InvitationPalettes::MODE_LIGHT, InvitationPalettes::modeForBackground('#ffffff'));
        $this->assertSame(InvitationPalettes::MODE_LIGHT, InvitationPalettes::modeForBackground('#faf6f0'));
        $this->assertSame(InvitationPalettes::MODE_DARK, InvitationPalettes::modeForBackground('#0d0b09'));
        $this->assertSame(InvitationPalettes::MODE_DARK, InvitationPalettes::modeForBackground('#1a003a'));
    }

    public function test_default_key_for_mode_returns_a_palette_of_that_mode(): void
    {
        foreach ([InvitationPalettes::MODE_LIGHT, InvitationPalettes::MODE_DARK] as $mode) {
            $key = InvitationPalettes::defaultKeyForMode($mode);

            $this->assertSame($mode, InvitationPalettes::get($key)['mode']);
        }
    }

    public function test_get_returns_null_for_unknown_key(): void
    {
        $this->assertNull(InvitationPalettes::get('no-such-palette'));
    }

    public function test_contrast_matches_known_wcag_anchors(): void
    {
        $this->assertEqualsWithDelta(21.0, InvitationPalettes::contrast('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, InvitationPalettes::contrast('#777777', '#777777'), 0.01);
    }
}
