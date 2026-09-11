<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EventPhotoResource;
use App\Http\Resources\Api\V1\PublicEventListResource;
use App\Services\PublicInvitationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * JSON sibling of App\Http\Controllers\EventGalleryController — no Blade view. feed() is
 * otherwise identical to web's (already pure JSON), swapping the inline map() for
 * EventPhotoResource::collection(). The web controller is untouched by this class.
 */
class EventGalleryController extends Controller
{
    public function show(string $slug, PublicInvitationResolver $resolver): JsonResponse|RedirectResponse
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

        return response()->json([
            'event' => new PublicEventListResource($event),
            'is_live' => $isLive,
            'photos' => EventPhotoResource::collection($photos),
        ]);
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
            'photos' => EventPhotoResource::collection($photos),
        ]);
    }
}
