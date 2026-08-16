<?php

namespace App\Console\Commands;

use App\Models\TicketPayment;
use App\Services\LencoService;
use App\Services\TicketPaymentStatusService;
use Illuminate\Console\Command;
use RuntimeException;

class PollPendingTicketPayments extends Command
{
    protected $signature = 'tickets:poll-pending';

    protected $description = 'Poll Lenco for pending ticket payments that may have missed webhook updates';

    public function handle(LencoService $lenco, TicketPaymentStatusService $statusService): int
    {
        $maxAgeHours = (int) config('services.lenco.pending_max_age_hours', 24);
        $maxPerRun = (int) config('services.lenco.poll_max_per_run', 50);
        $throttleMs = (int) config('services.lenco.poll_throttle_ms', 200);
        $headStart = now()->subMinutes(2);

        $apiCalls = 0;
        $processed = 0;
        $forceFailed = 0;

        $expired = TicketPayment::query()
            ->inProgress()
            ->where('created_at', '<', now()->subHours($maxAgeHours))
            ->get();

        foreach ($expired as $payment) {
            $statusService->applyVerificationResult($payment, [
                'status' => 'cancelled',
                'lencoStatus' => 'expired',
                'rawResponse' => [],
            ]);
            $forceFailed++;
            $processed++;
        }

        $withTransactionId = TicketPayment::query()
            ->inProgress()
            ->whereNotNull('lenco_transaction_id')
            ->where('created_at', '<=', $headStart)
            ->orderBy('created_at')
            ->get();

        foreach ($withTransactionId as $payment) {
            if ($apiCalls >= $maxPerRun) {
                break;
            }

            try {
                $verification = $lenco->verifyPayment((string) $payment->lenco_transaction_id);
                $statusService->applyVerificationResult($payment, $verification);
                $processed++;
            } catch (RuntimeException $e) {
                $this->warn("Ticket payment {$payment->id}: {$e->getMessage()}");
            }

            $apiCalls++;
            if ($throttleMs > 0 && $apiCalls < $maxPerRun) {
                usleep($throttleMs * 1000);
            }
        }

        if ($apiCalls < $maxPerRun) {
            $byReference = TicketPayment::query()
                ->where('status', 'pending')
                ->whereNotNull('payment_reference')
                ->whereNull('lenco_transaction_id')
                ->where('created_at', '<=', $headStart)
                ->orderBy('created_at')
                ->get();

            foreach ($byReference as $payment) {
                if ($apiCalls >= $maxPerRun) {
                    break;
                }

                try {
                    $verification = $lenco->verifyByReference((string) $payment->payment_reference);
                    $statusService->applyVerificationResult($payment, $verification);
                    $processed++;
                } catch (RuntimeException $e) {
                    $this->warn("Ticket payment {$payment->id}: {$e->getMessage()}");
                }

                $apiCalls++;
                if ($throttleMs > 0 && $apiCalls < $maxPerRun) {
                    usleep($throttleMs * 1000);
                }
            }
        }

        $this->info("Polled {$processed} ticket payment(s) ({$apiCalls} Lenco call(s), {$forceFailed} force-failed).");

        return self::SUCCESS;
    }
}
