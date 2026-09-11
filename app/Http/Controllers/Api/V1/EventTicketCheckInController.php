<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\TicketCheckInException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Ticket;
use App\Services\TicketCheckInService;
use App\Support\CheckInLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON sibling of App\Http\Controllers\TicketCheckInController — the ticket-side
 * twin of EventCheckInController (see that class's docblock; same reasoning
 * applies here). Parallel to EventCheckInController, not a shared abstraction —
 * matches the web pair's own separation (plans/ticketing.md §5.9).
 */
class EventTicketCheckInController extends Controller
{
    public function confirmToken(Event $event, string $token, TicketCheckInService $checkInService): JsonResponse
    {
        $this->authorizeTicketed($event);

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['message' => 'Ticket sales for this event have not been approved yet.'], 403);
        }

        $ticket = Ticket::query()
            ->where('event_id', $event->id)
            ->where('public_token', $token)
            ->first();

        if ($ticket === null) {
            return response()->json(['message' => 'No matching ticket for this event.'], 404);
        }

        return $this->confirmResponse($checkInService, $ticket);
    }

    public function confirmTicket(Event $event, Ticket $ticket, TicketCheckInService $checkInService): JsonResponse
    {
        $this->authorizeTicketed($event);
        abort_unless($ticket->event_id === $event->id, 404);

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['message' => 'Ticket sales for this event have not been approved yet.'], 403);
        }

        return $this->confirmResponse($checkInService, $ticket);
    }

    public function lookup(Request $request, Event $event): JsonResponse
    {
        $this->authorizeTicketed($event);

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['message' => 'Ticket sales for this event have not been approved yet.'], 403);
        }

        $term = CheckInLookup::term((string) $request->query('q', ''));
        if ($term === null) {
            return response()->json(['tickets' => []]);
        }

        $tickets = $event->tickets()
            ->search($term)
            ->orderBy('attendee_name')
            ->limit(10)
            ->get(['id', 'attendee_name', 'checked_in_at']);

        return response()->json([
            'tickets' => $tickets->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'name' => $ticket->attendee_name,
                'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
            ]),
        ]);
    }

    private function confirmResponse(TicketCheckInService $checkInService, Ticket $ticket): JsonResponse
    {
        try {
            return response()->json($checkInService->confirm($ticket, auth()->id()));
        } catch (TicketCheckInException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    private function authorizeTicketed(Event $event): void
    {
        $this->authorize('checkIn', $event);

        abort_unless($event->isTicketed(), 404);
    }
}
