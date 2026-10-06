<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\InvitationCustomizationService;
use App\Support\InvitationShareImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Writes the 1200×630 JPEG that WhatsApp, Facebook and X show in a link preview (see InvitationShareImage). Dispatched after
 * the cover or the invitation design changes (Event::saved), so it follows whatever picture the page would choose. It is
 * idempotent: the file name comes from the source, so an unchanged picture is a no-op. It never throws into a save; a failure
 * is logged and the page keeps using the stored image, as before.
 */
class GenerateEventShareImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        $event = Event::query()->find($this->eventId);
        if ($event === null || $event->isTicketed()) {
            return;
        }

        $disk = Storage::disk('public');

        try {
            $media = InvitationCustomizationService::storedMedia(app(InvitationCustomizationService::class)->storedCustomization($event));
            $source = InvitationShareImage::sourcePath($event, $media);

            // Nothing to show but the platform default: drop any stale copy, the page falls back by itself.
            if ($source === null) {
                $disk->deleteDirectory(InvitationShareImage::DIRECTORY.'/'.$event->id);

                return;
            }

            $target = InvitationShareImage::pathFor($event->id, $source);
            if ($target === null || $disk->exists($target)) {
                return;
            }

            $manager = extension_loaded('imagick') ? ImageManager::imagick() : ImageManager::gd();
            $image = $manager->read($disk->get($source))
                ->cover(InvitationShareImage::WIDTH, InvitationShareImage::HEIGHT);

            // cover() rounds, and can come out a pixel short; a preview should be exactly the advertised size.
            if ($image->width() !== InvitationShareImage::WIDTH || $image->height() !== InvitationShareImage::HEIGHT) {
                $image->resize(InvitationShareImage::WIDTH, InvitationShareImage::HEIGHT);
            }

            $disk->put($target, $image->toJpeg(82)->toString());

            // Previews are cached by URL, so older files are only clutter once the new one exists.
            foreach ($disk->files(InvitationShareImage::DIRECTORY.'/'.$event->id) as $file) {
                if ($file !== $target) {
                    $disk->delete($file);
                }
            }
        } catch (Throwable $e) {
            Log::warning('invitation.share_image_failed', ['event_id' => $this->eventId, 'exception_class' => $e::class]);
        }
    }
}
