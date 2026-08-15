<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStagedMediaRequest;
use App\Models\Event;
use App\Models\StagedMedia;
use App\Support\InvitationMediaStager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Receives one file at a time, the moment the user picks it, so the save that
 * follows carries ids instead of megabytes.
 *
 * @see StagedMedia
 */
class EventInvitationMediaController extends Controller
{
    public function store(StoreStagedMediaRequest $request, Event $event): JsonResponse
    {
        $slot = (string) $request->input('slot');
        $file = $request->file('file');
        $userId = $request->user()->id;

        $path = InvitationMediaStager::store($file, $slot, $event->id);

        try {
            $staged = DB::transaction(function () use ($event, $userId, $slot, $path, $file): StagedMedia {
                // A single-value slot holds one file, so a second pick replaces the
                // first rather than piling up an orphan the pruner deals with a day later.
                if (StagedMedia::isSingleValueSlot($slot)) {
                    $superseded = StagedMedia::query()
                        ->ownedBy($event->id, $userId)
                        ->where('slot', $slot)
                        ->lockForUpdate()
                        ->get();

                    foreach ($superseded as $row) {
                        $row->delete();
                        DB::afterCommit(function () use ($row): void {
                            Storage::disk('public')->delete($row->path);
                        });
                    }
                }

                return StagedMedia::create([
                    'event_id' => $event->id,
                    'user_id' => $userId,
                    'slot' => $slot,
                    'path' => $path,
                    'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                    'bytes' => (int) $file->getSize(),
                ]);
            });
        } catch (\Throwable $e) {
            // The row is the only thing that makes the file findable — without it
            // the upload is already an orphan, so clean up now rather than waiting.
            Storage::disk('public')->delete($path);

            throw $e;
        }

        Log::info('invitation_media.staged', [
            'event_id' => $event->id,
            'user_id' => $userId,
            'slot' => $slot,
            'bytes' => $staged->bytes,
        ]);

        return response()->json([
            'id' => $staged->id,
            'slot' => $staged->slot,
            'url' => Storage::disk('public')->url($staged->path),
            'name' => $staged->original_name,
            'bytes' => $staged->bytes,
        ], 201);
    }

    /**
     * Backs the tile's remove button — a discarded pick should not sit on disk
     * until the pruner notices it.
     */
    public function destroy(Request $request, Event $event, int $staged): JsonResponse
    {
        $this->authorize('update', $event);

        $row = StagedMedia::query()
            ->ownedBy($event->id, $request->user()->id)
            ->whereKey($staged)
            ->first();

        // Already gone (double click, or consumed by a save in another tab) is a
        // success from the caller's point of view.
        if ($row === null) {
            return response()->json(['deleted' => false], 200);
        }

        $path = $row->path;
        $row->delete();
        Storage::disk('public')->delete($path);

        return response()->json(['deleted' => true], 200);
    }
}
