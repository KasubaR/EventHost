<?php

namespace App\Http\Controllers;

use App\Http\Requests\InitiatePaymentRequest;
use App\Jobs\RetryLencoPayment;
use App\Models\Payment;
use App\Models\User;
use App\Services\LencoService;
use App\Services\PaymentCompletionService;
use App\Services\PaymentStatusService;
use App\Support\BillingPlan;
use App\Support\PaymentLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class PaymentController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $environment = (string) config('services.lenco.environment', 'sandbox');
        $cacheKey = "lenco.banks.zm.{$environment}";

        $banks = Cache::remember($cacheKey, now()->addHours(6), function () {
            try {
                $fetched = app(LencoService::class)->getBanks();
                if ($fetched !== []) {
                    return $fetched;
                }
            } catch (\Throwable $e) {
                PaymentLog::info('banks.fetch_failed', [
                    'message' => $e->getMessage(),
                ]);
            }

            return BillingPlan::fallbackBanks();
        });

        return view('billing.checkout', [
            'plans' => BillingPlan::all(),
            'currency' => BillingPlan::currency(),
            'banks' => $banks,
            'user' => $user,
            'selectedPlan' => $request->query('plan'),
            'bankTransferEnabled' => (bool) config('services.lenco.bank_transfer_enabled', true),
        ]);
    }

    public function initiate(InitiatePaymentRequest $request, LencoService $lenco): JsonResponse
    {
        $user = $request->user();
        if (! $user->isActive()) {
            return response()->json(['success' => false, 'message' => 'Account is not active.'], 403);
        }

        $plan = BillingPlan::get($request->string('plan_key')->toString());
        if ($plan === null) {
            return response()->json(['success' => false, 'message' => 'Invalid plan.'], 422);
        }

        $amount = (float) $plan['amount'];
        if ($amount <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid plan amount.'], 422);
        }

        $method = $request->string('payment_method')->toString();
        $userRef = 'USR-'.$user->id;
        $planLabel = (string) ($plan['label'] ?? $request->string('plan_key'));
        $description = "Event Host — {$planLabel} event credit";

        try {
            return DB::transaction(function () use ($request, $lenco, $user, $plan, $amount, $method, $userRef, $planLabel, $description): JsonResponse {
                $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                $inProgress = Payment::query()
                    ->forUser($lockedUser->id)
                    ->inProgress()
                    ->exists();

                if ($inProgress) {
                    PaymentLog::warning('initiate.duplicate_in_progress', [
                        'user_id' => $lockedUser->id,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'You already have a payment in progress.',
                    ], 409);
                }

                $reference = $lenco->generatePaymentReference($lockedUser->id);
                $context = [
                    'user_id' => $lockedUser->id,
                    'ref' => $userRef,
                    'amount' => $amount,
                    'currency' => BillingPlan::currency(),
                    'description' => $description,
                    'reference' => $reference,
                ];

                try {
                    $result = $method === 'mobile_money'
                        ? $lenco->initiateMobileMoneyPayment(
                            $context,
                            (string) $request->input('phone'),
                            (string) $request->input('provider'),
                        )
                        : $lenco->initiateBankTransfer($context, [
                            'bankName' => (string) $request->input('bank_name'),
                        ]);
                } catch (RuntimeException $e) {
                    $code = (int) $e->getCode();
                    if ($code === 0 || $code >= 500) {
                        $payment = $this->createPendingPayment($lockedUser, $request, $plan, $reference, $userRef, $amount, $method, [
                            'phone' => $request->input('phone'),
                            'provider' => $request->input('provider'),
                            'bank_name' => $request->input('bank_name'),
                            'queued_reason' => $e->getMessage(),
                        ]);

                        RetryLencoPayment::dispatch($payment)->afterCommit();

                        PaymentLog::forPayment($payment, 'initiate.queued', [
                            'reason' => $e->getMessage(),
                        ]);

                        return response()->json([
                            'success' => true,
                            'status' => 'queued',
                            'payment_reference' => $payment->payment_reference,
                            'message' => 'Payment is being processed. Please wait…',
                        ]);
                    }

                    throw $e;
                }

                $mappedStatus = LencoService::mapStatus((string) ($result['status'] ?? 'pending'));

                $payment = Payment::query()->create([
                    'user_id' => $lockedUser->id,
                    'plan_key' => $request->string('plan_key')->toString(),
                    'credits_granted' => (int) ($plan['credits'] ?? 1),
                    'user_ref' => $userRef,
                    'payment_method' => $method,
                    'provider' => $result['provider'] ?? $request->input('provider'),
                    'amount' => $amount,
                    'currency' => BillingPlan::currency(),
                    'status' => $mappedStatus === 'completed' ? 'completed' : ($mappedStatus === 'processing' ? 'processing' : 'pending'),
                    'lenco_transaction_id' => $result['transactionId'] ?? null,
                    'lenco_reference' => $result['lencoReference'] ?? null,
                    'lenco_status' => $result['status'] ?? null,
                    'lenco_response' => $result['rawResponse'] ?? null,
                    'payment_reference' => $reference,
                    'payment_instructions' => $result['paymentInstructions'] ?? null,
                    'bank_details' => $result['bankDetails'] ?? null,
                    'payment_url' => $result['paymentUrl'] ?? null,
                    'expires_at' => now()->addHours(24),
                    'metadata' => [
                        'phone' => $request->input('phone'),
                        'bank_name' => $request->input('bank_name'),
                    ],
                ]);

                if ($payment->status === 'completed') {
                    app(PaymentCompletionService::class)->complete($payment);
                }

                PaymentLog::forPayment($payment, 'initiate.success', [
                    'plan_key' => $payment->plan_key,
                    'method' => $method,
                ]);

                return response()->json([
                    'success' => true,
                    'status' => $payment->status,
                    'transaction_id' => $payment->lenco_transaction_id,
                    'payment_reference' => $payment->payment_reference,
                    'payment_instructions' => $payment->payment_instructions,
                    'bank_details' => $payment->bank_details,
                    'payment_url' => $payment->payment_url,
                    'plan_label' => $planLabel,
                ]);
            });
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();
            $message = $e->getMessage();

            if ($method === 'bank_transfer' && in_array($code, [404, 422], true)) {
                $message = 'Bank transfer is unavailable right now. Please use mobile money or contact support.';
            }

            PaymentLog::error('initiate.failed', [
                'user_id' => $user->id,
                'method' => $method,
                'code' => $code,
                'message' => $message,
            ]);

            return response()->json([
                'success' => false,
                'message' => $message,
            ], max(400, min(503, $code ?: 502)));
        }
    }

    public function verify(string $transactionId, LencoService $lenco, PaymentStatusService $statusService): JsonResponse
    {
        if (! preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $transactionId)) {
            return response()->json(['success' => false, 'message' => 'Invalid transaction ID.'], 422);
        }

        $payment = Payment::query()
            ->where('lenco_transaction_id', $transactionId)
            ->when(auth()->check(), fn ($q) => $q->where('user_id', auth()->id()))
            ->first();

        if ($payment === null) {
            return response()->json(['success' => false, 'message' => 'Payment not found.'], 404);
        }

        if ($payment->isTerminal()) {
            return $this->paymentStatusResponse($payment);
        }

        if ($payment->lenco_transaction_id === null) {
            return $this->paymentStatusResponse($payment);
        }

        try {
            $verification = $lenco->verifyPayment($transactionId);
            $payment = $statusService->applyVerificationResult($payment, $verification);
            PaymentLog::forPayment($payment, 'verify.by_id', [
                'transaction_id' => $transactionId,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'status' => $payment->status,
                'message' => $e->getMessage(),
            ], 502);
        }

        return $this->paymentStatusResponse($payment->fresh());
    }

    public function verifyByReference(string $reference, LencoService $lenco, PaymentStatusService $statusService): JsonResponse
    {
        if (! preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $reference)) {
            return response()->json(['success' => false, 'message' => 'Invalid reference.'], 422);
        }

        $payment = Payment::query()
            ->where('payment_reference', $reference)
            ->when(auth()->check(), fn ($q) => $q->where('user_id', auth()->id()))
            ->first();

        if ($payment === null) {
            return response()->json(['success' => false, 'message' => 'Payment not found.'], 404);
        }

        if ($payment->isTerminal()) {
            return $this->paymentStatusResponse($payment);
        }

        try {
            $verification = $lenco->verifyByReference($reference);
            $payment = $statusService->applyVerificationResult($payment, $verification);
            PaymentLog::forPayment($payment, 'verify.by_reference', [
                'reference' => $reference,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'status' => $payment->status,
                'message' => $e->getMessage(),
            ], 502);
        }

        return $this->paymentStatusResponse($payment->fresh());
    }

    public function webhook(Request $request, LencoService $lenco, PaymentStatusService $statusService): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('X-Lenco-Signature', '');

        if (! $lenco->validateWebhookSignature($rawBody, $signature)) {
            PaymentLog::warning('webhook.invalid_signature');

            return response()->json(['success' => false, 'message' => 'Invalid signature'], 401);
        }

        try {
            $webhook = $lenco->extractWebhookData($rawBody);
        } catch (RuntimeException) {
            PaymentLog::warning('webhook.invalid_payload');

            return response()->json(['success' => false, 'message' => 'Invalid payload']);
        }

        $payment = Payment::findForLencoWebhook(
            isset($webhook['reference']) ? (string) $webhook['reference'] : null,
            isset($webhook['transactionId']) ? (string) $webhook['transactionId'] : null,
            isset($webhook['lencoReference']) ? (string) $webhook['lencoReference'] : null,
        );

        if ($payment === null) {
            PaymentLog::info('webhook.acknowledged_unmatched', [
                'reference' => $webhook['reference'] ?? null,
            ]);

            return response()->json(['success' => true, 'message' => 'acknowledged']);
        }

        $mappedStatus = LencoService::mapStatus((string) ($webhook['lencoStatus'] ?? 'pending'));
        $isReversal = $payment->status === 'completed'
            && PaymentCompletionService::isReversalStatus($mappedStatus)
            && $payment->credits_reversed_at === null;

        if ($payment->isTerminal() && ! $isReversal) {
            PaymentLog::forPayment($payment, 'webhook.already_processed');

            return response()->json(['success' => true, 'message' => 'already processed']);
        }

        $payment->update([
            'webhook_received' => true,
            'webhook_payload' => $webhook['raw'] ?? null,
            'webhook_received_at' => now(),
        ]);

        $statusService->applyWebhook($payment->fresh(), $webhook);

        PaymentLog::forPayment($payment->fresh(), 'webhook.processed');

        return response()->json(['success' => true, 'message' => 'processed']);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $metadata
     */
    private function createPendingPayment(
        User $user,
        InitiatePaymentRequest $request,
        array $plan,
        string $reference,
        string $userRef,
        float $amount,
        string $method,
        array $metadata = [],
    ): Payment {
        return Payment::query()->create([
            'user_id' => $user->id,
            'plan_key' => $request->string('plan_key')->toString(),
            'credits_granted' => (int) ($plan['credits'] ?? 1),
            'user_ref' => $userRef,
            'payment_method' => $method,
            'provider' => $request->input('provider'),
            'amount' => $amount,
            'currency' => BillingPlan::currency(),
            'status' => 'pending',
            'payment_reference' => $reference,
            'expires_at' => now()->addHours(24),
            'metadata' => $metadata,
        ]);
    }

    private function paymentStatusResponse(Payment $payment): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status' => $payment->status,
            'transaction_id' => $payment->lenco_transaction_id,
            'payment_reference' => $payment->payment_reference,
            'failure_reason' => $payment->failure_reason,
            'redirect_url' => $payment->status === 'completed' ? route('events.create') : null,
        ]);
    }
}
