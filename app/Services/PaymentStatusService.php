<?php

namespace App\Services;

use App\Models\Payment;
use App\Support\PaymentLog;
use Illuminate\Support\Facades\DB;

class PaymentStatusService
{
    public function __construct(
        private LencoService $lenco,
        private PaymentCompletionService $completion,
    ) {}

    /**
     * @param  array<string, mixed>  $verification
     */
    public function applyVerificationResult(Payment $payment, array $verification): Payment
    {
        if ($payment->isTerminal()) {
            return $payment;
        }

        $mappedStatus = (string) ($verification['status'] ?? 'pending');
        $lencoStatus = (string) ($verification['lencoStatus'] ?? $mappedStatus);

        return DB::transaction(function () use ($payment, $verification, $mappedStatus, $lencoStatus): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isTerminal()) {
                return $locked;
            }

            $locked->updateLencoStatus($lencoStatus, $verification['rawResponse'] ?? []);

            if (! empty($verification['transactionId'])) {
                $locked->lenco_transaction_id = (string) $verification['transactionId'];
            }
            if (! empty($verification['lencoReference'])) {
                $locked->lenco_reference = (string) $verification['lencoReference'];
            }

            if ($mappedStatus === 'completed') {
                if (! Payment::providerSettlementMatchesRecordedPayment(
                    isset($verification['amount']) ? (float) $verification['amount'] : null,
                    isset($verification['currency']) ? (string) $verification['currency'] : null,
                    $locked
                )) {
                    PaymentLog::forPayment($locked, 'verify.amount_mismatch', [
                        'expected_amount' => (float) $locked->amount,
                        'received_amount' => $verification['amount'] ?? null,
                        'currency' => $verification['currency'] ?? null,
                    ], 'warning');
                    $this->completion->markFailed($locked, 'Amount or currency mismatch with provider settlement');

                    return $locked->fresh();
                }

                $locked->status = 'completed';
                $locked->completed_at = now();
                $locked->save();

                PaymentLog::forPayment($locked, 'verify.completed');

                DB::afterCommit(fn () => $this->completion->complete($locked->fresh()));

                return $locked->fresh();
            }

            if ($mappedStatus === 'failed') {
                $this->completion->markFailed($locked, 'Payment failed at provider');

                return $locked->fresh();
            }

            if ($mappedStatus === 'cancelled') {
                $this->completion->markCancelled($locked, 'Payment cancelled or expired at provider');

                return $locked->fresh();
            }

            $locked->status = $mappedStatus === 'processing' ? 'processing' : 'pending';
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $webhook
     */
    public function applyWebhook(Payment $payment, array $webhook): Payment
    {
        $lencoStatus = (string) ($webhook['lencoStatus'] ?? 'pending');
        $mappedStatus = LencoService::mapStatus($lencoStatus);

        return $this->applyVerificationResult($payment, [
            'status' => $mappedStatus,
            'lencoStatus' => $lencoStatus,
            'transactionId' => $webhook['transactionId'] ?? null,
            'reference' => $webhook['reference'] ?? null,
            'lencoReference' => $webhook['lencoReference'] ?? null,
            'amount' => $webhook['amount'] ?? null,
            'currency' => $webhook['currency'] ?? null,
            'rawResponse' => $webhook['raw'] ?? [],
        ]);
    }
}
