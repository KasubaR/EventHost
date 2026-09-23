<?php

namespace App\Support;

final class InvitationLayoutVariant
{
    public const STANDARD = 'standard';

    public const PRO_MAGAZINE = 'pro_magazine';

    public const BOTANICAL_GRADUATION = 'botanical_graduation';

    public const BEAUTY_FOR_ASHES = 'beauty_for_ashes';

    public const EVENT_INVITE = 'event_invite';

    public const WEDDING_INVITATION = 'wedding_invitation';

    public const WEDDING_INVITATION_NOIR = 'wedding_invitation_noir';

    public const MODERN_MINIMAL = 'modern_minimal';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::STANDARD, self::PRO_MAGAZINE, self::BOTANICAL_GRADUATION, self::BEAUTY_FOR_ASHES, self::EVENT_INVITE, self::WEDDING_INVITATION, self::WEDDING_INVITATION_NOIR, self::MODERN_MINIMAL];
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
            self::EVENT_INVITE => ['gallery', 'countdown', 'details', 'description', 'story', 'schedule'],
            self::WEDDING_INVITATION => ['countdown', 'schedule'],
            self::WEDDING_INVITATION_NOIR => ['countdown', 'details'],
            self::MODERN_MINIMAL => ['description', 'schedule'],
            default => [],
        };
    }

    /**
     * Whether this layout renders a countdown section at all. The homepage
     * markets "countdown timer" as a Pro feature, but countdown isn't a
     * standalone gate anywhere in code — it only ever appears because a
     * template's layout happens to include the section. See
     * InvitationTemplate's saving guard, which is what actually keeps that
     * promise true: any layout this returns true for requires at least Pro.
     */
    public static function hasCountdownSection(string $variant): bool
    {
        return ! in_array('countdown', self::blockedSections($variant), true);
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
            self::EVENT_INVITE => 'events-invitation-layout-event-invite.css',
            self::WEDDING_INVITATION => 'events-invitation-layout-wedding-invitation.css',
            self::WEDDING_INVITATION_NOIR => 'events-invitation-layout-wedding-invitation-noir.css',
            self::MODERN_MINIMAL => 'events-invitation-layout-modern-minimal.css',
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
            self::PRO_MAGAZINE, self::BOTANICAL_GRADUATION, self::BEAUTY_FOR_ASHES, self::EVENT_INVITE, self::WEDDING_INVITATION, self::WEDDING_INVITATION_NOIR, self::MODERN_MINIMAL => 'hero',
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
            self::WEDDING_INVITATION => 3,
            default => 0,
        };
    }

    /**
     * How many gallery images this layout can usefully show. Botanical and
     * Modern Minimal mosaics are five tiles; other layouts use the platform cap.
     */
    public static function maxGalleryImages(?string $variant): int
    {
        return match (self::normalize($variant)) {
            self::BOTANICAL_GRADUATION, self::MODERN_MINIMAL => 5,
            default => InvitationMediaRules::GALLERY_MAX,
        };
    }

    /**
     * Whether this layout should collect a host Cover Image on the edit page.
     * Modern Minimal and Event Invite never render cover in the invitation.
     * Botanical Blush uses up to two hero portraits instead — cover was only a
     * redundant single-frame fallback / share image. Beauty for Ashes uses a
     * CSS hero and four speaker portraits — cover is not the invitation photo path.
     */
    public static function usesCoverImage(?string $variant): bool
    {
        return ! in_array(self::normalize($variant), [
            self::MODERN_MINIMAL,
            self::EVENT_INVITE,
            self::BOTANICAL_GRADUATION,
            self::BEAUTY_FOR_ASHES,
        ], true);
    }
}
