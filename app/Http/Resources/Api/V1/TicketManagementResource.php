<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/host/events/{event}/tickets (Slice D) — host management table row.
 * Distinct from the guest-facing TicketResource (wallet-shaped: public_token,
 * QR payload, download link — none of which a host row needs). No EH-### ordinal
 * here: same reasoning as the web table, which only ever computes it from
 * $tickets->firstItem() for CSV/pagination display, never stores it — the client
 * adds its own row index to the `starting_ordinal` returned alongside this
 * collection rather than this resource trying to number itself per-page.
 *
 * @mixin Ticket
 */
class TicketManagementResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attendee_name' => $this->attendee_name ?: ($this->order?->buyer_name ?? null),
            'attendee_email' => $this->attendee_email ?: ($this->order?->buyer_email ?? null),
            'attendee_phone' => $this->attendee_phone ?: ($this->order?->buyer_phone ?? null),
            'ticket_type_name' => $this->ticketType?->name,
            'order_reference' => $this->order?->order_reference,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'is_checked_in' => $this->isCheckedIn(),
            'checked_in_by' => $this->checkedInByLabel(),
        ];
    }
}
