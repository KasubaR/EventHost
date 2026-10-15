<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGuestGroupApiRequest;
use App\Http\Requests\Api\V1\UpdateGuestGroupApiRequest;
use App\Http\Resources\Api\V1\GuestGroupResource;
use App\Models\Event;
use App\Models\GuestGroup;
use App\Services\GuestGroupDeleter;
use App\Services\GuestGroupNamer;
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

    public function store(StoreGuestGroupApiRequest $request, Event $event, GuestGroupNamer $namer): JsonResponse
    {
        $this->authorize('update', $event);
        abort_unless($event->isInvitation(), 404);

        $group = $namer->create($event, $request->validated()['name']);

        if (isset($request->validated()['seat_limit'])) {
            $group->enableLink((int) $request->validated()['seat_limit']);
        }

        return response()->json(new GuestGroupResource($group), 201);
    }

    public function update(UpdateGuestGroupApiRequest $request, Event $event, GuestGroup $guest_group, GuestGroupNamer $namer): JsonResponse
    {
        $guest_group->loadMissing('event');
        $this->authorize('update', $guest_group);
        abort_unless($guest_group->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        $namer->rename($event, $guest_group, $request->validated()['name']);

        $validated = $request->validated();

        if (array_key_exists('seat_limit', $validated)) {
            $validated['seat_limit'] === null
                ? app(GuestGroupDeleter::class)->turnOffLink($event, $guest_group, $request->user()?->id)
                : $guest_group->enableLink((int) $validated['seat_limit']);
        }

        if (array_key_exists('rsvp_link_closed', $validated) && $guest_group->hasSeatPool()) {
            $guest_group->forceFill([
                'rsvp_link_closed_at' => $validated['rsvp_link_closed'] ? now() : null,
            ])->save();
        }

        return response()->json(new GuestGroupResource($guest_group->fresh()))->setStatusCode(200);
    }

    public function destroy(Event $event, GuestGroup $guest_group, GuestGroupDeleter $deleter): JsonResponse
    {
        $guest_group->loadMissing('event');
        abort_unless($guest_group->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        $this->authorize('delete', $guest_group);

        $deleter->delete($event, $guest_group, request()->user()?->id);

        return response()->json(null, 204);
    }

    private function authorizeInvitation(Event $event): void
    {
        $this->authorize('update', $event);

        abort_unless($event->isInvitation(), 404);
    }
}
