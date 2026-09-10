<?php

namespace App\Services;

use App\Enums\ContributionStatus;
use App\Models\ContributionPayment;
use App\Models\EventContribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Parallel to TicketPaymentStatusService — drives ContributionPayment /
 * EventContribution instead of TicketPayment / TicketOrder. Unlike tickets,
 * crediting the pledge's running total happens inline under the same row
 * lock rather than being deferred to a separate service — there is nothing
 * external (like issuing a ticket) that can fail independently of the
 * payment itself. Notifications (Phase 3) are the one genuinely deferred
 * side effect, queued via DB::afterCommit() the same way ticket fulfillment
 * defers issuing tickets. See plans/contributions.md.
 */
class ContributionPaymentStatusService
{
    public function __construct(private readonly CommunicationService $communication) {}

    /**
     * Persist Lenco initiate fields, then apply the mapped status under the
     * same lock/idempotency rules as webhook/verify.
     *
     * @param  array<string, mixed>  $result
     */
    public function applyInitiateResult(ContributionPayment $payment, array $result): ContributionPayment
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
    public function applyVerificationResult(ContributionPayment $payment, array $verification): ContributionPayment
    {
        $mappedStatus = (string) ($verification['status'] ?? 'pending');
        $lencoStatus = (string) ($verification['lencoStatus'] ?? $mappedStatus);

        return DB::transaction(function () use ($payment, $verification, $mappedStatus, $lencoStatus): ContributionPayment {
            /** @var ContributionPayment $locked */
            $locked = ContributionPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $recoveringCompleted = $mappedStatus === 'completed' && $locked->canRecoverToCompleted();

            if ($locked->isTerminal() && ! $recoveringCompleted) {
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
                if (! ContributionPayment::providerSettlementMatchesRecordedPayment(
                    isset($verification['amount']) ? (float) $verification['amount'] : null,
                    isset($verification['currency']) ? (string) $verification['currency'] : null,
                    $locked
                )) {
                    Log::warning('contribution_payment.verify.amount_mismatch', [
                        'contribution_payment_id' => $locked->id,
                        'expected_amount' => (float) $locked->amount,
                        'received_amount' => $verification['amount'] ?? null,
                    ]);
                    $locked->status = 'failed';
                    $locked->failure_reason = 'Amount or currency mismatch with provider settlement';
                    $locked->failed_at = now();
                    $locked->save();

                    return $locked->fresh();
                }

                $locked->status = 'completed';
                $locked->completed_at = now();
                $locked->save();

                $this->creditContribution($locked);

                return $locked->fresh();
            }

            if ($mappedStatus === 'failed') {
                $locked->status = 'failed';
                $locked->failure_reason = 'Payment failed at provider';
                $locked->failed_at = now();
                $locked->save();

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

                return $locked->fresh();
            }

            $locked->status = $mappedStatus === 'processing' ? 'processing' : 'pending';
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * Hard failure from our side (Lenco 4xx, retry exhaustion) — lock the
     * payment row and mark it failed. Nothing else to unwind: the pledge's
     * amount_paid was never touched for a payment that never completed.
     */
    public function markFailed(ContributionPayment $payment, string $reason): ContributionPayment
    {
        return DB::transaction(function () use ($payment, $reason): ContributionPayment {
            /** @var ContributionPayment $locked */
            $locked = ContributionPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'completed' || $locked->status === 'refunded') {
                return $locked;
            }

            if (! $locked->isTerminal()) {
                $locked->status = 'failed';
                $locked->failure_reason = $reason;
                $locked->failed_at = now();
                $locked->save();
            }

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $webhook
     */
    public function applyWebhook(ContributionPayment $payment, array $webhook): ContributionPayment
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

    /**
     * Called exactly once per payment, from inside the branch that just
     * flipped it from non-terminal to completed — isTerminal() guards every
     * other path back into applyVerificationResult, so this can't double-add
     * the same installment to amount_paid.
     */
    private function creditContribution(ContributionPayment $payment): void
    {
        /** @var EventContribution $contribution */
        $contribution = EventContribution::query()
            ->whereKey($payment->event_contribution_id)
            ->lockForUpdate()
            ->firstOrFail();

        $contribution->amount_paid = round((float) $contribution->amount_paid + (float) $payment->amount, 2);

        $contribution->status = $contribution->amount_paid >= (float) $contribution->target_amount
            ? ContributionStatus::Completed
            : ContributionStatus::Partial;

        if ($contribution->status === ContributionStatus::Completed && $contribution->completed_at === null) {
            $contribution->completed_at = now();
        }

        $contribution->save();

        DB::afterCommit(fn () => $this->dispatchNotifications($contribution->fresh(), $payment));
    }

    /**
     * Contributor receipt + host notification — best-effort. A notification
     * failure must never surface as a failed payment; the money already
     * moved by the time this runs.
     */
    private function dispatchNotifications(EventContribution $contribution, ContributionPayment $payment): void
    {
        try {
            $this->communication->sendContributionReceipt($contribution, $payment);

            $host = $contribution->event?->user;
            if ($host !== null) {
                $this->communication->notifyHostNewContribution($host, $contribution, $payment);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
