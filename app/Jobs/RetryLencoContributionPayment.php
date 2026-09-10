<?php

namespace App\Jobs;

use App\Models\ContributionPayment;
use App\Services\ContributionPaymentStatusService;
use App\Services\LencoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Twin of RetryLencoTicketPayment — retries a Lenco initiate call that
 * failed transiently (network error / 5xx) when the contributor first
 * submitted an installment.
 */
class RetryLencoContributionPayment implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var list<int> */
    private const BACKOFF = [30, 90, 270];

    /** @var list<int> */
    private const TERMINAL_CODES = [400, 401, 403, 404, 422];

    public function __construct(public ContributionPayment $payment)
    {
        $this->afterCommit();
        $this->onQueue('payments');
    }

    public function handle(LencoService $lenco, ContributionPaymentStatusService $statusService): void
    {
        $this->payment->refresh();

        if ($this->payment->lenco_transaction_id) {
            return;
        }

        if ($this->payment->isTerminal()) {
            return;
        }

        $contribution = $this->payment->contribution()->with('event')->first();
        if ($contribution === null) {
            return;
        }

        $metadata = $this->payment->metadata ?? [];

        $context = [
            'user_id' => 0,
            'ref' => $this->payment->payment_reference,
            'amount' => (float) $this->payment->amount,
            'currency' => $this->payment->currency,
            'description' => 'Contribution — '.($contribution->event?->name ?? 'event'),
            'reference' => $this->payment->payment_reference,
        ];

        try {
            $result = $this->payment->payment_method === 'bank_transfer'
                ? $lenco->initiateBankTransfer($context, [
                    'bankName' => (string) ($metadata['bank_name'] ?? ''),
                ])
                : $lenco->initiateMobileMoneyPayment(
                    $context,
                    (string) ($metadata['phone'] ?? ''),
                    (string) ($metadata['provider'] ?? $this->payment->provider ?? 'mtn'),
                );

            $statusService->applyInitiateResult($this->payment, array_merge($result, [
                'provider' => $result['provider'] ?? $this->payment->provider,
                'paymentInstructions' => $result['paymentInstructions'] ?? $this->payment->payment_instructions,
                'bankDetails' => $result['bankDetails'] ?? $this->payment->bank_details,
                'paymentUrl' => $result['paymentUrl'] ?? $this->payment->payment_url,
            ]));
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();

            if (in_array($code, self::TERMINAL_CODES, true) || $this->attempts() >= $this->tries) {
                $statusService->markFailed($this->payment, $e->getMessage());
                $this->fail($e);

                return;
            }

            $delay = self::BACKOFF[$this->attempts() - 1] ?? 270;
            $this->release($delay);
        }
    }

    public function failed(\Throwable $e): void
    {
        if ($this->payment->fresh()?->isTerminal()) {
            return;
        }

        app(ContributionPaymentStatusService::class)->markFailed($this->payment, $e->getMessage());
    }
}
