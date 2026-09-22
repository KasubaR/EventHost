<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PhotoStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EventPhotoModerationResource;
use App\Models\Event;
use App\Models\EventPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * JSON sibling of App\Http\Controllers\EventPhotoController (Slice E,
 * host-side moderation). The guest-facing feed lives on
 * Api\V1\EventGalleryController (Slice B3) — separate controller, same
 * "guest read vs host manage" split every other Slice C/D area already uses.
 */
class EventPhotoController extends Controller
{
    public function index(Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['message' => 'This event is not on a premium plan.'], 403);
        }

        $photos = $event->photos()
            ->with('table')
            ->orderByDesc('created_at')
            ->paginate(40);

        return response()->json([
            'photos' => EventPhotoModerationResource::collection($photos->items()),
            'stats' => [
                'total' => $event->photos()->count(),
                'pending' => $event->photos()->where('status', PhotoStatus::Pending)->count(),
                'hidden' => $event->photos()->where('status', PhotoStatus::Hidden)->count(),
            ],
            'meta' => [
                'current_page' => $photos->currentPage(),
                'last_page' => $photos->lastPage(),
                'total' => $photos->total(),
            ],
        ]);
    }

    public function update(Request $request, Event $event, EventPhoto $photo): JsonResponse
    {
        abort_unless($photo->event_id === $event->id, 404);
        $this->authorize('update', $photo);

        $status = $request->string('status')->toString();
        abort_unless(in_array($status, ['approved', 'hidden'], true), 422);

        $photo->update(['status' => $status]);

        return response()->json(['photo' => new EventPhotoModerationResource($photo)]);
    }

    public function destroy(Event $event, EventPhoto $photo): JsonResponse
    {
        abort_unless($photo->event_id === $event->id, 404);
        $this->authorize('delete', $photo);

        $path = $photo->path;
        $thumbnailPath = $photo->thumbnail_path;
        $table = $photo->table;

        $photo->delete();

        if ($table !== null && $table->photos_count > 0) {
            $table->decrement('photos_count');
        }

        Storage::disk('public')->delete([$path, $thumbnailPath]);

        return response()->json(['message' => 'Photo deleted.']);
    }
}
