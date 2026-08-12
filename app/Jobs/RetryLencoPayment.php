<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\LencoService;
use App\Services\PaymentCompletionService;
use App\Support\BillingPlan;
use App\Support\PaymentLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class RetryLencoPayment implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var list<int> */
    private const BACKOFF = [30, 90, 270];

    /** @var list<int> */
    private const TERMINAL_CODES = [400, 401, 403, 404, 422];

    public function __construct(public Payment $payment)
    {
        $this->afterCommit();
        $this->onQueue('payments');
    }

    public function handle(LencoService $lenco): void
    {
        $this->payment->refresh();

        if ($this->payment->lenco_transaction_id) {
            return;
        }

        if ($this->payment->isTerminal()) {
            return;
        }

        PaymentLog::forPayment($this->payment, 'retry.attempt', [
            'attempt' => $this->attempts(),
        ]);

        $plan = BillingPlan::get($this->payment->plan_key);
        $planLabel = is_array($plan) ? ($plan['label'] ?? $this->payment->plan_key) : $this->payment->plan_key;
        $metadata = $this->payment->metadata ?? [];

        $context = [
            'user_id' => $this->payment->user_id,
            'ref' => $this->payment->user_ref,
            'amount' => (float) $this->payment->amount,
            'currency' => $this->payment->currency,
            'description' => "Event Host — {$planLabel} event credit",
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

            $mappedStatus = LencoService::mapStatus((string) ($result['status'] ?? 'pending'));

            $this->payment->update([
                'status' => $mappedStatus === 'completed' ? 'completed' : ($mappedStatus === 'processing' ? 'processing' : 'pending'),
                'lenco_transaction_id' => $result['transactionId'] ?? null,
                'lenco_reference' => $result['lencoReference'] ?? null,
                'lenco_status' => $result['status'] ?? null,
                'lenco_response' => $result['rawResponse'] ?? null,
                'payment_instructions' => $result['paymentInstructions'] ?? $this->payment->payment_instructions,
                'bank_details' => $result['bankDetails'] ?? $this->payment->bank_details,
                'payment_url' => $result['paymentUrl'] ?? $this->payment->payment_url,
                'provider' => $result['provider'] ?? $this->payment->provider,
            ]);

            if ($this->payment->status === 'completed') {
                app(PaymentCompletionService::class)->complete($this->payment->fresh());
            }
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();

            if (in_array($code, self::TERMINAL_CODES, true) || $this->attempts() >= $this->tries) {
                $this->payment->update([
                    'status' => 'failed',
                    'failure_reason' => $e->getMessage(),
                    'failed_at' => now(),
                ]);
                PaymentLog::forPayment($this->payment->fresh(), 'retry.terminal_failure', [
                    'code' => $code,
                    'message' => $e->getMessage(),
                ], 'error');
                $this->fail($e);

                return;
            }

            $delay = self::BACKOFF[$this->attempts() - 1] ?? 270;
            $this->release($delay);
        }
    }

    public function failed(\Throwable $e): void
    {
        if ($this->payment->fresh()?->status !== 'failed') {
            $this->payment->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
                'failed_at' => now(),
            ]);
        }
    }
}
