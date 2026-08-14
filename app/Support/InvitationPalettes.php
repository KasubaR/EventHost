<?php

namespace App\Support;

final class InvitationPalettes
{
    public const MODE_LIGHT = 'light';

    public const MODE_DARK = 'dark';

    /**
     * Pre-vetted colour trios offered in the invitation design form.
     *
     * Free-form hex was replaced by this catalogue because nothing validated the
     * *combination* of the three colours — a host could pick a trio that rendered
     * text invisible on their public invitation.
     *
     * Each layout stylesheet derives its local tokens from --evt-primary/accent/background
     * with a baked-in light or dark assumption (modern-minimal mixes toward `white`,
     * noir mixes toward the background), so a palette is only safe for templates of a
     * matching `mode`. See {@see InvitationPaletteTest} for the enforced constraints.
     *
     * @var array<string, array{label: string, mode: string, primary: string, accent: string, background: string}>
     */
    public const PALETTES = [
        'ivory-gold' => [
            'label' => 'Ivory & Gold',
            'mode' => self::MODE_LIGHT,
            'primary' => '#2c2520',
            'accent' => '#b8965a',
            'background' => '#faf6f0',
        ],
        'modern-mono' => [
            'label' => 'Modern Mono',
            'mode' => self::MODE_LIGHT,
            'primary' => '#1a1a1a',
            'accent' => '#c0a080',
            'background' => '#ffffff',
        ],
        'slate-sky' => [
            'label' => 'Slate & Sky',
            'mode' => self::MODE_LIGHT,
            'primary' => '#1e293b',
            'accent' => '#0ea5e9',
            'background' => '#ffffff',
        ],
        'magazine-red' => [
            'label' => 'Magazine Red',
            'mode' => self::MODE_LIGHT,
            'primary' => '#1c1917',
            'accent' => '#dc2626',
            'background' => '#fafaf9',
        ],
        'botanical-blush' => [
            'label' => 'Botanical Blush',
            'mode' => self::MODE_LIGHT,
            'primary' => '#2c2420',
            'accent' => '#c4847a',
            'background' => '#fdfaf6',
        ],
        'blush-rosewood' => [
            'label' => 'Blush & Rosewood',
            'mode' => self::MODE_LIGHT,
            'primary' => '#5a1a20',
            'accent' => '#e8b4b8',
            'background' => '#f0e6e6',
        ],
        'sage-ivory' => [
            'label' => 'Sage & Ivory',
            'mode' => self::MODE_LIGHT,
            'primary' => '#22312a',
            'accent' => '#7c9a80',
            'background' => '#f4f7f2',
        ],
        'navy-coral' => [
            'label' => 'Navy & Coral',
            'mode' => self::MODE_LIGHT,
            'primary' => '#172340',
            'accent' => '#d9654a',
            'background' => '#fbfaf7',
        ],
        'noir-gold' => [
            'label' => 'Noir & Gold',
            'mode' => self::MODE_DARK,
            'primary' => '#f5eedc',
            'accent' => '#c8973f',
            'background' => '#0d0b09',
        ],
        'midnight-silver' => [
            'label' => 'Midnight & Silver',
            'mode' => self::MODE_DARK,
            'primary' => '#e8ecf2',
            'accent' => '#9fb4d0',
            'background' => '#10141c',
        ],
        'plum-rose' => [
            'label' => 'Plum & Rose',
            'mode' => self::MODE_DARK,
            'primary' => '#f4e9ee',
            'accent' => '#d98fa8',
            'background' => '#1e1018',
        ],
    ];

    /**
     * @return array<string, array{label: string, mode: string, primary: string, accent: string, background: string}>
     */
    public static function all(): array
    {
        return self::PALETTES;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::PALETTES);
    }

    /**
     * @return array{label: string, mode: string, primary: string, accent: string, background: string}|null
     */
    public static function get(string $key): ?array
    {
        return self::PALETTES[trim($key)] ?? null;
    }

    /**
     * Palettes safe for a template of the given mode, preserving catalogue order.
     *
     * @return array<string, array{label: string, mode: string, primary: string, accent: string, background: string}>
     */
    public static function forMode(string $mode): array
    {
        $mode = self::normalizeMode($mode);

        return array_filter(self::PALETTES, static fn (array $p): bool => $p['mode'] === $mode);
    }

    public static function normalizeMode(?string $mode): string
    {
        return trim((string) $mode) === self::MODE_DARK ? self::MODE_DARK : self::MODE_LIGHT;
    }

    /**
     * Exact reverse lookup for a stored trio, used to pre-select the current palette
     * in the design form. Returns null when the stored colours predate this catalogue.
     */
    public static function matchKey(string $primary, string $accent, string $background): ?string
    {
        $needle = [
            'primary' => strtolower(trim($primary)),
            'accent' => strtolower(trim($accent)),
            'background' => strtolower(trim($background)),
        ];

        foreach (self::PALETTES as $key => $palette) {
            if ($palette['primary'] === $needle['primary']
                && $palette['accent'] === $needle['accent']
                && $palette['background'] === $needle['background']) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Which palette set a template belongs to, derived from its own background colour.
     */
    public static function modeForBackground(string $background): string
    {
        return self::luminance($background) >= 0.5 ? self::MODE_LIGHT : self::MODE_DARK;
    }

    /**
     * First palette of the given mode — the fallback selection when a stored trio
     * matches nothing in the catalogue.
     */
    public static function defaultKeyForMode(string $mode): string
    {
        $keys = array_keys(self::forMode($mode));

        return $keys[0] ?? array_key_first(self::PALETTES);
    }

    /**
     * WCAG 2.1 relative luminance of an #rrggbb colour (0 = black, 1 = white).
     */
    public static function luminance(string $hex): float
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return 1.0;
        }

        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * WCAG 2.1 contrast ratio between two #rrggbb colours (1.0 – 21.0).
     */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        $lighter = max($la, $lb);
        $darker = min($la, $lb);

        return ($lighter + 0.05) / ($darker + 0.05);
    }
}
