<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\LencoService;
use App\Services\PaymentStatusService;
use App\Support\PaymentLog;
use Illuminate\Console\Command;
use RuntimeException;

class PollPendingPayments extends Command
{
    protected $signature = 'payments:poll-pending';

    protected $description = 'Poll Lenco for pending payments that may have missed webhook updates';

    public function handle(LencoService $lenco, PaymentStatusService $statusService): int
    {
        $maxAgeHours = (int) config('services.lenco.pending_max_age_hours', 24);
        $maxPerRun = (int) config('services.lenco.poll_max_per_run', 50);
        $throttleMs = (int) config('services.lenco.poll_throttle_ms', 200);
        $headStart = now()->subMinutes(2);

        $apiCalls = 0;
        $processed = 0;
        $forceFailed = 0;

        $expired = Payment::query()
            ->inProgress()
            ->where('created_at', '<', now()->subHours($maxAgeHours))
            ->get();

        foreach ($expired as $payment) {
            $statusService->applyVerificationResult($payment, [
                'status' => 'cancelled',
                'lencoStatus' => 'expired',
                'rawResponse' => [],
            ]);
            PaymentLog::forPayment($payment->fresh(), 'poll.force_failed_max_age', [
                'max_age_hours' => $maxAgeHours,
            ], 'warning');
            $forceFailed++;
            $processed++;
        }

        $branchA = Payment::query()
            ->inProgress()
            ->whereNotNull('lenco_transaction_id')
            ->where('created_at', '<=', $headStart)
            ->orderBy('created_at')
            ->get();

        foreach ($branchA as $payment) {
            if ($apiCalls >= $maxPerRun) {
                break;
            }

            try {
                $verification = $lenco->verifyPayment((string) $payment->lenco_transaction_id);
                $statusService->applyVerificationResult($payment, $verification);
                PaymentLog::forPayment($payment->fresh(), 'poll.verify_by_id', [
                    'mapped_status' => $verification['status'] ?? null,
                ]);
                $processed++;
            } catch (RuntimeException $e) {
                PaymentLog::forPayment($payment, 'poll.verify_by_id_failed', [
                    'message' => $e->getMessage(),
                ], 'warning');
                $this->warn("Payment {$payment->id}: {$e->getMessage()}");
            }

            $apiCalls++;
            if ($throttleMs > 0 && $apiCalls < $maxPerRun) {
                usleep($throttleMs * 1000);
            }
        }

        if ($apiCalls < $maxPerRun) {
            $branchB = Payment::query()
                ->where('status', 'pending')
                ->where('payment_method', 'mobile_money')
                ->whereNotNull('payment_reference')
                ->whereNull('lenco_transaction_id')
                ->where('created_at', '<=', $headStart)
                ->orderBy('created_at')
                ->get();

            foreach ($branchB as $payment) {
                if ($apiCalls >= $maxPerRun) {
                    break;
                }

                try {
                    $verification = $lenco->verifyByReference((string) $payment->payment_reference);
                    $statusService->applyVerificationResult($payment, $verification);
                    PaymentLog::forPayment($payment->fresh(), 'poll.verify_by_reference', [
                        'mapped_status' => $verification['status'] ?? null,
                    ]);
                    $processed++;
                } catch (RuntimeException $e) {
                    PaymentLog::forPayment($payment, 'poll.verify_by_reference_failed', [
                        'message' => $e->getMessage(),
                    ], 'warning');
                    $this->warn("Payment {$payment->id}: {$e->getMessage()}");
                }

                $apiCalls++;
                if ($throttleMs > 0 && $apiCalls < $maxPerRun) {
                    usleep($throttleMs * 1000);
                }
            }
        }

        PaymentLog::info('poll.completed', [
            'processed' => $processed,
            'force_failed' => $forceFailed,
            'api_calls' => $apiCalls,
        ]);

        $this->info("Polled {$processed} payment(s) ({$apiCalls} Lenco call(s), {$forceFailed} force-failed).");

        return self::SUCCESS;
    }
}
