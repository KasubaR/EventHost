<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
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

    /**
     * The "Download ticket" button — a self-contained PDF the buyer can save
     * or print, distinct from the "View ticket" link to the live /t/{token}
     * page. Uses png() (not svg()) same as the email attachment: dompdf
     * embeds raster more reliably than an inline SVG data URI at this size.
     *
     * Cached like tickets.qr so a shared /download URL cannot burn CPU on
     * every hit; throttle:ticket-download caps uncached first-renders per IP.
     */
    public function download(string $token, QrCodeService $qrCodeService): Response
    {
        $ticket = Ticket::query()
            ->where('public_token', $token)
            ->with(['event', 'ticketType', 'order'])
            ->firstOrFail();

        $pdfBinary = Cache::remember(
            'ticket-pdf:'.$token,
            now()->addWeek(),
            function () use ($ticket, $qrCodeService): string {
                $qrDataUri = 'data:image/png;base64,'.base64_encode($qrCodeService->png($ticket->publicUrl(), 260));

                return (string) Pdf::loadView('tickets.pdf', compact('ticket', 'qrDataUri'))
                    ->setPaper('a5', 'portrait')
                    ->output();
            }
        );

        return response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ticket-'.$ticket->id.'.pdf"',
        ]);
    }
}
