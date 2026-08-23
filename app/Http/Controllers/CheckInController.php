<?php

namespace App\Http\Controllers;

use App\Exceptions\CheckInClosedException;
use App\Models\Event;
use App\Models\Guest;
use App\Services\CheckInAnalyticsService;
use App\Services\CheckInService;
use App\Support\CheckInLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckInController extends Controller
{
    public function scan(Event $event, CheckInAnalyticsService $analyticsService): View|RedirectResponse
    {
        $this->authorizeInvitation($event);

        if (! $event->ownerHasPremiumEventTools()) {
            return redirect()->route('billing.show')->with('status', 'premium-required-checkin');
        }

        $stats = [
            'total' => $event->guests()->count(),
            'checked_in' => $event->guests()->whereNotNull('checked_in_at')->count(),
        ];

        $arrivals = $analyticsService->arrivalsByBucket($event);
        $staffLinks = $event->staffLinks()->get();

        return view('events.checkin.scan', compact('event', 'stats', 'arrivals', 'staffLinks'));
    }

    /**
     * Phone cameras GET the URL printed on a guest badge. That must never
     * check anyone in — only the scanner page's POST does. Send the visitor
     * to the homepage so a host who scanned with their camera lands somewhere
     * real instead of a 404.
     */
    public function openFromCamera(Event $event, string $token): RedirectResponse
    {
        return redirect()->route('home');
    }

    /**
     * Fired by the staff scanner page after it decodes a guest's invitation QR.
     * Guests can never hit this route on their own — it's auth-protected and only
     * ever called via fetch() from the logged-in scanner page.
     */
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

        return $this->confirmResponse($checkInService, $guest, auth()->id());
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

        return $this->confirmResponse($checkInService, $guest, auth()->id());
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

    private function confirmResponse(CheckInService $checkInService, Guest $guest, ?int $staffUserId): JsonResponse
    {
        try {
            return response()->json($checkInService->confirm($guest, $staffUserId));
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
