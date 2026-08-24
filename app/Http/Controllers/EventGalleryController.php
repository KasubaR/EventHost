<?php

namespace App\Http\Controllers;

use App\Models\EventPhoto;
use App\Services\PublicInvitationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventGalleryController extends Controller
{
    public function show(string $slug, PublicInvitationResolver $resolver): View|RedirectResponse
    {
        $resolved = $resolver->resolveSibling($slug);

        if ($resolved instanceof RedirectResponse) {
            return $resolved;
        }

        $event = $resolved;
        $isLive = $event->photoWallIsLive();

        $photos = $isLive
            ? $event->photos()->approved()->orderByDesc('id')->limit(60)->get()
            : collect();

        return view('events.gallery.show', compact('event', 'photos', 'isLive'));
    }

    public function feed(Request $request, string $slug, PublicInvitationResolver $resolver): JsonResponse|RedirectResponse
    {
        $resolved = $resolver->resolveSibling($slug);

        if ($resolved instanceof RedirectResponse) {
            return $resolved;
        }

        $event = $resolved;

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
