<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Resolves a media reference stored in invitation customization (gallery,
 * hero_portrait, couple_photos) to a browser-loadable URL.
 *
 * Real uploads always land here as a storage-relative path written by
 * InvitationMediaStager, so the common case is prefixing `storage/`. Template
 * preview sample events (see InvitationTemplate::previewSampleEvent()) stage
 * absolute Unsplash URLs in these same arrays instead — there is no upload to
 * scope a storage path to for a sample event — so an already-absolute (or
 * root-relative) reference is returned as-is rather than double-prefixed.
 */
class InvitationMediaUrl
{
    public static function resolve(?string $pathOrUrl): ?string
    {
        if ($pathOrUrl === null || $pathOrUrl === '') {
            return null;
        }

        if (str_starts_with($pathOrUrl, 'http://')
            || str_starts_with($pathOrUrl, 'https://')
            || str_starts_with($pathOrUrl, '/')) {
            return $pathOrUrl;
        }

        return asset('storage/'.$pathOrUrl);
    }

    /** Width of the small gallery copy; the stored one is at most LARGE_WIDTH (ProcessInvitationDesignImageJob). */
    public const SMALL_WIDTH = 600;

    public const LARGE_WIDTH = 1200;

    /** What the layouts' gallery grids and slider show at each viewport: three, two, then one photo across. */
    public const DEFAULT_SIZES = '(min-width: 880px) 33vw, (min-width: 560px) 50vw, 90vw';

    /**
     * Where the small copy of a gallery photo lives: the same name with "-600" before the extension. Pure naming, no
     * disk access; null for anything that is not a processed gallery WebP (couple photos, covers, originals).
     */
    public static function variantName(string $path): ?string
    {
        if (! str_starts_with($path, 'invitation-gallery/') || ! str_ends_with($path, '.webp') || str_ends_with($path, '-600.webp')) {
            return null;
        }

        return substr($path, 0, -5).'-600.webp';
    }

    /** The photo a "-600" file belongs to, or null when the path is not a small copy. */
    public static function parentOfVariant(string $path): ?string
    {
        if (! str_starts_with($path, 'invitation-gallery/') || ! str_ends_with($path, '-600.webp')) {
            return null;
        }

        return substr($path, 0, -9).'.webp';
    }

    /**
     * The small copy of a gallery photo when one is on disk, else null (photos saved before the small copies
     * existed, or ones narrower than SMALL_WIDTH, keep a single src).
     */
    public static function smallVariantPath(string $path): ?string
    {
        $variant = self::variantName($path);

        return $variant !== null && Storage::disk('public')->exists($variant) ? $variant : null;
    }

    /**
     * The given paths plus the small copy of each that exists, so deleting a photo never leaves its copy behind.
     *
     * @param  iterable<string>  $paths
     * @return list<string>
     */
    public static function withVariants(iterable $paths): array
    {
        $all = [];
        foreach ($paths as $path) {
            $all[] = $path;
            $variant = self::smallVariantPath($path);
            if ($variant !== null) {
                $all[] = $variant;
            }
        }

        return array_values(array_unique($all));
    }

    /**
     * ` srcset="… 600w, … 1200w" sizes="…"` for a gallery <img> that has a small copy, else an empty string.
     * Echo with {!! !!}: the values are escaped here.
     */
    public static function responsiveAttributes(string $path, string $sizes = self::DEFAULT_SIZES): string
    {
        $small = self::smallVariantPath($path);
        if ($small === null) {
            return '';
        }

        $srcset = self::resolve($small).' '.self::SMALL_WIDTH.'w, '.self::resolve($path).' '.self::LARGE_WIDTH.'w';

        return ' srcset="'.e($srcset).'" sizes="'.e($sizes).'"';
    }

    /**
     * @param  list<string>  $gallery  every gallery path, in display order
     */
    public static function galleryAlt(string $path, array $gallery, string $eventName): string
    {
        $index = array_search($path, $gallery, true);
        $number = $index === false ? 1 : $index + 1;

        return 'Photo '.$number.' of '.max(1, count($gallery)).' from '.$eventName;
    }

    /** For a photo with no caption of its own (couple, portrait, story): says whose it is. */
    public static function photoAlt(string $eventName): string
    {
        return 'Photo from '.$eventName;
    }
}
