<?php

namespace App\Support;

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
}
