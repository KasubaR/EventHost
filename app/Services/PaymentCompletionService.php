<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentReceiptNotification;
use App\Support\BillingPlan;
use App\Support\PaymentLog;
use Illuminate\Support\Facades\DB;

class PaymentCompletionService
{
    public function __construct(private readonly EventCreditService $credits) {}

    public function complete(Payment $payment): void
    {
        $userId = DB::transaction(function () use ($payment): ?int {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'completed') {
                return null;
            }

            if ($locked->notified_at !== null) {
                PaymentLog::forPayment($locked, 'complete.skipped_already_fulfilled');

                return null;
            }

            /** @var User $user */
            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();

            $this->credits->grant(
                $user,
                (int) $locked->credits_granted,
                CreditTransaction::REASON_PURCHASE,
                $locked
            );

            $purchasedTier = BillingPlan::tierForPlan($locked->plan_key);
            if ($purchasedTier->rank() > $user->subscriptionTierRank()) {
                $user->update(['subscription_tier' => $purchasedTier]);
            }

            $locked->update(['notified_at' => now()]);

            PaymentLog::forPayment($locked, 'complete.fulfilled', [
                'credits_granted' => $locked->credits_granted,
                'tier' => $purchasedTier->value,
            ]);

            return (int) $user->id;
        });

        if ($userId === null) {
            return;
        }

        $freshUser = User::query()->find($userId);
        $freshPayment = Payment::query()->find($payment->id);

        if ($freshUser === null || $freshPayment === null) {
            return;
        }

        if ((bool) ($freshUser->notification_preferences['email_payment_receipts'] ?? true)) {
            $freshUser->notify(new PaymentReceiptNotification($freshPayment));
        }
    }

    public function markCompleted(Payment $payment, ?string $lencoStatus = null): Payment
    {
        $payment->update([
            'status' => 'completed',
            'lenco_status' => $lencoStatus ?? $payment->lenco_status,
            'completed_at' => $payment->completed_at ?? now(),
        ]);

        return $payment->fresh();
    }

    public function markFailed(Payment $payment, string $reason): Payment
    {
        $payment->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'failed_at' => now(),
        ]);

        return $payment->fresh();
    }

    public function markCancelled(Payment $payment, ?string $reason = null): Payment
    {
        $payment->update([
            'status' => 'cancelled',
            'failure_reason' => $reason,
            'cancelled_at' => now(),
        ]);

        return $payment->fresh();
    }
}
