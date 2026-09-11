<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TicketResource;
use App\Models\Ticket;
use App\Services\TicketPdfService;
use Illuminate\Http\Response;

/**
 * JSON sibling of App\Http\Controllers\TicketController — the wallet. Same trust model
 * as web: the token in the URL is the only guard, no auth:sanctum. No `qr` action —
 * TicketResource.wallet_qr_payload_url replaces the binary SVG endpoint, same decision
 * B1 made for the RSVP entry-pass QR. `download` is a verbatim binary PDF passthrough.
 */
class TicketController extends Controller
{
    public function show(string $token): TicketResource
    {
        $ticket = Ticket::query()
            ->where('public_token', $token)
            ->with(['event', 'ticketType', 'order'])
            ->firstOrFail();

        return new TicketResource($ticket);
    }

    public function download(string $token, TicketPdfService $ticketPdfService): Response
    {
        $ticket = Ticket::query()
            ->where('public_token', $token)
            ->with(['event', 'ticketType', 'order'])
            ->firstOrFail();

        return response($ticketPdfService->render($ticket), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ticket-'.$ticket->id.'.pdf"',
        ]);
    }
}
