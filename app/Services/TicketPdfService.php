<?php

namespace App\Services;

use App\Models\Ticket;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the buyer-facing ticket PDF used by /t/{token}/download and the
 * order confirmation email. Cached on the private local disk (not the DB
 * cache store) because the embedded QR raster is a binary blob.
 */
class TicketPdfService
{
    public function __construct(
        private readonly QrCodeService $qrCodeService,
    ) {}

    public function cachePath(Ticket $ticket): string
    {
        return 'ticket-pdfs/v2/'.$ticket->public_token.'.pdf';
    }

    public function render(Ticket $ticket): string
    {
        $ticket->loadMissing(['event', 'ticketType', 'order']);

        $disk = Storage::disk('local');
        $path = $this->cachePath($ticket);

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $binary = $this->generate($ticket);
        $disk->put($path, $binary);

        return $binary;
    }

    private function generate(Ticket $ticket): string
    {
        $qrDataUri = 'data:image/png;base64,'.base64_encode(
            $this->qrCodeService->png($ticket->publicUrl(), 260)
        );

        $logoPath = public_path('images/logo/eventhost-mail.png');
        $logoDataUri = is_file($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        return (string) Pdf::loadView('tickets.pdf', [
            'ticket' => $ticket,
            'qrDataUri' => $qrDataUri,
            'logoDataUri' => $logoDataUri,
        ])
            ->setPaper('a5', 'portrait')
            ->output();
    }
}
