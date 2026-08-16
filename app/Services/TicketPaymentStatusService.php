<?php

namespace App\Services;

use App\Enums\TicketOrderStatus;
use App\Models\TicketPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Parallel to PaymentStatusService — drives TicketPayment/TicketOrder instead
 * of Payment/User credits. Not a shared abstraction: the two domains'
 * completion side-effects (issue tickets vs grant credits) have nothing in
 * common. See plans/ticketing.md §5.2.
 */
class TicketPaymentStatusService
{
    public function __construct(private readonly TicketOrderFulfillmentService $fulfillment) {}

    /**
     * Persist Lenco initiate fields, then apply the mapped status under the
     * same lock/idempotency rules as webhook/verify (including fail/cancel/
     * expire — initiate must not coerce those to pending).
     *
     * @param  array<string, mixed>  $result
     */
    public function applyInitiateResult(TicketPayment $payment, array $result): TicketPayment
    {
        $mappedStatus = LencoService::mapStatus((string) ($result['status'] ?? 'pending'));

        $payment->update([
            'provider' => $result['provider'] ?? $payment->provider,
            'lenco_transaction_id' => $result['transactionId'] ?? $payment->lenco_transaction_id,
            'lenco_reference' => $result['lencoReference'] ?? $payment->lenco_reference,
            'lenco_status' => $result['status'] ?? $payment->lenco_status,
            'lenco_response' => $result['rawResponse'] ?? $payment->lenco_response,
            'payment_instructions' => $result['paymentInstructions'] ?? $payment->payment_instructions,
            'bank_details' => $result['bankDetails'] ?? $payment->bank_details,
            'payment_url' => $result['paymentUrl'] ?? $payment->payment_url,
        ]);

        $fresh = $payment->fresh();
        if ($fresh === null) {
            return $payment;
        }

        return $this->applyVerificationResult($fresh, [
            'status' => $mappedStatus,
            'lencoStatus' => (string) ($result['status'] ?? 'pending'),
            'transactionId' => $result['transactionId'] ?? null,
            'lencoReference' => $result['lencoReference'] ?? null,
            'amount' => $result['amount'] ?? null,
            'currency' => $result['currency'] ?? $fresh->currency,
            'rawResponse' => $result['rawResponse'] ?? [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $verification
     */
    public function applyVerificationResult(TicketPayment $payment, array $verification): TicketPayment
    {
        $mappedStatus = (string) ($verification['status'] ?? 'pending');
        $lencoStatus = (string) ($verification['lencoStatus'] ?? $mappedStatus);

        return DB::transaction(function () use ($payment, $verification, $mappedStatus, $lencoStatus): TicketPayment {
            /** @var TicketPayment $locked */
            $locked = TicketPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $recoveringCompleted = $mappedStatus === 'completed' && $locked->canRecoverToCompleted();

            if ($locked->isTerminal() && ! $recoveringCompleted) {
                $this->queueFulfillmentIfPaymentCompleted($locked);
                $this->queueUnsuccessfulIfNeeded($locked);

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
                if (! TicketPayment::providerSettlementMatchesRecordedPayment(
                    isset($verification['amount']) ? (float) $verification['amount'] : null,
                    isset($verification['currency']) ? (string) $verification['currency'] : null,
                    $locked
                )) {
                    Log::warning('ticket_payment.verify.amount_mismatch', [
                        'ticket_payment_id' => $locked->id,
                        'expected_amount' => (float) $locked->amount,
                        'received_amount' => $verification['amount'] ?? null,
                    ]);
                    $locked->status = 'failed';
                    $locked->failure_reason = 'Amount or currency mismatch with provider settlement';
                    $locked->failed_at = now();
                    $locked->save();

                    DB::afterCommit(fn () => $this->fulfillment->fail($locked->order, $locked->failure_reason));

                    return $locked->fresh();
                }

                $locked->status = 'completed';
                $locked->completed_at = now();
                $locked->save();

                $this->queueFulfillmentIfPaymentCompleted($locked);

                return $locked->fresh();
            }

            if ($mappedStatus === 'failed') {
                $locked->status = 'failed';
                $locked->failure_reason = 'Payment failed at provider';
                $locked->failed_at = now();
                $locked->save();

                DB::afterCommit(fn () => $this->fulfillment->fail($locked->order, 'Payment failed at provider'));

                return $locked->fresh();
            }

            if ($mappedStatus === 'cancelled') {
                $expired = strtolower(trim($lencoStatus)) === 'expired';
                $locked->status = 'cancelled';
                $locked->failure_reason = $expired
                    ? 'Payment expired at provider'
                    : 'Payment cancelled or expired at provider';
                $locked->cancelled_at = now();
                $locked->save();

                $reason = $locked->failure_reason;
                DB::afterCommit(function () use ($locked, $expired, $reason) {
                    if ($expired) {
                        $this->fulfillment->expire($locked->order, $reason);
                    } else {
                        $this->fulfillment->cancel($locked->order, $reason);
                    }
                });

                return $locked->fresh();
            }

            $locked->status = $mappedStatus === 'processing' ? 'processing' : 'pending';
            $locked->save();

            if ($mappedStatus === 'processing') {
                DB::afterCommit(fn () => $this->fulfillment->markProcessing($locked->order));
            }

            return $locked->fresh();
        });
    }

    /**
     * If settlement is already recorded but ticket issuance failed (or raced),
     * try complete() again. Also used to recover a failed/cancelled/expired
     * order once the payment row is `completed`.
     */
    public function retryFulfillmentIfNeeded(TicketPayment $payment): void
    {
        if ($payment->status === 'completed') {
            $this->queueFulfillmentIfPaymentCompleted($payment);

            return;
        }

        $this->queueUnsuccessfulIfNeeded($payment);
    }

    /**
     * Hard failure from our side (Lenco 4xx, retry exhaustion) — lock the
     * payment row, then fail the order.
     */
    public function markFailed(TicketPayment $payment, string $reason): TicketPayment
    {
        return DB::transaction(function () use ($payment, $reason): TicketPayment {
            /** @var TicketPayment $locked */
            $locked = TicketPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'completed' || $locked->status === 'refunded') {
                return $locked;
            }

            if (! $locked->isTerminal()) {
                $locked->status = 'failed';
                $locked->failure_reason = $reason;
                $locked->failed_at = now();
                $locked->save();
            }

            $order = $locked->order;
            if ($order !== null) {
                DB::afterCommit(fn () => $this->fulfillment->fail($order, $reason));
            }

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $webhook
     */
    public function applyWebhook(TicketPayment $payment, array $webhook): TicketPayment
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

    private function queueFulfillmentIfPaymentCompleted(TicketPayment $locked): void
    {
        if ($locked->status !== 'completed') {
            return;
        }

        $order = $locked->order;
        if ($order === null || $order->status === TicketOrderStatus::Paid || $order->status === TicketOrderStatus::Refunded) {
            return;
        }

        if (! $order->status->isInFlight() && ! $order->status->isRecoverable()) {
            return;
        }

        DB::afterCommit(fn () => $this->fulfillment->complete($order));
    }

    private function queueUnsuccessfulIfNeeded(TicketPayment $locked): void
    {
        $order = $locked->order;
        if ($order === null || ! $order->status->isInFlight()) {
            return;
        }

        if ($locked->status === 'failed') {
            $reason = $locked->failure_reason ?: 'Payment failed at provider';
            DB::afterCommit(fn () => $this->fulfillment->fail($order, $reason));

            return;
        }

        if ($locked->status !== 'cancelled') {
            return;
        }

        $reason = $locked->failure_reason ?: 'Payment cancelled or expired at provider';
        $expired = strtolower((string) $locked->lenco_status) === 'expired';
        DB::afterCommit(function () use ($order, $expired, $reason) {
            if ($expired) {
                $this->fulfillment->expire($order, $reason);
            } else {
                $this->fulfillment->cancel($order, $reason);
            }
        });
    }
}
