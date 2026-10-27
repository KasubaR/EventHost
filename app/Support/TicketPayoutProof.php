<?php

namespace App\Support;

use App\Models\TicketPayout;
use App\Services\TicketPayoutService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a payout's proof of payment from the private disk. The caller has already authorised the
 * request and checked the payout belongs to the event in the URL; this only refuses what is not there.
 */
final class TicketPayoutProof
{
    public static function response(TicketPayout $payout): StreamedResponse
    {
        $disk = Storage::disk(TicketPayoutService::PROOF_DISK);

        abort_unless($payout->hasProof() && $disk->exists($payout->proof_path), 404);

        return $disk->response($payout->proof_path, $payout->proof_original_name, [
            // Show an image or PDF in the browser, but never let it be sniffed as anything else.
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
