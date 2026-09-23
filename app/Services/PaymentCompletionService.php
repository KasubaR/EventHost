<?php

namespace App\Services;

use App\Enums\CustomQuoteStatus;
use App\Enums\SubscriptionTier;
use App\Models\CreditTransaction;
use App\Models\CustomQuote;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentReceiptNotification;
use App\Support\BillingPlan;
use App\Support\PaymentLog;
use Illuminate\Support\Facades\DB;

class PaymentCompletionService
{
    public function __construct(private readonly EventCreditService $credits) {}

    /**
     * @var list<string>
     */
    public const REVERSAL_STATUSES = ['failed', 'cancelled', 'refunded'];

    public static function isReversalStatus(string $status): bool
    {
        return in_array($status, self::REVERSAL_STATUSES, true);
    }

    public function complete(Payment $payment): void
    {
        $userId = DB::transaction(function () use ($payment): ?int {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'completed') {
                return null;
            }

            if ($locked->credits_fulfilled_at !== null && $locked->notified_at !== null) {
                PaymentLog::forPayment($locked, 'complete.skipped_already_fulfilled');

                return null;
            }

            /** @var User $user */
            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();

            if ($locked->credits_fulfilled_at === null) {
                if ($locked->plan_key === 'remove_branding') {
                    if (! $this->fulfillRemoveBranding($locked)) {
                        return null;
                    }
                } elseif ($locked->plan_key === 'public_registration_quote') {
                    if (! $this->fulfillPublicRegistrationQuote($locked)) {
                        return null;
                    }
                } else {
                    $quote = $this->resolvePendingQuoteForPayment($locked);

                    if ($locked->plan_key === 'enterprise' && $quote === null) {
                        // Quote vanished or was cancelled after initiate — do not
                        // grant Enterprise or credits for an orphan payment.
                        PaymentLog::forPayment($locked, 'complete.skipped_missing_quote');

                        return null;
                    }

                    // Unused-credit top-ups set credits_granted = 0 (tier only).
                    // EventCreditService::grant() rejects amounts below 1.
                    if ((int) $locked->credits_granted >= 1) {
                        $this->credits->grant(
                            $user,
                            (int) $locked->credits_granted,
                            CreditTransaction::REASON_PURCHASE,
                            $locked
                        );

                        $user->refresh();
                    }

                    $purchasedTier = BillingPlan::tierForPlan($locked->plan_key);
                    if ($purchasedTier->rank() > $user->subscriptionTierRank()) {
                        $user->subscription_tier = $purchasedTier;
                        $user->save();
                    }

                    if ($quote !== null) {
                        $quote->forceFill([
                            'status' => CustomQuoteStatus::Paid,
                            'payment_id' => $locked->id,
                        ])->save();
                    }

                    $locked->credits_fulfilled_at = now();
                    $locked->save();

                    PaymentLog::forPayment($locked, 'complete.fulfilled', [
                        'credits_granted' => $locked->credits_granted,
                        'tier' => $purchasedTier->value,
                    ]);
                }
            }

            return $locked->notified_at === null ? (int) $user->id : null;
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

        $freshPayment->notified_at = now();
        $freshPayment->save();
    }

    /**
     * Sets Event::branding_removed for a completed remove_branding payment.
     * No credits, no tier — just the one flag. Returns false when the target
     * event has gone missing or changed hands since initiate() (the payment
     * still settled; nothing to fulfill against, same posture as an
     * Enterprise payment whose quote vanished).
     */
    private function fulfillRemoveBranding(Payment $payment): bool
    {
        $eventId = data_get($payment->metadata, 'event_id');

        if (! is_numeric($eventId)) {
            PaymentLog::forPayment($payment, 'complete.skipped_missing_event');

            return false;
        }

        /** @var Event|null $event */
        $event = Event::query()->whereKey((int) $eventId)->lockForUpdate()->first();

        if ($event === null || (int) $event->user_id !== (int) $payment->user_id) {
            PaymentLog::forPayment($payment, 'complete.skipped_missing_event');

            return false;
        }

        $event->branding_removed = true;
        $event->save();

        $payment->credits_fulfilled_at = now();
        $payment->save();

        PaymentLog::forPayment($payment, 'complete.fulfilled', [
            'event_id' => $event->id,
            'plan_key' => 'remove_branding',
        ]);

        return true;
    }

    /**
     * Sets Event::is_published and public_registration_quote_paid_at for a
     * completed public_registration_quote payment. No credits, no tier —
     * the admin-set quote is the only price, same posture as
     * fulfillRemoveBranding(). Returns false when the target event has gone
     * missing, changed hands, or is no longer awaiting this payment since
     * initiate() (e.g. re-approved with a different quote after this
     * payment was created) — the payment still settled; nothing to fulfill
     * against.
     */
    private function fulfillPublicRegistrationQuote(Payment $payment): bool
    {
        $eventId = data_get($payment->metadata, 'event_id');

        if (! is_numeric($eventId)) {
            PaymentLog::forPayment($payment, 'complete.skipped_missing_event');

            return false;
        }

        /** @var Event|null $event */
        $event = Event::query()->whereKey((int) $eventId)->lockForUpdate()->first();

        if ($event === null
            || (int) $event->user_id !== (int) $payment->user_id
            || ! $event->awaitingPublicRegistrationPayment()) {
            PaymentLog::forPayment($payment, 'complete.skipped_missing_event');

            return false;
        }

        $event->forceFill([
            'is_published' => true,
            'public_registration_quote_paid_at' => now(),
        ])->save();

        $payment->credits_fulfilled_at = now();
        $payment->save();

        PaymentLog::forPayment($payment, 'complete.fulfilled', [
            'event_id' => $event->id,
            'plan_key' => 'public_registration_quote',
        ]);

        return true;
    }

    private function resolvePendingQuoteForPayment(Payment $payment): ?CustomQuote
    {
        $quoteId = data_get($payment->metadata, 'quote_id');
        if (! is_numeric($quoteId)) {
            return null;
        }

        $quote = CustomQuote::query()->whereKey((int) $quoteId)->lockForUpdate()->first();

        if ($quote === null || (int) $quote->user_id !== (int) $payment->user_id) {
            return null;
        }

        if ($quote->status === CustomQuoteStatus::Paid && (int) $quote->payment_id === (int) $payment->id) {
            return $quote;
        }

        if ($quote->status !== CustomQuoteStatus::Pending) {
            return null;
        }

        return $quote;
    }

    public function reverse(Payment $payment, string $incomingStatus): Payment
    {
        return DB::transaction(function () use ($payment, $incomingStatus): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'completed' || $locked->credits_reversed_at !== null) {
                return $locked;
            }

            /** @var User $user */
            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();

            // remove_branding, public_registration_quote, and unused-credit
            // top-ups (credits_granted = 0) never touch the credit ledger —
            // reversePurchase() would just write a pointless 0-credit refund
            // row for those.
            if ($locked->credits_fulfilled_at !== null
                && (int) $locked->credits_granted >= 1
                && ! in_array($locked->plan_key, ['remove_branding', 'public_registration_quote'], true)) {
                $this->credits->reversePurchase(
                    $user,
                    $locked,
                    'Provider reported '.$incomingStatus
                );
            }

            $locked->status = 'refunded';
            $locked->credits_reversed_at = now();
            $locked->failure_reason = 'Provider reported '.$incomingStatus;
            $locked->save();

            // A reversed Enterprise payment must not leave its quote stuck on
            // "paid" — that reads as settled in the admin UI even though the
            // money came back. Cancel it rather than reopening it as pending:
            // the original custom deal is void, and an admin who wants to
            // re-offer it can issue a fresh quote.
            if ($locked->plan_key === 'enterprise') {
                $quote = CustomQuote::query()
                    ->where('payment_id', $locked->id)
                    ->where('status', CustomQuoteStatus::Paid)
                    ->lockForUpdate()
                    ->first();

                if ($quote !== null) {
                    $quote->forceFill(['status' => CustomQuoteStatus::Cancelled])->save();
                }
            }

            // Likewise, a reversed branding-removal payment must not leave
            // the bar hidden after the money's gone back — same reasoning as
            // the Enterprise quote above.
            if ($locked->plan_key === 'remove_branding' && $locked->credits_fulfilled_at !== null) {
                $eventId = data_get($locked->metadata, 'event_id');
                if (is_numeric($eventId)) {
                    $brandingEvent = Event::query()->whereKey((int) $eventId)->lockForUpdate()->first();
                    if ($brandingEvent !== null && $brandingEvent->branding_removed) {
                        $brandingEvent->branding_removed = false;
                        $brandingEvent->save();
                    }
                }
            }

            // And a reversed public-registration payment must not leave the
            // event live after the money's gone back — un-publish and clear
            // the paid-at so it returns to "approved, awaiting payment".
            if ($locked->plan_key === 'public_registration_quote' && $locked->credits_fulfilled_at !== null) {
                $eventId = data_get($locked->metadata, 'event_id');
                if (is_numeric($eventId)) {
                    $registrationEvent = Event::query()->whereKey((int) $eventId)->lockForUpdate()->first();
                    if ($registrationEvent !== null && $registrationEvent->public_registration_quote_paid_at !== null) {
                        $registrationEvent->forceFill([
                            'is_published' => false,
                            'public_registration_quote_paid_at' => null,
                        ])->save();
                    }
                }
            }

            // Unused-credit top-up: restore the prior tier only when the
            // account is still on the tier this payment raised them to —
            // otherwise a later higher purchase would be clobbered.
            if ($locked->credits_fulfilled_at !== null
                && (bool) data_get($locked->metadata, 'upgrade')
                && is_string(data_get($locked->metadata, 'previous_tier'))) {
                $purchasedTier = BillingPlan::tierForPlan($locked->plan_key);
                $previousTier = SubscriptionTier::normalize((string) data_get($locked->metadata, 'previous_tier'));

                if ($user->subscriptionTier()->rank() === $purchasedTier->rank()) {
                    $user->subscription_tier = $previousTier;
                    $user->save();
                }
            }

            PaymentLog::forPayment($locked, 'complete.reversed', [
                'incoming_status' => $incomingStatus,
            ]);

            return $locked->fresh();
        });
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
