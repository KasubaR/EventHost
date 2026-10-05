<?php

namespace App\Support;

final class InvitationFonts
{
    /**
     * Value key => stack or Google font name for link href.
     *
     * @var array<string, array{font_stack: string, google_family?: string}>
     */
    public const MAP = [
        'system_ui' => [
            'font_stack' => 'system-ui, -apple-system, Segoe UI, sans-serif',
        ],
        'georgia' => [
            'font_stack' => 'Georgia, "Times New Roman", serif',
        ],
        'playfair' => [
            'font_stack' => '"Playfair Display", Georgia, serif',
            'google_family' => 'Playfair+Display:wght@400;700',
        ],
        'cormorant_garamond' => [
            'font_stack' => '"Cormorant Garamond", Georgia, "Times New Roman", serif',
            'google_family' => 'Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400;1,600',
        ],
        'inter' => [
            'font_stack' => 'Inter, system-ui, sans-serif',
            'google_family' => 'Inter:wght@400;600;700',
        ],
        'dm_sans' => [
            'font_stack' => '"DM Sans", system-ui, sans-serif',
            'google_family' => 'DM+Sans:wght@400;600;700',
        ],
        'lato' => [
            'font_stack' => 'Lato, system-ui, sans-serif',
            'google_family' => 'Lato:ital,wght@0,300;0,400;0,700;1,300;1,400',
        ],
        'libre_baskerville' => [
            'font_stack' => '"Libre Baskerville", Georgia, serif',
            'google_family' => 'Libre+Baskerville:ital,wght@0,400;0,700;1,400',
        ],
        'jost' => [
            'font_stack' => 'Jost, system-ui, sans-serif',
            'google_family' => 'Jost:wght@300;400;500',
        ],
        'montserrat' => [
            'font_stack' => 'Montserrat, system-ui, sans-serif',
            'google_family' => 'Montserrat:wght@400;600;700',
        ],
        'poppins' => [
            'font_stack' => 'Poppins, system-ui, sans-serif',
            'google_family' => 'Poppins:wght@400;600;700',
        ],
        'nunito' => [
            'font_stack' => 'Nunito, system-ui, sans-serif',
            'google_family' => 'Nunito:wght@400;600;700',
        ],
        'lora' => [
            'font_stack' => '"Lora", Georgia, serif',
            'google_family' => 'Lora:ital,wght@0,400;0,600;0,700;1,400',
        ],
        'cinzel' => [
            'font_stack' => '"Cinzel", Georgia, serif',
            'google_family' => 'Cinzel:wght@400;700;900',
        ],
        'dancing_script' => [
            'font_stack' => '"Dancing Script", cursive',
            'google_family' => 'Dancing+Script:wght@400;600;700',
        ],
        'great_vibes' => [
            'font_stack' => '"Great Vibes", cursive',
            'google_family' => 'Great+Vibes',
        ],
        'mrs_saint_delafield' => [
            'font_stack' => '"Mrs Saint Delafield", "Snell Roundhand", cursive',
            'google_family' => 'Mrs+Saint+Delafield',
        ],
        'bodoni_moda' => [
            'font_stack' => '"Bodoni Moda", Georgia, serif',
            'google_family' => 'Bodoni+Moda:ital,wght@0,400;0,600;1,400;1,600',
        ],
        'eb_garamond' => [
            'font_stack' => '"EB Garamond", Georgia, serif',
            'google_family' => 'EB+Garamond:ital,wght@0,300;0,400;1,300;1,400',
        ],
    ];

    /**
     * Keys still in MAP (so invitations using them keep rendering and saving) but no
     * longer offered in the font pickers. Retire a font here first; delete it from
     * MAP only once nothing stores it, and merge() then swaps in the template's font.
     *
     * @var list<string>
     */
    public const RETIRED = [];

    /**
     * Every key that renders and validates, retired ones included.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Keys a host may pick. $current (the event's saved key) stays in the list when
     * it is retired, so opening the form never silently changes the font.
     *
     * @return list<string>
     */
    public static function selectableKeys(?string $current = null): array
    {
        return array_values(array_filter(
            self::keys(),
            static fn (string $key): bool => ! in_array($key, self::RETIRED, true) || $key === $current
        ));
    }

    public static function exists(mixed $key): bool
    {
        return is_string($key) && array_key_exists(trim($key), self::MAP);
    }

    /**
     * $key when it is still in MAP, otherwise $fallback (the template's own font),
     * otherwise `system_ui`.
     */
    public static function resolve(mixed $key, mixed $fallback = null): string
    {
        if (self::exists($key)) {
            return trim($key);
        }

        return self::exists($fallback) ? trim($fallback) : 'system_ui';
    }

    /**
     * Return $key when defined in {@see MAP}; otherwise `system_ui` (aligned with {@see stack()} fallback).
     */
    public static function normalizeKey(string $key): string
    {
        $trimmed = trim($key);

        return ($trimmed !== '' && array_key_exists($trimmed, self::MAP))
            ? $trimmed
            : 'system_ui';
    }

    public static function stack(string $key): string
    {
        return self::MAP[$key]['font_stack'] ?? self::MAP['system_ui']['font_stack'];
    }

    /**
     * @return list<string>
     */
    public static function googleFamiliesNeeded(string $headingKey, string $bodyKey): array
    {
        $out = [];
        foreach ([self::normalizeKey($headingKey), self::normalizeKey($bodyKey)] as $key) {
            $google = self::MAP[$key]['google_family'] ?? null;
            if ($google !== null) {
                $out[] = $google;
            }
        }

        return array_values(array_unique($out));
    }
}
