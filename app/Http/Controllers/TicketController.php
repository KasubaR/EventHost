<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\QrCodeService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * The buyer's secure ticket page — same trust model as Guest's entry pass:
 * the token in the URL is the only guard, no login. See plans/ticketing.md §5.3.
 */
class TicketController extends Controller
{
    public function show(string $token): View
    {
        $ticket = Ticket::query()
            ->where('public_token', $token)
            ->with(['event', 'ticketType', 'order'])
            ->firstOrFail();

        return view('tickets.show', ['ticket' => $ticket]);
    }

    public function qr(string $token, QrCodeService $qrCodeService): Response
    {
        $ticket = Ticket::query()->where('public_token', $token)->firstOrFail();

        $svg = Cache::remember(
            'ticket-qr:'.$token,
            now()->addWeek(),
            fn () => $qrCodeService->svg($ticket->publicUrl())
        );

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }
}
