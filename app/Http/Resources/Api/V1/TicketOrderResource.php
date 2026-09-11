<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/tickets/orders/{orderReference} — JSON twin of
 * events/tickets/order-status.blade.php. Deliberately narrower than the TicketOrder
 * model: face_value/commission_percent/commission_amount/buyer_fee/host_amount are
 * internal accounting columns the web buyer-facing status page never shows, so this
 * resource never exposes them either — see the Slice B2 plan, design decision #4.
 *
 * @mixin \App\Models\TicketOrder
 */
class TicketOrderResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_reference' => $this->order_reference,
            'status' => $this->status->value,
            'is_terminal' => $this->status->isTerminal(),
            'event' => new PublicEventListResource($this->event),
            'buyer' => [
                'name' => $this->buyer_name,
                'email' => $this->buyer_email,
                'phone' => $this->buyer_phone,
            ],
            'currency' => $this->currency,
            'buyer_total' => (string) $this->buyer_total,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'failure_reason' => $this->payment?->failure_reason,
            'items' => $this->items->map(fn ($item) => [
                'ticket_type_name' => $item->ticket_type_name,
                'unit_price' => (string) $item->unit_price,
                'quantity' => $item->quantity,
                'subtotal' => (string) $item->subtotal,
            ]),
            'tickets' => TicketResource::collection($this->whenLoaded('tickets')),
        ];
    }
}
