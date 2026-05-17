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
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::MAP);
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
