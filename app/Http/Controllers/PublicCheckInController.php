<?php

namespace App\Http\Controllers;

use App\Exceptions\CheckInClosedException;
use App\Models\EventStaffLink;
use App\Models\Guest;
use App\Services\CheckInService;
use App\Support\CheckInLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * No-login check-in scanner reached via a shareable staff link (EventStaffLink),
 * for door staff who don't have — and shouldn't need — a dashboard account.
 * Same trust model as guest RSVP tokens: an unguessable bearer secret in the URL.
 */
class PublicCheckInController extends Controller
{
    public function scan(string $staffToken): View
    {
        $link = EventStaffLink::query()
            ->where('token', $staffToken)
            ->with(['event' => fn ($q) => $q->withTrashed()->with('user')])
            ->first();

        $event = $link?->event;

        return view('events.checkin.public-scan', [
            'link' => $link,
            'event' => $event,
            'isActive' => $link !== null
                && $event !== null
                && ! $event->trashed()
                && $link->isActive()
                && $event->isInvitation()
                && $event->ownerHasPremiumEventTools(),
        ]);
    }

    public function confirmToken(string $staffToken, string $token, CheckInService $checkInService): JsonResponse
    {
        $link = $this->resolveActiveLink($staffToken);

        $guest = Guest::query()
            ->where('event_id', $link->event_id)
            ->where('invitation_token', $token)
            ->first();

        abort_if($guest === null, 404, 'No matching invitation for this event.');

        return $this->confirmResponse($checkInService, $guest, $link);
    }

    public function confirmGuest(string $staffToken, Guest $guest, CheckInService $checkInService): JsonResponse
    {
        $link = $this->resolveActiveLink($staffToken);
        abort_unless($guest->event_id === $link->event_id, 404);

        return $this->confirmResponse($checkInService, $guest, $link);
    }

    public function lookup(Request $request, string $staffToken): JsonResponse
    {
        $link = $this->resolveActiveLink($staffToken);

        $term = CheckInLookup::term((string) $request->query('q', ''));
        if ($term === null) {
            return response()->json(['guests' => []]);
        }

        $guests = $link->event->guests()
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

    private function resolveActiveLink(string $staffToken): EventStaffLink
    {
        $link = EventStaffLink::query()
            ->where('token', $staffToken)
            ->with(['event' => fn ($q) => $q->withTrashed()->with('user')])
            ->first();

        abort_if($link === null, 404);
        abort_unless($link->isActive(), 403, 'This scanner link has been revoked.');
        // EventStaffLink::event() excludes soft-deleted events by default, so a
        // deleted event's scanner link would otherwise crash below instead of
        // 404ing cleanly.
        abort_if($link->event === null || $link->event->trashed(), 404);
        abort_unless($link->event->isInvitation(), 404);
        abort_unless($link->event->ownerHasPremiumEventTools(), 403, 'This event is not on a premium plan.');

        return $link;
    }

    private function confirmResponse(CheckInService $checkInService, Guest $guest, EventStaffLink $link): JsonResponse
    {
        try {
            // No user id to record — the link is the credential. Its label is
            // the only thing that says which door this scan came through.
            $payload = $checkInService->confirm($guest, null, $link->scanLabel());
        } catch (CheckInClosedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $link->markUsed();

        return response()->json($payload);
    }
}
