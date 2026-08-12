<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventGalleryController extends Controller
{
    public function show(string $slug): View
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        if (! $event->is_public) {
            abort(403);
        }

        $isLive = $event->photoWallIsLive();

        $photos = $isLive
            ? $event->photos()->approved()->orderByDesc('id')->limit(60)->get()
            : collect();

        return view('events.gallery.show', compact('event', 'photos', 'isLive'));
    }

    public function feed(Request $request, string $slug): JsonResponse
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_public', true)
            ->firstOrFail();

        if (! $event->photoWallIsLive()) {
            return response()->json(['photos' => []]);
        }

        $afterId = (int) $request->query('after_id', 0);

        $photos = $event->photos()
            ->approved()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(20)
            ->get();

        return response()->json([
            'photos' => $photos->map(fn (EventPhoto $photo) => [
                'id' => $photo->id,
                'thumbnail_url' => $photo->thumbnail_url,
                'uploader_name' => $photo->uploader_name,
            ]),
        ]);
    }
}
