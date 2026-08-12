<?php

namespace App\Http\Controllers;

use App\Enums\PhotoStatus;
use App\Models\Event;
use App\Models\EventPhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class EventPhotoController extends Controller
{
    public function index(Event $event): View|RedirectResponse
    {
        $this->authorize('update', $event);

        if (! $event->ownerHasPremiumEventTools()) {
            return redirect()->route('billing.show')->with('status', 'premium-required-photos');
        }

        $photos = $event->photos()
            ->with('table')
            ->orderByDesc('created_at')
            ->paginate(40);

        $stats = [
            'total' => $event->photos()->count(),
            'pending' => $event->photos()->where('status', PhotoStatus::Pending)->count(),
            'hidden' => $event->photos()->where('status', PhotoStatus::Hidden)->count(),
        ];

        return view('events.photos.index', compact('event', 'photos', 'stats'));
    }

    public function update(Request $request, Event $event, EventPhoto $photo): RedirectResponse
    {
        abort_unless($photo->event_id === $event->id, 404);
        $this->authorize('update', $photo);

        $status = $request->string('status')->toString();
        abort_unless(in_array($status, ['approved', 'hidden'], true), 422);

        $photo->update(['status' => $status]);

        return back()->with('status', 'photo-updated');
    }

    public function destroy(Event $event, EventPhoto $photo): RedirectResponse
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

        return back()->with('status', 'photo-deleted');
    }
}
