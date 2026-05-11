<?php

namespace App\Jobs;

use App\Models\Event;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Throwable;

class ProcessInvitationDesignImageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /**
     * @param  'gallery'|'hero_portrait'|'couple'  $target
     */
    public function __construct(
        public int $eventId,
        public string $originalRelativePath,
        public string $target,
    ) {}

    public function uniqueId(): string
    {
        return 'inv-design-img:'.$this->eventId.':'.$this->target.':'.$this->originalRelativePath;
    }

    public function handle(): void
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($this->originalRelativePath)) {
            return;
        }

        DB::transaction(function () use ($disk): void {
            $event = Event::lockForUpdate()->find($this->eventId);
            if ($event === null) {
                return;
            }

            $customization = is_array($event->invitation_customization) ? $event->invitation_customization : [];
            $media = is_array($customization['media'] ?? null) ? $customization['media'] : [];
            $media['gallery'] = is_array($media['gallery'] ?? null) ? $media['gallery'] : [];
            $media['couple_photos'] = is_array($media['couple_photos'] ?? null) ? $media['couple_photos'] : [];

            $stillReferenced = match ($this->target) {
                'gallery' => in_array($this->originalRelativePath, $media['gallery'], true),
                'hero_portrait' => ($media['hero_portrait'] ?? null) === $this->originalRelativePath,
                'couple' => in_array($this->originalRelativePath, $media['couple_photos'], true),
                default => false,
            };

            if (! $stillReferenced) {
                return;
            }

            $binary = $disk->get($this->originalRelativePath);
            if ($binary === null || $binary === '') {
                return;
            }

            $manager = extension_loaded('imagick')
                ? ImageManager::imagick()
                : ImageManager::gd();
            $image = $manager->read($binary);

            $maxWidth = match ($this->target) {
                'gallery' => 1200,
                'hero_portrait' => 1100,
                'couple' => 900,
                default => 1200,
            };

            $image->scaleDown(width: $maxWidth);
            $webp = $image->toWebp(85);

            $entropy = str_replace('.', '_', uniqid('', true));
            $newPath = match ($this->target) {
                'gallery' => 'invitation-gallery/'.$this->eventId.'/gal_'.$entropy.'.webp',
                'hero_portrait' => 'invitation-hero/'.$this->eventId.'/hp_'.$entropy.'.webp',
                'couple' => 'invitation-couple/'.$this->eventId.'/cp_'.$entropy.'.webp',
                default => 'invitation-gallery/'.$this->eventId.'/gal_'.$entropy.'.webp',
            };

            $disk->put($newPath, $webp->toString());

            match ($this->target) {
                'gallery' => $this->swapGalleryPath($media, $newPath),
                'hero_portrait' => $media['hero_portrait'] = $newPath,
                'couple' => $this->swapCouplePath($media, $newPath),
                default => null,
            };

            $customization['media'] = $media;
            $event->invitation_customization = $customization;
            $event->save();

            $disk->delete($this->originalRelativePath);
        });
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function swapGalleryPath(array &$media, string $newPath): void
    {
        $gallery = $media['gallery'] ?? [];
        $updated = [];
        foreach ($gallery as $path) {
            $pathStr = (string) $path;
            $updated[] = $pathStr === $this->originalRelativePath ? $newPath : $pathStr;
        }
        $media['gallery'] = array_values($updated);
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function swapCouplePath(array &$media, string $newPath): void
    {
        $couple = $media['couple_photos'] ?? [];
        $updated = [];
        foreach ($couple as $path) {
            $pathStr = (string) $path;
            $updated[] = $pathStr === $this->originalRelativePath ? $newPath : $pathStr;
        }
        $media['couple_photos'] = array_values($updated);
    }

    public function failed(?Throwable $exception): void
    {
        // Original file remains so guests still see the uploaded image until retry succeeds.
    }
}
