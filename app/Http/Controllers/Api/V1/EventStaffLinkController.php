<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StaffLinkResource;
use App\Models\Event;
use App\Models\EventStaffLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * JSON sibling of App\Http\Controllers\EventStaffLinkController (Slice D). Applies
 * to both invitation and ticketed events, same as the web controller — the link
 * itself is generic (event_id/token/label). Adds an index() the web controller has
 * no equivalent of: the web scan page renders $event->staffLinks() server-side, the
 * API needs an explicit list endpoint for the app to show existing links.
 */
class EventStaffLinkController extends Controller
{
    public function index(Event $event): AnonymousResourceCollection
    {
        $this->authorize('update', $event);

        return StaffLinkResource::collection($event->staffLinks()->get());
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        abort_unless($event->ownerHasPremiumEventTools(), 403);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $label = trim((string) ($validated['label'] ?? ''));

        $link = $event->staffLinks()->create([
            'label' => $label !== '' ? $label : null,
        ]);

        return response()->json(['link' => new StaffLinkResource($link)], 201);
    }

    public function destroy(Event $event, EventStaffLink $link): JsonResponse
    {
        abort_unless($link->event_id === $event->id, 404);
        $this->authorize('delete', $link);

        $link->delete();

        return response()->json(['message' => 'Scanner link revoked.']);
    }
}
