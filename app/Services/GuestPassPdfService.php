<?php

namespace App\Services;

use App\Models\Guest;
use App\Support\GuestPassCard;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders the guest's invitation pass as a downloadable PDF — the twin of
 * TicketPdfService. Caching (and why it is keyed on the card's fingerprint
 * rather than the token alone) lives in GuestPassFileCache. See
 * plans/invitation-pass-card.md §3.
 */
class GuestPassPdfService
{
    public function __construct(
        private readonly QrCodeService $qrCodeService,
        private readonly GuestPassFileCache $cache,
    ) {}

    public function render(Guest $guest, GuestPassCard $card): string
    {
        return $this->cache->remember(
            GuestPassFileCache::PDF,
            $guest,
            $card,
            'pdf',
            fn (): string => $this->generate($guest, $card),
        );
    }

    private function generate(Guest $guest, GuestPassCard $card): string
    {
        $qrUrl = $guest->checkInQrUrl();
        abort_if($qrUrl === null, 404);

        $qrDataUri = 'data:image/png;base64,'.base64_encode($this->qrCodeService->png($qrUrl, 260));

        // The icon-only mark: pink on a transparent background, so it sits on the
        // themed header without the white box the wordmark PNG (built for email)
        // would draw there. The brand name is printed beside it as text.
        $logoPath = public_path('images/logo/EventHost Logo_Icon.svg');
        $logoDataUri = is_file($logoPath)
            ? 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        return (string) Pdf::loadView('rsvp.pass-pdf', [
            'card' => $card,
            'qrDataUri' => $qrDataUri,
            'logoDataUri' => $logoDataUri,
        ])
            // Subsetting embeds only the glyphs used instead of all of DejaVu (~700 KB) —
            // this file rides along as an email attachment, so size matters.
            ->setOption(['enable_font_subsetting' => true])
            ->setPaper('a5', 'portrait')
            ->output();
    }
}
