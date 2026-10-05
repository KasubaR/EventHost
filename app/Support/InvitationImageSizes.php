<?php

namespace App\Support;

/**
 * The upload size shown as a hint beside each image field on the design form.
 * Sizes follow what the server keeps — the cover is cropped to 1200×630
 * (InvitationMediaStager::storeCover()); portraits are scaled to 900px wide and gallery
 * photos to 1200px wide (ProcessInvitationDesignImageJob) — and the shape of the frame
 * each layout draws them in.
 */
final class InvitationImageSizes
{
    private const COVER = [1200, 630, 'landscape'];

    private const PORTRAIT_3_4 = [900, 1200, 'portrait, 3:4'];

    private const PORTRAIT_4_5 = [800, 1000, 'portrait, 4:5'];

    private const PORTRAIT_SQUARE = [900, 900, 'square'];

    private const GALLERY_4_3 = [1200, 900, 'landscape, 4:3'];

    private const GALLERY_SQUARE = [1200, 1200, 'square'];

    /** @var array<string, array<string, array{0: int, 1: int, 2: string}>> */
    private const SIZES = [
        InvitationLayoutVariant::WEDDING_INVITATION => [
            'cover' => self::COVER,
            'couple' => self::PORTRAIT_3_4,
            'gallery' => self::GALLERY_4_3,
        ],
        InvitationLayoutVariant::WEDDING_INVITATION_NOIR => [
            'cover' => self::COVER,
            'couple' => self::PORTRAIT_3_4,
            'gallery' => self::GALLERY_4_3,
        ],
        InvitationLayoutVariant::MODERN_MINIMAL => [
            'cover' => self::COVER,
            'couple' => self::PORTRAIT_3_4,
            'gallery' => self::GALLERY_4_3,
        ],
        InvitationLayoutVariant::WEDDING_MIDNIGHT_GOLD => [
            'cover' => self::COVER,
            'couple' => self::PORTRAIT_SQUARE,
            'gallery' => self::GALLERY_SQUARE,
        ],
        InvitationLayoutVariant::WEDDING_DUSTY_BLUE => [
            'cover' => self::COVER,
            'couple' => self::PORTRAIT_SQUARE,
            'gallery' => self::GALLERY_SQUARE,
        ],
        InvitationLayoutVariant::PRO_MAGAZINE => [
            'cover' => self::COVER,
            'gallery' => self::GALLERY_4_3,
        ],
        InvitationLayoutVariant::BOTANICAL_GRADUATION => [
            'couple' => self::PORTRAIT_4_5,
            'gallery' => self::GALLERY_4_3,
        ],
        InvitationLayoutVariant::BEAUTY_FOR_ASHES => [
            'speaker' => self::PORTRAIT_3_4,
        ],
    ];

    /** Layouts that show the landscape cover in a tall frame, so its sides are lost. */
    private const COVER_CROPPED_TALL = [
        InvitationLayoutVariant::WEDDING_INVITATION_NOIR,
        InvitationLayoutVariant::MODERN_MINIMAL,
    ];

    /**
     * @param  'cover'|'couple'|'speaker'|'gallery'  $slot
     * @return array{0: int, 1: int, 2: string}|null width, height, shape
     */
    public static function for(?string $variant, string $slot): ?array
    {
        return self::SIZES[InvitationLayoutVariant::normalize($variant)][$slot] ?? null;
    }

    /**
     * The frame's orientation, for the uploader's client-side "this will be cropped" warning.
     *
     * @param  'cover'|'couple'|'speaker'|'gallery'  $slot
     * @return 'portrait'|'landscape'|'square'|null
     */
    public static function orientation(?string $variant, string $slot): ?string
    {
        $size = self::for($variant, $slot);

        if ($size === null) {
            return null;
        }

        return match ($size[0] <=> $size[1]) {
            -1 => 'portrait',
            1 => 'landscape',
            default => 'square',
        };
    }

    /**
     * @param  'cover'|'couple'|'speaker'|'gallery'  $slot
     */
    public static function hint(?string $variant, string $slot): ?string
    {
        $size = self::for($variant, $slot);

        if ($size === null) {
            return null;
        }

        [$width, $height, $shape] = $size;
        $hint = "Recommended size: {$width} × {$height} px ({$shape}).";

        if ($slot === 'cover' && in_array(InvitationLayoutVariant::normalize($variant), self::COVER_CROPPED_TALL, true)) {
            $hint .= ' Keep faces near the centre. The sides are cropped.';
        }

        return $hint;
    }
}
