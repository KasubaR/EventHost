<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\TicketPurchaseException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PublicEventListResource;
use App\Http\Resources\Api\V1\TicketHoldResource;
use App\Http\Resources\Api\V1\TicketTypeResource;
use App\Models\Event;
use App\Services\PublicInvitationResolver;
use App\Services\TicketReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * JSON sibling of App\Http\Controllers\EventTicketPurchaseController — no session,
 * no redirect+flash. Reuses PublicInvitationResolver::resolveForTickets() and
 * TicketReservationService::hold() verbatim. The web controller is untouched by this
 * class. See the Slice B2 plan for the cart_id redesign (no TicketCart/session use
 * here — hold() mints its own UUID and returns it in the response body).
 */
class EventTicketPurchaseController extends Controller
{
    public function show(string $slug, PublicInvitationResolver $resolver): JsonResponse|RedirectResponse
    {
        $event = $this->resolveEvent($slug, $resolver);

        if ($event instanceof RedirectResponse) {
            return $event;
        }

        $event->load(['ticketTypes' => fn ($q) => $q->where('is_active', true)]);

        return response()->json([
            'event' => new PublicEventListResource($event),
            'ticket_types' => TicketTypeResource::collection($event->ticketTypes),
        ]);
    }

    public function hold(
        string $slug,
        Request $request,
        TicketReservationService $reservations,
        PublicInvitationResolver $resolver,
    ): TicketHoldResource|JsonResponse|RedirectResponse {
        $event = $this->resolveEvent($slug, $resolver);

        if ($event instanceof RedirectResponse) {
            return $event;
        }

        $validated = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['integer', 'min:0', 'max:100'],
        ]);

        // No TicketCart/session here — each hold() call mints its own fresh cart id
        // and hands it back in the response, unlike web's session-persisted cart.
        $cartId = (string) Str::uuid();

        try {
            $held = $reservations->hold($event, $cartId, array_map('intval', $validated['quantities']));
            // hold() returns a plain Support\Collection (collect()->push(...) inside the
            // service), not an Eloquent Collection, so loadMissing() isn't available here —
            // load the relation onto each freshly created row individually instead.
            $held->each(fn ($reservation) => $reservation->load('ticketType'));
        } catch (TicketPurchaseException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return new TicketHoldResource($event, $cartId, $held);
    }

    private function resolveEvent(string $slug, PublicInvitationResolver $resolver): Event|RedirectResponse
    {
        return $resolver->resolveForTickets($slug);
    }
}
