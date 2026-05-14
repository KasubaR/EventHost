<?php

namespace App\Support;

final class InvitationLayoutVariant
{
    public const STANDARD = 'standard';

    public const PRO_MAGAZINE = 'pro_magazine';

    public const BOTANICAL_GRADUATION = 'botanical_graduation';

    public const BEAUTY_FOR_ASHES = 'beauty_for_ashes';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::STANDARD, self::PRO_MAGAZINE, self::BOTANICAL_GRADUATION, self::BEAUTY_FOR_ASHES];
    }

    public static function normalize(?string $value): string
    {
        $trimmed = trim((string) $value);

        return in_array($trimmed, self::keys(), true)
            ? $trimmed
            : self::STANDARD;
    }

    /**
     * Section types that are not available for this layout variant.
     * Sections in this list are never rendered, regardless of the per-event visibility toggle.
     *
     * @return list<string>
     */
    public static function blockedSections(string $variant): array
    {
        return match (self::normalize($variant)) {
            self::STANDARD => ['gallery', 'countdown'],
            self::BEAUTY_FOR_ASHES => ['countdown'],
            default => [],
        };
    }

    /**
     * Layout-specific CSS filename to push into the <head>, or null for the standard layout.
     * Add one entry here when introducing a new layout variant — no view changes needed.
     */
    public static function cssFile(string $variant): ?string
    {
        return match ($variant) {
            self::PRO_MAGAZINE => 'events-invitation-layout-pro-magazine.css',
            self::BOTANICAL_GRADUATION => 'events-invitation-layout-botanical-graduation.css',
            self::BEAUTY_FOR_ASHES => 'events-invitation-layout-beauty-for-ashes.css',
            default => null,
        };
    }

    /**
     * Section type that must always appear at index 0 for this variant, or null if order is free.
     * The hero section defines the outer page structure in layout-specific variants, so reordering
     * it below other sections would visually break the layout.
     */
    public static function pinnedFirst(string $variant): ?string
    {
        return match ($variant) {
            self::PRO_MAGAZINE, self::BOTANICAL_GRADUATION, self::BEAUTY_FOR_ASHES => 'hero',
            default => null,
        };
    }

    /**
     * Invitation-specific raster uploads (hero portrait + couple slots) are gated by the helpers below.
     *
     * To support another template later, raise maxInvitationHeroPortraitSlots / maxCouplePhotoSlots for that
     * variant here and adjust its Blade/CSS if needed — validation and pruning follow those counts.
     */
    /** Separate invitation hero portrait upload slots (0 or 1). Event cover is always the fallback when empty. */
    public static function maxInvitationHeroPortraitSlots(string $variant): int
    {
        return match (self::normalize($variant)) {
            self::BOTANICAL_GRADUATION => 1,
            default => 0,
        };
    }

    /** Optional couple / dual portrait slots in the hero area (layout-specific). */
    public static function maxCouplePhotoSlots(string $variant): int
    {
        return match (self::normalize($variant)) {
            self::BOTANICAL_GRADUATION => 2,
            self::BEAUTY_FOR_ASHES => 4,
            default => 0,
        };
    }
}
