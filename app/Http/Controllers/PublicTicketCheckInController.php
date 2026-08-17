<?php

namespace App\Http\Controllers;

use App\Exceptions\TicketCheckInException;
use App\Models\EventStaffLink;
use App\Models\Ticket;
use App\Services\TicketCheckInService;
use App\Support\CheckInLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * No-login check-in scanner for ticketed events, reached via a shareable
 * staff link — the ticket-side twin of PublicCheckInController (guests).
 * Parallel, not a shared abstraction (see plans/ticketing.md §5.9's reasoning
 * for TicketCheckInController vs CheckInController; the same logic applies
 * one layer out, for the no-login surface). Reuses EventStaffLink as-is: it
 * was already generic (event_id/token/label, no guest-specific column), so
 * this is the first controller to prove that by consuming it for a
 * different credential type without any model change.
 */
class PublicTicketCheckInController extends Controller
{
    public function scan(string $staffToken): View
    {
        $link = EventStaffLink::query()->where('token', $staffToken)->with('event.user')->first();

        return view('events.tickets.checkin.public-scan', [
            'link' => $link,
            'event' => $link?->event,
            'isActive' => $link !== null
                && $link->isActive()
                && $link->event->isTicketed()
                && $link->event->ownerHasPremiumEventTools(),
        ]);
    }

    public function confirmToken(string $staffToken, string $token, TicketCheckInService $checkInService): JsonResponse
    {
        $link = $this->resolveActiveLink($staffToken);

        $ticket = Ticket::query()
            ->where('event_id', $link->event_id)
            ->where('public_token', $token)
            ->first();

        abort_if($ticket === null, 404, 'No matching ticket for this event.');

        return $this->confirmResponse($checkInService, $ticket, $link);
    }

    public function confirmTicket(string $staffToken, Ticket $ticket, TicketCheckInService $checkInService): JsonResponse
    {
        $link = $this->resolveActiveLink($staffToken);
        abort_unless($ticket->event_id === $link->event_id, 404);

        return $this->confirmResponse($checkInService, $ticket, $link);
    }

    public function lookup(Request $request, string $staffToken): JsonResponse
    {
        $link = $this->resolveActiveLink($staffToken);

        $term = CheckInLookup::term((string) $request->query('q', ''));
        if ($term === null) {
            return response()->json(['tickets' => []]);
        }

        $tickets = $link->event->tickets()
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

    private function resolveActiveLink(string $staffToken): EventStaffLink
    {
        $link = EventStaffLink::query()->where('token', $staffToken)->with('event.user')->first();

        abort_if($link === null, 404);
        abort_unless($link->isActive(), 403, 'This scanner link has been revoked.');
        abort_unless($link->event->isTicketed(), 404);
        abort_unless($link->event->ownerHasPremiumEventTools(), 403, 'This event is not on a premium plan.');

        return $link;
    }

    private function confirmResponse(TicketCheckInService $checkInService, Ticket $ticket, EventStaffLink $link): JsonResponse
    {
        try {
            $payload = $checkInService->confirm($ticket, null);
        } catch (TicketCheckInException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $link->markUsed();

        return response()->json($payload);
    }
}
