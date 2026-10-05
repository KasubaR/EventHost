<?php

namespace App\Support;

use App\Models\Event;
use App\Services\InvitationCustomizationService;
use Illuminate\Support\Facades\Storage;

/**
 * Which files an invitation points at no longer exist on disk (deleted by hand, lost in a restore).
 * Guests never see a broken image: hide() drops the reference from what the page renders. The host is told
 * through missing(), computed on view and never stored, so re-uploading clears it.
 * plans/invitation-page-edge-cases.md Phase 4.
 *
 * Absolute URLs and root-relative paths are not checked: template preview sample events stage Unsplash
 * URLs in these same arrays (see InvitationMediaUrl).
 */
final class InvitationMediaHealth
{
    public static function present(string $reference): bool
    {
        if ($reference === ''
            || str_starts_with($reference, 'http://')
            || str_starts_with($reference, 'https://')
            || str_starts_with($reference, '/')) {
            return true;
        }

        return Storage::disk('public')->exists($reference);
    }

    /**
     * What the host has saved that is no longer on disk.
     *
     * @return list<array{slot: string, label: string, path: string}>
     */
    public static function missing(Event $event): array
    {
        if ($event->isTicketed()) {
            return [];
        }

        $missing = [];

        // The cover has its own fallback (Event::hasCoverImage()), so guests see the default image;
        // the host still needs to know their cover is gone.
        if (filled($event->cover_image) && ! $event->hasCoverImage()) {
            $missing[] = ['slot' => 'cover', 'label' => 'Cover image', 'path' => (string) $event->cover_image];
        }

        $stored = app(InvitationCustomizationService::class)->storedCustomization($event);
        $media = InvitationCustomizationService::storedMedia($stored);
        $effects = is_array($stored['effects'] ?? null) ? $stored['effects'] : [];

        foreach ($media['gallery'] as $path) {
            if (! self::present($path)) {
                $missing[] = ['slot' => 'gallery', 'label' => 'Gallery photo', 'path' => $path];
            }
        }

        if ($media['hero_portrait'] !== null && ! self::present($media['hero_portrait'])) {
            $missing[] = ['slot' => 'hero_portrait', 'label' => 'Hero portrait', 'path' => $media['hero_portrait']];
        }

        foreach ($media['couple_photos'] as $path) {
            if ($path !== '' && ! self::present($path)) {
                $missing[] = ['slot' => 'couple_photos', 'label' => 'Photo', 'path' => $path];
            }
        }

        $video = $effects['video_background'] ?? null;
        if (is_string($video) && InvitationVideoBackground::isFilePath($video) && ! self::present($video)) {
            $missing[] = ['slot' => 'video', 'label' => 'Background video', 'path' => $video];
        }

        $audio = $effects['audio_track'] ?? null;
        if (is_string($audio) && $audio !== '' && ! self::present($audio)) {
            $missing[] = ['slot' => 'audio', 'label' => 'Background music', 'path' => $audio];
        }

        return $missing;
    }

    /**
     * The merged invitation as guests should see it: every reference to a missing file removed, so no
     * layout needs its own check. A section whose only images are gone (the gallery) renders nothing.
     *
     * @param  array<string, mixed>  $invitation  InvitationCustomizationService::merge() output
     * @return array<string, mixed>
     */
    public static function hide(array $invitation): array
    {
        $media = is_array($invitation['media'] ?? null) ? $invitation['media'] : [];

        $media['gallery'] = array_values(array_filter(
            (array) ($media['gallery'] ?? []),
            static fn ($path) => is_string($path) && self::present($path),
        ));

        $hero = $media['hero_portrait'] ?? null;
        if (is_string($hero) && ! self::present($hero)) {
            $media['hero_portrait'] = null;
        }

        // Positional layouts (Beauty for Ashes) keep a blank in the slot so each photo stays bound to its
        // speaker; the others are a plain list.
        $couple = (array) ($media['couple_photos'] ?? []);
        $positional = InvitationLayoutVariant::normalize($invitation['layout_variant'] ?? null) === InvitationLayoutVariant::BEAUTY_FOR_ASHES;
        $couple = array_map(static fn ($path) => is_string($path) && self::present($path) ? $path : '', $couple);
        $media['couple_photos'] = $positional ? $couple : array_values(array_filter($couple, static fn ($p) => $p !== ''));

        $invitation['media'] = $media;

        $effects = is_array($invitation['effects'] ?? null) ? $invitation['effects'] : [];
        $video = $effects['video_background'] ?? null;
        if (is_string($video) && InvitationVideoBackground::isFilePath($video) && ! self::present($video)) {
            $effects['video_background'] = null;
        }
        $audio = $effects['audio_track'] ?? null;
        if (is_string($audio) && $audio !== '' && ! self::present($audio)) {
            $effects['audio_track'] = null;
        }
        $invitation['effects'] = $effects;

        return $invitation;
    }
}
