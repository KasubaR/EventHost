<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\TicketPayment;
use App\Services\AstragateService;
use App\Services\PaymentGateway;
use App\Services\PaymentStatusService;
use App\Services\TicketPaymentStatusService;
use App\Support\PaymentLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Receives Astragate callbacks at a secret URL (`POST /webhooks/astragate/{secret}`).
 * Astragate does not sign callbacks, so a wrong or unconfigured secret 404s — the route
 * looks like it does not exist — and the body is **never trusted**: it only tells us which
 * payment to look at. The status that gets applied is read back from Astragate's API.
 *
 * Only rows with `gateway = astragate` are matched, so a callback can never move a Lenco
 * payment. Anything unmatched is acknowledged and ignored.
 */
class AstragateWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $secret,
        AstragateService $astragate,
        PaymentStatusService $paymentStatus,
        TicketPaymentStatusService $ticketStatus,
    ): JsonResponse {
        $expected = (string) config('astragate.webhook_secret');

        if ($expected === '' || ! hash_equals($expected, $secret)) {
            abort(404);
        }

        $reference = $request->input('correlatorId');
        $reference = is_string($reference) ? $reference : '';

        $payment = $reference === '' ? null : Payment::query()
            ->where('gateway', PaymentGateway::ASTRAGATE)
            ->where('payment_reference', $reference)
            ->first();

        $ticketPayment = ($payment !== null || $reference === '') ? null : TicketPayment::query()
            ->where('gateway', PaymentGateway::ASTRAGATE)
            ->where('payment_reference', $reference)
            ->first();

        if ($payment === null && $ticketPayment === null) {
            PaymentLog::info('astragate.webhook.acknowledged_unmatched', ['reference' => $reference]);

            return response()->json(['success' => true, 'message' => 'acknowledged']);
        }

        $row = $payment ?? $ticketPayment;

        // Replays are safe: the status services ignore a terminal row, apart from the
        // reversal / recovery cases they handle themselves.
        $row->update([
            'webhook_received' => true,
            'webhook_payload' => $request->all(),
            'webhook_received_at' => now(),
        ]);

        try {
            $verification = $astragate->verifyByReference($reference, (float) $row->amount, (string) $row->currency);

            if ($payment !== null) {
                $paymentStatus->applyVerificationResult($payment->fresh(), $verification);
                PaymentLog::forPayment($payment->fresh(), 'astragate.webhook.processed');
            } else {
                $ticketStatus->applyVerificationResult($ticketPayment->fresh(), $verification);
            }
        } catch (RuntimeException $e) {
            // The poller (payments:poll-pending / tickets) picks it up; nothing is lost.
            PaymentLog::warning('astragate.webhook.verify_failed', [
                'reference' => $reference,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'processed']);
    }
}
