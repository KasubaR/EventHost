<?php

namespace App\Support;

use App\Jobs\ProcessInvitationDesignImageJob;
use App\Models\StagedMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Writes an upload to its final resting place on the 'public' disk.
 *
 * Files are staged directly into the directory the save would have used, not into a
 * temp area, so consuming a staged row costs one string assignment and zero
 * filesystem work inside the save transaction — which is the whole point of moving
 * the upload earlier.
 *
 * Rasters are stored as-is; {@see ProcessInvitationDesignImageJob} converts
 * them to WebP after the save commits, exactly as it did when the binary arrived with
 * the form. The event cover is the exception — it is converted here, synchronously,
 * because it has always been converted synchronously and the user is now watching a
 * progress bar while it happens.
 */
final class InvitationMediaStager
{
    /**
     * @return array{0: string, 1: string} [directory, filename prefix]
     */
    public static function targetFor(string $slot, int $eventId): array
    {
        return match (true) {
            $slot === StagedMedia::SLOT_GALLERY => ['invitation-gallery/'.$eventId, 'gal_src_'],
            $slot === StagedMedia::SLOT_HERO_PORTRAIT => ['invitation-hero/'.$eventId, 'hero_src_'],
            $slot === StagedMedia::SLOT_COUPLE => ['invitation-couple/'.$eventId, 'couple_src_'],
            StagedMedia::isSpeakerSlot($slot) => ['invitation-couple/'.$eventId, 'couple_src_'],
            $slot === StagedMedia::SLOT_AUDIO => ['invitation-media/'.$eventId, 'audio_'],
            $slot === StagedMedia::SLOT_COVER => ['events', 'event_'],
            default => throw new \InvalidArgumentException('Unknown staging slot: '.$slot),
        };
    }

    public static function store(UploadedFile $file, string $slot, int $eventId): string
    {
        if ($slot === StagedMedia::SLOT_COVER) {
            return self::storeCover($file);
        }

        [$dir, $prefix] = self::targetFor($slot, $eventId);

        return self::storeOriginal($file, $dir, $prefix, $slot === StagedMedia::SLOT_AUDIO);
    }

    /**
     * Store the uploaded binary unchanged under a non-guessable name.
     */
    public static function storeOriginal(UploadedFile $file, string $dir, string $prefix, bool $allowAnyExtension = false): string
    {
        $ext = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg'));

        if (! $allowAnyExtension && ! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $ext = 'jpg';
        }

        if ($allowAnyExtension && $ext === '') {
            throw new \RuntimeException('Could not determine extension for uploaded media file.');
        }

        $entropy = str_replace('.', '_', uniqid('', true));

        return $file->storeAs($dir, $prefix.$entropy.'.'.$ext, 'public');
    }

    /**
     * Crop to the 1200×630 sharing ratio and write WebP, the shape every consumer
     * of Event::cover_image already expects.
     */
    public static function storeCover(UploadedFile $file): string
    {
        $manager = extension_loaded('imagick')
            ? ImageManager::imagick()
            : ImageManager::gd();

        $image = $manager->read($file->getRealPath());
        $image->cover(1200, 630);
        $webp = $image->toWebp(85);

        $path = 'events/'.uniqid('event_', true).'.webp';
        Storage::disk('public')->put($path, $webp->toString());

        return $path;
    }
}
