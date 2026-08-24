<?php

namespace App\Http\Controllers;

use App\Exceptions\TicketPurchaseException;
use App\Models\Event;
use App\Services\PublicInvitationResolver;
use App\Services\TicketReservationService;
use App\Support\TicketCart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public ticket picker + hold. No login — same trust posture as the RSVP
 * flow. See plans/ticketing.md §5.
 */
class EventTicketPurchaseController extends Controller
{
    public function show(string $slug, PublicInvitationResolver $resolver): View|RedirectResponse
    {
        $event = $this->resolveEvent($slug, $resolver);

        if ($event instanceof RedirectResponse) {
            return $event;
        }

        $event->load(['ticketTypes' => fn ($q) => $q->where('is_active', true)]);

        return view('events.tickets.purchase', ['event' => $event]);
    }

    public function hold(
        string $slug,
        Request $request,
        TicketReservationService $reservations,
        PublicInvitationResolver $resolver,
    ): RedirectResponse {
        $event = $this->resolveEvent($slug, $resolver);

        if ($event instanceof RedirectResponse) {
            return $event;
        }

        $validated = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['integer', 'min:0', 'max:100'],
        ]);

        $cartId = TicketCart::getOrCreate($request, $event);

        try {
            $reservations->hold($event, $cartId, array_map('intval', $validated['quantities']));
        } catch (TicketPurchaseException $e) {
            return redirect()
                ->route('events.public.tickets', $event->slug)
                ->withErrors(['tickets' => $e->getMessage()]);
        }

        return redirect()->route('events.public.tickets.checkout', $event->slug);
    }

    private function resolveEvent(string $slug, PublicInvitationResolver $resolver): Event|RedirectResponse
    {
        return $resolver->resolveForTickets($slug);
    }
}
