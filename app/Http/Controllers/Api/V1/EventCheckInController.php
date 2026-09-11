<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CheckInClosedException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Guest;
use App\Services\CheckInService;
use App\Support\CheckInLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON sibling of App\Http\Controllers\CheckInController — the door-staff camera
 * scanner for RSVP guests (Slice D). The web controller already returns JSON from
 * every one of these three methods (its scanner page calls them via fetch() with
 * session auth), so this is close to a verbatim port: swap `auth:sanctum` for the
 * session guard, drop scan() (HTML view) and openFromCamera() (a guard against a
 * guest's own camera app hitting a GET link, meaningless once nothing prints this
 * URL on a badge). CheckInService is reused unchanged.
 */
class EventCheckInController extends Controller
{
    public function confirmToken(Event $event, string $token, CheckInService $checkInService): JsonResponse
    {
        $this->authorizeInvitation($event);

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['message' => 'This event is not on a premium plan.'], 403);
        }

        $guest = Guest::query()
            ->where('event_id', $event->id)
            ->where('invitation_token', $token)
            ->first();

        if ($guest === null) {
            return response()->json(['message' => 'No matching invitation for this event.'], 404);
        }

        return $this->confirmResponse($checkInService, $guest);
    }

    public function confirmGuest(Event $event, Guest $guest, CheckInService $checkInService): JsonResponse
    {
        $guest->loadMissing('event');
        $this->authorize('checkIn', $guest);
        abort_unless($guest->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['message' => 'This event is not on a premium plan.'], 403);
        }

        return $this->confirmResponse($checkInService, $guest);
    }

    public function lookup(Request $request, Event $event): JsonResponse
    {
        $this->authorizeInvitation($event);

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['message' => 'This event is not on a premium plan.'], 403);
        }

        $term = CheckInLookup::term((string) $request->query('q', ''));
        if ($term === null) {
            return response()->json(['guests' => []]);
        }

        $guests = $event->guests()
            ->search($term)
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'checked_in_at']);

        return response()->json([
            'guests' => $guests->map(fn (Guest $guest) => [
                'id' => $guest->id,
                'name' => $guest->name,
                'checked_in_at' => $guest->checked_in_at?->toIso8601String(),
            ]),
        ]);
    }

    private function confirmResponse(CheckInService $checkInService, Guest $guest): JsonResponse
    {
        try {
            return response()->json($checkInService->confirm($guest, auth()->id()));
        } catch (CheckInClosedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    private function authorizeInvitation(Event $event): void
    {
        $this->authorize('checkIn', $event);

        abort_unless($event->isInvitation(), 404);
    }
}
