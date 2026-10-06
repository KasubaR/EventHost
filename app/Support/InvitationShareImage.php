<?php

namespace App\Support;

use App\Models\Event;
use Illuminate\Support\Facades\Storage;

/**
 * The picture a link preview (WhatsApp, Facebook, X) shows for an invitation, and where its JPEG copy lives.
 *
 * Previews want a JPEG or PNG at about 1200×630; covers and gallery photos are stored as WebP, which these crawlers do not
 * reliably render. A JPEG of the chosen picture is written beside the event by GenerateEventShareImageJob, at
 * `invitation-share/{event_id}/{hash}.jpg`. The hash is in the name on purpose: previews are cached by image URL, so a changed
 * picture must be a new URL. The name is derived from the source file (path, size, modified time), so the page can compute it
 * without listing a directory or storing anything on the event. plans/invitation-page-compatibility.md Phase 5.
 */
final class InvitationShareImage
{
    public const DIRECTORY = 'invitation-share';

    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /**
     * The one definition of "which picture represents this event", shared by the page's meta tags and the job:
     * with a cover, the first gallery photo wins and the cover is the fallback; with no cover, the first couple photo, then the
     * hero portrait, then the first gallery photo. Only a stored file that exists counts. Null means "use the platform default".
     *
     * @param  array<string, mixed>  $media  the invitation's media (gallery, hero_portrait, couple_photos)
     */
    public static function sourcePath(Event $event, array $media): ?string
    {
        $gallery = self::firstUsable((array) ($media['gallery'] ?? []));

        if ($event->hasCoverImage()) {
            return $gallery ?? (string) $event->cover_image;
        }

        $couple = self::firstUsable((array) ($media['couple_photos'] ?? []));
        $hero = self::firstUsable([$media['hero_portrait'] ?? null]);

        return $couple ?? $hero ?? $gallery;
    }

    /** Where the JPEG for this source belongs. Null when the source is not on disk. */
    public static function pathFor(int $eventId, string $source): ?string
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($source)) {
            return null;
        }

        $hash = substr(md5($source.'|'.$disk->size($source).'|'.$disk->lastModified($source)), 0, 12);

        return self::DIRECTORY.'/'.$eventId.'/'.$hash.'.jpg';
    }

    /** The JPEG for this event's picture when it has been generated, else null (the page then falls back to the stored image). */
    public static function existingFor(Event $event, ?string $source): ?string
    {
        if ($source === null) {
            return null;
        }

        $path = self::pathFor((int) $event->getKey(), $source);

        return $path !== null && Storage::disk('public')->exists($path) ? $path : null;
    }

    public static function altText(Event $event): string
    {
        return (string) $event->name;
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private static function firstUsable(array $candidates): ?string
    {
        foreach ($candidates as $path) {
            if (! is_string($path) || $path === '' || str_contains($path, '://') || str_starts_with($path, '/')) {
                continue;
            }
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
