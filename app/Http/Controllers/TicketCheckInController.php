<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Exceptions\TicketCheckInException;
use App\Models\Event;
use App\Models\Ticket;
use App\Services\TicketCheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Door check-in scanner for ticketed events. Parallel to CheckInController
 * (guests), not a shared abstraction — see TicketCheckInService. Dashboard
 * scanner only for now, no shareable no-login staff link yet (that's
 * CheckInController/EventStaffLink's pattern for guests) — see
 * plans/ticketing.md §5.4.
 */
class TicketCheckInController extends Controller
{
    public function scan(Event $event): View|RedirectResponse
    {
        $this->authorize('update', $event);
        abort_unless($event->isTicketed(), 404);

        if (! $event->ownerHasPremiumEventTools()) {
            return redirect()->route('billing.show')->with('status', 'premium-required-checkin');
        }

        $stats = [
            'total' => $event->tickets()->whereIn('status', [TicketStatus::Valid, TicketStatus::Used])->count(),
            'checked_in' => $event->tickets()->where('status', TicketStatus::Used)->count(),
        ];

        $staffLinks = $event->staffLinks()->get();

        return view('events.tickets.checkin.scan', compact('event', 'stats', 'staffLinks'));
    }

    /**
     * A ticket's own QR encodes its public buyer-facing page (tickets.show,
     * `/t/{token}`), not a check-in endpoint — unlike a guest's QR, so there
     * is no "opened from camera accidentally checks someone in" risk to guard
     * against here; GET-ing that URL only ever shows the buyer their ticket.
     */
    public function confirmToken(Event $event, string $token, TicketCheckInService $checkInService): JsonResponse
    {
        $this->authorize('update', $event);

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['message' => 'This event is not on a premium plan.'], 403);
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
        $this->authorize('update', $event);
        abort_unless($ticket->event_id === $event->id, 404);

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['message' => 'This event is not on a premium plan.'], 403);
        }

        return $this->confirmResponse($checkInService, $ticket);
    }

    public function lookup(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['message' => 'This event is not on a premium plan.'], 403);
        }

        $term = (string) $request->query('q', '');

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
}
