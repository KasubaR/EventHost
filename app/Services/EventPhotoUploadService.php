<?php

namespace App\Services;

use App\Enums\PhotoStatus;
use App\Models\Event;
use App\Models\EventPhoto;
use App\Models\EventTable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class EventPhotoUploadService
{
    /**
     * Re-encodes an anonymous guest upload to WebP (also strips EXIF/GPS metadata — privacy,
     * don't leak a guest's location off their phone) and stores a display copy + grid thumbnail
     * on the public disk, same pattern as EventController::storeCoverImage().
     */
    public function store(
        Event $event,
        ?EventTable $table,
        UploadedFile $file,
        ?string $uploaderName,
        string $ipHash,
    ): EventPhoto {
        $manager = extension_loaded('imagick')
            ? ImageManager::imagick()
            : ImageManager::gd();

        // Intervention Image auto-corrects EXIF rotation while decoding.
        $image = $manager->read($file->getRealPath());

        $display = (clone $image)->scaleDown(1600, 1600);
        $thumbnail = (clone $image)->cover(400, 400);

        $id = uniqid('photo_', true);
        $path = 'event-photos/'.$id.'.webp';
        $thumbnailPath = 'event-photos/thumbs/'.$id.'.webp';

        Storage::disk('public')->put($path, $display->toWebp(85)->toString());
        Storage::disk('public')->put($thumbnailPath, $thumbnail->toWebp(80)->toString());

        $status = $event->photo_wall_requires_approval ? PhotoStatus::Pending : PhotoStatus::Approved;

        try {
            return DB::transaction(function () use ($event, $table, $path, $thumbnailPath, $uploaderName, $ipHash, $status): EventPhoto {
                $photo = EventPhoto::create([
                    'event_id' => $event->id,
                    'event_table_id' => $table?->id,
                    'path' => $path,
                    'thumbnail_path' => $thumbnailPath,
                    'uploader_name' => $uploaderName,
                    'status' => $status,
                    'ip_hash' => $ipHash,
                ]);

                if ($table !== null) {
                    $table->increment('photos_count');
                }

                return $photo;
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete([$path, $thumbnailPath]);
            throw $e;
        }
    }
}
