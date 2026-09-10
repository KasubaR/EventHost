<?php

namespace App\Services;

use App\Enums\ContributionStatus;
use App\Exceptions\ContributionPaymentException;
use App\Jobs\RetryLencoContributionPayment;
use App\Models\ContributionPayment;
use App\Models\Event;
use App\Models\EventContribution;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Finds-or-creates a contributor's pledge and turns one installment into a
 * ContributionPayment + Lenco charge — the contribution equivalent of
 * TicketCheckoutService, minus the cart/hold step (contributions aren't
 * inventory-limited, so there is nothing to reserve). See
 * plans/contributions.md.
 */
class ContributionCheckoutService
{
    public const PAYMENT_EXPIRES_HOURS = 1;

    public function __construct(
        private readonly LencoService $lenco,
        private readonly ContributionPaymentStatusService $paymentStatus,
    ) {}

    /**
     * Find an in-progress pledge for this event + phone (so a contributor
     * paying a second installment doesn't fork into a second pledge), or
     * start a new one with the target snapshotted from the event's current
     * admin-set amount.
     *
     * @param  array{name: string, phone: string, email?: ?string}  $contributor
     */
    public function startOrResume(Event $event, array $contributor): EventContribution
    {
        return DB::transaction(function () use ($event, $contributor): EventContribution {
            $normalized = EventContribution::normalizePhone($contributor['phone']);

            $existing = EventContribution::query()
                ->where('event_id', $event->id)
                ->whereIn('status', [ContributionStatus::Pending, ContributionStatus::Partial])
                ->lockForUpdate()
                ->get()
                ->first(fn (EventContribution $c): bool => EventContribution::normalizePhone($c->contributor_phone) === $normalized);

            if ($existing !== null) {
                return $existing;
            }

            return EventContribution::query()->create([
                'event_id' => $event->id,
                'reference' => EventContribution::generateReference($event->id),
                'contributor_name' => $contributor['name'],
                'contributor_phone' => $contributor['phone'],
                'contributor_email' => $contributor['email'] ?? null,
                'target_amount' => $event->contribution_amount,
                'amount_paid' => 0,
                'currency' => 'ZMW',
                'status' => ContributionStatus::Pending,
            ]);
        });
    }

    /**
     * @param  array{method: string, provider?: ?string, phone?: ?string, bank_name?: ?string}  $payment
     * @return array{contribution: EventContribution, result: array<string, mixed>}
     */
    public function pay(EventContribution $contribution, float $amount, array $payment): array
    {
        $event = $contribution->event()->firstOrFail();

        $prepared = DB::transaction(function () use ($contribution, $amount, $payment): array {
            /** @var EventContribution $locked */
            $locked = EventContribution::query()->whereKey($contribution->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ContributionStatus::Completed) {
                throw new ContributionPaymentException('This contribution has already been paid in full.');
            }

            $inProgress = ContributionPayment::query()
                ->where('event_contribution_id', $locked->id)
                ->inProgress()
                ->exists();

            if ($inProgress) {
                throw new ContributionPaymentException('You already have a payment in progress for this contribution.');
            }

            $remaining = $locked->remainingAmount();
            $amount = round($amount, 2);

            // Half a cent of slack absorbs float rounding on the remaining-
            // balance display the contributor was shown, not a policy to pay
            // more than what's left.
            if ($amount <= 0 || $amount > $remaining + 0.01) {
                throw new ContributionPaymentException(
                    'Enter an amount up to the remaining balance of K'.number_format($remaining, 2).'.'
                );
            }

            $expiresAt = now()->addHours(self::PAYMENT_EXPIRES_HOURS);
            $reference = ContributionPayment::generateReference($locked->id);

            $contributionPayment = ContributionPayment::query()->create([
                'event_contribution_id' => $locked->id,
                'provider' => $payment['provider'] ?? null,
                'payment_method' => $payment['method'],
                'amount' => $amount,
                'currency' => 'ZMW',
                'status' => 'pending',
                'payment_reference' => $reference,
                'expires_at' => $expiresAt,
                'metadata' => [
                    'phone' => $payment['phone'] ?? null,
                    'provider' => $payment['provider'] ?? null,
                    'bank_name' => $payment['bank_name'] ?? null,
                ],
            ]);

            return ['contributionPayment' => $contributionPayment, 'amount' => $amount];
        });

        /** @var ContributionPayment $contributionPayment */
        $contributionPayment = $prepared['contributionPayment'];
        $amount = $prepared['amount'];

        $context = [
            'user_id' => 0,
            'ref' => $contributionPayment->payment_reference,
            'amount' => (float) $amount,
            'currency' => 'ZMW',
            'description' => 'Contribution — '.$event->name,
            'reference' => $contributionPayment->payment_reference,
        ];

        try {
            $result = $payment['method'] === 'bank_transfer'
                ? $this->lenco->initiateBankTransfer($context, [
                    'bankName' => (string) ($payment['bank_name'] ?? ''),
                ])
                : $this->lenco->initiateMobileMoneyPayment(
                    $context,
                    (string) ($payment['phone'] ?? ''),
                    (string) ($payment['provider'] ?? 'mtn'),
                );
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();
            if ($code === 0 || $code >= 500) {
                RetryLencoContributionPayment::dispatch($contributionPayment);

                return [
                    'contribution' => $contribution->fresh(),
                    'result' => [
                        'status' => 'queued',
                        'paymentInstructions' => null,
                        'bankDetails' => null,
                        'paymentUrl' => null,
                    ],
                ];
            }

            $this->paymentStatus->markFailed($contributionPayment, 'Lenco initiate failed: '.$e->getMessage());

            throw $e;
        }

        $contributionPayment = $this->paymentStatus->applyInitiateResult($contributionPayment, array_merge($result, [
            'provider' => $result['provider'] ?? ($payment['provider'] ?? $contributionPayment->provider),
        ]));

        if ($contributionPayment->status === 'failed'
            && $contributionPayment->failure_reason === 'Amount or currency mismatch with provider settlement') {
            throw new ContributionPaymentException('Payment amount did not match what was charged. Please try again.');
        }

        return ['contribution' => $contribution->fresh(), 'result' => $result];
    }
}
