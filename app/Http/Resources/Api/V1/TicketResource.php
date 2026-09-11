<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Used standalone by GET /api/v1/tickets/wallet/{token} and nested inside
 * TicketOrderResource.tickets. `wallet_qr_payload_url` is the exact string the web
 * SVG QR encodes ($ticket->publicUrl(), see TicketController::qr()) — Android renders
 * its own QR from it rather than fetching a binary image, same decision B1 made for
 * the RSVP entry-pass QR.
 *
 * @mixin \App\Models\Ticket
 */
class TicketResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_token' => $this->public_token,
            'attendee_name' => $this->attendee_name,
            'attendee_email' => $this->attendee_email,
            'ticket_type_name' => $this->ticketType?->name,
            'status' => $this->status->value,
            'price_paid' => (string) $this->price_paid,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'wallet_qr_payload_url' => $this->publicUrl(),
            'download_url' => route('api.v1.tickets.wallet.download', ['token' => $this->public_token]),
        ];
    }
}
