<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Plan: plans/invitation-page-compatibility.md Phase 2. The guest-facing stylesheets lean on features old browsers lack
 * (color-mix, clamp/min/max, inset, aspect-ratio). A browser that drops a declaration it does not understand must still get
 * a usable page, so every such declaration needs a fallback placed BEFORE it: a browser that understands both takes the later
 * (modern) one, and an old one keeps the earlier plain value. Adding an unprotected declaration fails this test.
 *
 * Fallback rules the test enforces:
 *   color-mix()            same property, plain value, immediately before. Custom properties (--x) cannot fall back by
 *                          repeating, so they need an `@supports not (color: color-mix(...))` block that sets them.
 *   clamp() / min() / max() same property, fixed value, immediately before.
 *   inset                  not used; write top/right/bottom/left.
 *   aspect-ratio           a matching `@supports not (aspect-ratio: 1)` block in the same file.
 */
class GuestCssFallbacksTest extends TestCase
{
    /** @return list<string> */
    private function guestStylesheets(): array
    {
        $dir = public_path('css');

        return array_merge(
            ["{$dir}/events-invitation.css", "{$dir}/events-public.css", "{$dir}/rsvp-public.css", "{$dir}/events-invitation-section-nav.css"],
            glob("{$dir}/events-invitation-layout-*.css") ?: [],
        );
    }

    /**
     * Declarations with the one directly before them in the same rule, and the custom properties set inside
     * `@supports not (color: color-mix…)` blocks.
     *
     * @return array{declarations: list<array{prop: string, value: string, line: int, prev: ?array{prop: string, value: string}}>, supportsVars: array<string, true>, ratioBlocks: int}
     */
    private function parse(string $css): array
    {
        $lines = preg_split('/\R/', $css);
        $declarations = [];
        $supportsVars = [];
        $ratioBlocks = 0;
        $prev = null;
        $inComment = false;
        $supportsDepth = 0;
        $supportsStart = false;

        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $trim = trim($lines[$i]);

            if ($inComment) {
                $inComment = ! str_contains($trim, '*/');

                continue;
            }
            if (str_starts_with($trim, '/*')) {
                $inComment = ! str_contains($trim, '*/');

                continue;
            }

            if (str_starts_with($trim, '@supports not (color: color-mix')) {
                $supportsStart = true;
                $supportsDepth = 0;
            }
            if (str_starts_with($trim, '@supports not (aspect-ratio')) {
                $ratioBlocks++;
            }

            if (preg_match('/^(--[\w-]+|[a-z-]+):\s+(.*)$/i', $trim, $m) && ! str_ends_with($trim, '{')) {
                $text = $trim;
                $startLine = $i + 1;
                while ((substr_count($text, '(') > substr_count($text, ')') || ! str_contains($text, ';')) && $i + 1 < $n) {
                    $i++;
                    $text .= ' '.trim($lines[$i]);
                    if (str_contains($text, ';') && substr_count($text, '(') <= substr_count($text, ')')) {
                        break;
                    }
                }
                $value = trim(substr($text, strpos($text, ':') + 1), " ;\t");
                $declarations[] = ['prop' => strtolower($m[1]), 'value' => $value, 'line' => $startLine, 'prev' => $prev];
                if ($supportsStart && str_starts_with($m[1], '--')) {
                    $supportsVars[$m[1]] = true;
                }
                $prev = ['prop' => strtolower($m[1]), 'value' => $value];

                continue;
            }

            if ($supportsStart) {
                $supportsDepth += substr_count($trim, '{') - substr_count($trim, '}');
                if ($supportsDepth <= 0) {
                    $supportsStart = false;
                }
            }
            if (str_contains($trim, '{') || str_contains($trim, '}')) {
                $prev = null;
            }
        }

        return ['declarations' => $declarations, 'supportsVars' => $supportsVars, 'ratioBlocks' => $ratioBlocks];
    }

    private function hasFeature(string $value, string $pattern): bool
    {
        return (bool) preg_match($pattern, $value);
    }

    public function test_every_modern_declaration_has_a_fallback_before_it(): void
    {
        $mix = '/color-mix\(/i';
        $math = '/(?<![\w-])(clamp|min|max)\(/i';
        $problems = [];

        foreach ($this->guestStylesheets() as $file) {
            $name = basename($file);
            $parsed = $this->parse(file_get_contents($file));

            foreach ($parsed['declarations'] as $d) {
                foreach ([$mix => 'color-mix()', $math => 'clamp()/min()/max()'] as $pattern => $label) {
                    if (! $this->hasFeature($d['value'], $pattern)) {
                        continue;
                    }

                    if (str_starts_with($d['prop'], '--')) {
                        // A repeated custom property cannot fall back; it needs an @supports block (color-mix only).
                        if ($pattern === $mix && ! isset($parsed['supportsVars'][$d['prop']])) {
                            $problems[] = "{$name}:{$d['line']} {$d['prop']} uses {$label} but no @supports-not block sets it";
                        }

                        continue;
                    }

                    $p = $d['prev'];
                    if ($p === null || $p['prop'] !== $d['prop'] || $this->hasFeature($p['value'], $pattern)) {
                        $problems[] = "{$name}:{$d['line']} {$d['prop']} uses {$label} with no plain {$d['prop']} just before it";
                    }
                }
            }
        }

        $this->assertSame([], $problems, "Add a plain fallback line directly before each of these:\n".implode("\n", $problems));
    }

    public function test_inset_is_never_used_in_the_guest_stylesheets(): void
    {
        foreach ($this->guestStylesheets() as $file) {
            $this->assertDoesNotMatchRegularExpression('/^\s*inset\s*:/m', file_get_contents($file), basename($file).' uses inset: (Chrome 87+); write top/right/bottom/left');
        }
    }

    public function test_every_aspect_ratio_has_a_height_fallback_block(): void
    {
        $problems = [];

        foreach ($this->guestStylesheets() as $file) {
            $parsed = $this->parse(file_get_contents($file));
            $ratios = array_filter(
                $parsed['declarations'],
                fn (array $d) => $d['prop'] === 'aspect-ratio' && strtolower($d['value']) !== 'auto',
            );

            // Declarations inside the @supports-not blocks are min-height, so every aspect-ratio here is a real one.
            if (count($ratios) !== $parsed['ratioBlocks']) {
                $problems[] = basename($file).': '.count($ratios).' aspect-ratio declaration(s) but '.$parsed['ratioBlocks'].' @supports-not (aspect-ratio) block(s)';
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_the_chosen_rsvp_answer_is_shown_without_has(): void
    {
        $css = file_get_contents(public_path('css/rsvp-public.css'));

        $this->assertStringContainsString('.rsvp-radio input:checked + span::before', $css, 'the native radio is hidden; :has() alone leaves old browsers with no visible selection');
    }

    public function test_the_fallbacks_come_before_the_modern_value_not_after(): void
    {
        // A fallback written AFTER the modern declaration would win in every browser. Spot-check one of each kind.
        $css = file_get_contents(public_path('css/events-invitation.css'));

        $this->assertLessThan(
            strpos($css, 'font-size: clamp(1.25rem, 4vw, 1.75rem);'),
            strpos($css, 'font-size: 1.25rem;'),
            'the plain font-size must precede the clamp()',
        );
        $this->assertMatchesRegularExpression('/background: transparent;\s*background: color-mix\(in srgb, var\(--evt-accent, #1e47bb\) 12%, transparent\);/', $css);
    }
}
