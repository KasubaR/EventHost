<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGuestGroupApiRequest;
use App\Http\Requests\Api\V1\UpdateGuestGroupApiRequest;
use App\Http\Resources\Api\V1\GuestGroupResource;
use App\Models\Event;
use App\Models\GuestGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * JSON sibling of App\Http\Controllers\GuestGroupController — authorizes via
 * GuestGroupPolicy/EventPolicy directly instead of the narrower owner-only
 * FormRequest checks (Slice C3 plan, design decision #1). Web controller untouched.
 */
class GuestGroupController extends Controller
{
    public function index(Event $event): AnonymousResourceCollection
    {
        $this->authorizeInvitation($event);

        return GuestGroupResource::collection($event->guestGroups()->withCount('guests')->get());
    }

    public function store(StoreGuestGroupApiRequest $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        abort_unless($event->isInvitation(), 404);

        $group = $event->guestGroups()->create([
            'name' => $request->validated()['name'],
        ]);

        if (isset($request->validated()['seat_limit'])) {
            $group->enableLink((int) $request->validated()['seat_limit']);
        }

        return response()->json(new GuestGroupResource($group), 201);
    }

    public function update(UpdateGuestGroupApiRequest $request, Event $event, GuestGroup $guest_group): JsonResponse
    {
        $guest_group->loadMissing('event');
        $this->authorize('update', $guest_group);
        abort_unless($guest_group->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        $guest_group->update([
            'name' => $request->validated()['name'],
        ]);

        $validated = $request->validated();

        if (array_key_exists('seat_limit', $validated)) {
            $validated['seat_limit'] === null
                ? $guest_group->disableLink()
                : $guest_group->enableLink((int) $validated['seat_limit']);
        }

        if (array_key_exists('rsvp_link_closed', $validated) && $guest_group->hasSeatPool()) {
            $guest_group->forceFill([
                'rsvp_link_closed_at' => $validated['rsvp_link_closed'] ? now() : null,
            ])->save();
        }

        return response()->json(new GuestGroupResource($guest_group->fresh()))->setStatusCode(200);
    }

    public function destroy(Event $event, GuestGroup $guest_group): JsonResponse
    {
        $guest_group->loadMissing('event');
        abort_unless($guest_group->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        $this->authorize('delete', $guest_group);

        $guest_group->delete();

        return response()->json(null, 204);
    }

    private function authorizeInvitation(Event $event): void
    {
        $this->authorize('update', $event);

        abort_unless($event->isInvitation(), 404);
    }
}
