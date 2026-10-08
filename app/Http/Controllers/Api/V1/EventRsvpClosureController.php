<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\EventRsvpClosureController as WebClosure;
use App\Http\Resources\Api\V1\EventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

/**
 * API twin of the web controller: stop / resume taking RSVPs. plans/rsvp-deadline-moments.md Phase 2.
 */
class EventRsvpClosureController extends Controller
{
    public function store(Event $event): JsonResponse
    {
        $this->authorize('pause', $event);

        if (! WebClosure::canClose($event)) {
            return response()->json([
                'error' => 'invalid_lifecycle_transition',
                'message' => 'Only a live invitation that has not taken place yet can stop taking RSVPs.',
            ], 422);
        }

        if (! $event->rsvpManuallyClosed()) {
            $event->forceFill(['rsvp_closed_at' => now()])->save();
        }

        return response()->json(new EventResource($event->fresh()));
    }

    public function destroy(Event $event): JsonResponse
    {
        $this->authorize('pause', $event);

        if ($event->rsvpManuallyClosed()) {
            $event->forceFill(['rsvp_closed_at' => null])->save();
        }

        return response()->json(new EventResource($event->fresh()));
    }
}
