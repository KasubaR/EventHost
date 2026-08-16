<?php

namespace App\Jobs;

use App\Models\TicketPayment;
use App\Services\LencoService;
use App\Services\TicketPaymentStatusService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class RetryLencoTicketPayment implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var list<int> */
    private const BACKOFF = [30, 90, 270];

    /** @var list<int> */
    private const TERMINAL_CODES = [400, 401, 403, 404, 422];

    public function __construct(public TicketPayment $payment)
    {
        $this->afterCommit();
        $this->onQueue('payments');
    }

    public function handle(LencoService $lenco, TicketPaymentStatusService $statusService): void
    {
        $this->payment->refresh();

        if ($this->payment->lenco_transaction_id) {
            return;
        }

        if ($this->payment->isTerminal()) {
            return;
        }

        $order = $this->payment->order()->with('event')->first();
        if ($order === null) {
            return;
        }

        $metadata = $this->payment->metadata ?? [];

        $context = [
            'user_id' => 0,
            'ref' => $this->payment->payment_reference,
            'amount' => (float) $this->payment->amount,
            'currency' => $this->payment->currency,
            'description' => 'Tickets — '.($order->event?->name ?? 'event'),
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

        app(TicketPaymentStatusService::class)->markFailed($this->payment, $e->getMessage());
    }
}
