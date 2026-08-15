<?php

namespace App\Support;

use App\Models\StagedMedia;

/**
 * One home for the upload rules, because they are now enforced twice: once when a
 * file is staged on pick, and again on the save that consumes it. Two copies of
 * "max:5120" would drift, and the drift would only show up as a file the staging
 * endpoint accepted and the save then rejected.
 */
final class InvitationMediaRules
{
    public const IMAGE_MIMES = 'jpeg,jpg,png,webp,gif';

    public const IMAGE_MAX_KB = 5120;

    public const AUDIO_MIMES = 'mp3,mpeg,ogg,wav';

    public const AUDIO_MAX_KB = 5120;

    /** The event cover is capped lower than invitation rasters — see UpdateEventRequest. */
    public const COVER_MIMES = 'jpeg,jpg,png,webp,gif';

    public const COVER_MAX_KB = 4096;

    public const GALLERY_MAX = 6;

    /**
     * @return list<string>
     */
    public static function imageRules(): array
    {
        return ['file', 'image', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::IMAGE_MAX_KB];
    }

    /**
     * @return list<string>
     */
    public static function audioRules(): array
    {
        return ['file', 'mimes:'.self::AUDIO_MIMES, 'max:'.self::AUDIO_MAX_KB];
    }

    /**
     * @return list<string>
     */
    public static function coverRules(): array
    {
        return ['file', 'image', 'mimes:'.self::COVER_MIMES, 'max:'.self::COVER_MAX_KB];
    }

    /**
     * @return list<string>
     */
    public static function rulesForSlot(string $slot): array
    {
        return match (true) {
            $slot === StagedMedia::SLOT_AUDIO => self::audioRules(),
            $slot === StagedMedia::SLOT_COVER => self::coverRules(),
            default => self::imageRules(),
        };
    }

    /**
     * Byte ceiling for one file in a slot, for the client-side pre-check.
     */
    public static function maxBytesForSlot(string $slot): int
    {
        return match (true) {
            $slot === StagedMedia::SLOT_AUDIO => self::AUDIO_MAX_KB * 1024,
            $slot === StagedMedia::SLOT_COVER => self::COVER_MAX_KB * 1024,
            default => self::IMAGE_MAX_KB * 1024,
        };
    }

    public static function acceptForSlot(string $slot): string
    {
        return match (true) {
            $slot === StagedMedia::SLOT_AUDIO => 'audio/mpeg,audio/mp3,audio/ogg,audio/wav',
            $slot === StagedMedia::SLOT_COVER => 'image/jpeg,image/png,image/webp',
            default => 'image/jpeg,image/png,image/webp,image/gif',
        };
    }
}
