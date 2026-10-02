<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\AstragateService;
use App\Services\PaymentStatusService;
use App\Support\PaymentLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Astragate collection callbacks at a secret URL
 * (`POST /webhooks/astragate/{secret}`). Astragate does not sign callbacks, so a
 * wrong or unconfigured secret 404s — the route looks like it does not exist.
 */
class AstragateWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, PaymentStatusService $statusService): JsonResponse
    {
        $expected = (string) config('services.astragate.webhook_secret');

        if ($expected === '' || ! hash_equals($expected, $secret)) {
            abort(404);
        }

        $correlatorId = (string) $request->input('correlatorId', '');
        $statusCode = $request->input('statusCode');

        $payment = $correlatorId !== ''
            ? Payment::query()->where('payment_reference', $correlatorId)->first()
            : null;

        if ($payment === null || ($payment->metadata['gateway'] ?? null) !== 'astragate') {
            PaymentLog::info('astragate.webhook_unmatched', ['correlator_id' => $correlatorId]);

            return response()->json(['success' => true, 'message' => 'acknowledged']);
        }

        $payment->update([
            'webhook_received' => true,
            'webhook_payload' => $request->all(),
            'webhook_received_at' => now(),
        ]);

        $status = AstragateService::mapStatusCode(is_scalar($statusCode) ? $statusCode : null);

        // The callback carries no amount, so the recorded one stands in for the
        // settlement check; the unguessable URL is what vouches for the callback.
        $statusService->applyVerificationResult($payment->fresh(), [
            'status' => $status,
            'lencoStatus' => (string) $statusCode,
            'transactionId' => $request->input('systemTransactionId'),
            'reference' => $correlatorId,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'rawResponse' => $request->all(),
        ]);

        PaymentLog::forPayment($payment->fresh(), 'astragate.webhook_processed', ['status' => $status]);

        return response()->json(['success' => true, 'message' => 'processed']);
    }
}
