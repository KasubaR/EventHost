<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Event;
use App\Services\TicketReservationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * "Current held state for a cart_id" — shared by EventTicketPurchaseController::hold()
 * and EventTicketCheckoutController::show(), since both return exactly that. Wraps a
 * Collection<TicketReservation>, not a Model, so JsonResource's automatic
 * wasRecentlyCreated-based status calculation never applies here regardless of call
 * site — no forced status code needed on either response using this resource.
 */
class TicketHoldResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        private readonly Event $event,
        private readonly string $cartId,
        private readonly Collection $reservations,
    ) {
        parent::__construct($reservations);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'cart_id' => $this->cartId,
            'event' => new PublicEventListResource($this->event),
            'currency' => 'ZMW',
            'hold_minutes' => TicketReservationService::HOLD_MINUTES,
            // Soonest of the batch governs the client's countdown — a hold created a
            // moment later than another in the same cart still expires with the group.
            'expires_at' => $this->reservations->min('expires_at')?->toIso8601String(),
            'items' => $this->reservations->map(fn ($reservation) => [
                'ticket_type_id' => $reservation->ticket_type_id,
                'name' => $reservation->ticketType->name,
                'quantity' => $reservation->quantity,
                'unit_price' => (string) $reservation->unit_price_snapshot,
                // number_format, not (string) round(...) — a whole-number float like 400.0
                // stringifies to "400", not "400.00"; every other money field in this API
                // comes from a decimal-cast model attribute, which already formats correctly.
                'subtotal' => number_format((float) $reservation->unit_price_snapshot * $reservation->quantity, 2, '.', ''),
            ])->values(),
            'total' => number_format(
                $this->reservations->sum(fn ($r) => (float) $r->unit_price_snapshot * $r->quantity),
                2,
                '.',
                ''
            ),
        ];
    }
}
