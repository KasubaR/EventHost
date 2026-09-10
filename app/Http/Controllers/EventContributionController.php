<?php

namespace App\Http\Controllers;

use App\Exceptions\ContributionPaymentException;
use App\Http\Requests\StoreContributionInstallmentRequest;
use App\Http\Requests\StoreContributionPledgeRequest;
use App\Models\EventContribution;
use App\Services\ContributionCheckoutService;
use App\Services\ContributionPaymentStatusService;
use App\Services\LencoService;
use App\Services\PublicInvitationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

/**
 * Guest-facing contribute flow — no login. Twin of EventTicketCheckoutController,
 * minus the cart/hold step: contributions aren't inventory-limited, so a
 * contributor goes straight from "who are you" to "how much, paid how".
 * See plans/contributions.md.
 */
class EventContributionController extends Controller
{
    public function show(string $slug, PublicInvitationResolver $resolver): View|RedirectResponse
    {
        $event = $resolver->resolveForContributions($slug);

        if ($event instanceof RedirectResponse) {
            return $event;
        }

        return view('events.contribute', [
            'event' => $event,
            'bankTransferEnabled' => (bool) config('services.lenco.bank_transfer_enabled', true),
        ]);
    }

    public function store(
        string $slug,
        StoreContributionPledgeRequest $request,
        ContributionCheckoutService $checkout,
        PublicInvitationResolver $resolver,
    ): JsonResponse|RedirectResponse {
        $event = $resolver->resolveForContributions($slug);

        if ($event instanceof RedirectResponse) {
            return $event;
        }

        $contribution = $checkout->startOrResume($event, [
            'name' => $request->string('name')->toString(),
            'phone' => $request->string('phone')->toString(),
            'email' => $request->input('email'),
        ]);

        try {
            ['contribution' => $contribution, 'result' => $result] = $checkout->pay(
                $contribution,
                (float) $request->input('amount'),
                [
                    'method' => $request->string('payment_method')->toString(),
                    'provider' => $request->input('provider'),
                    'phone' => $request->input('momo_phone'),
                    'bank_name' => $request->input('bank_name'),
                ],
            );
        } catch (ContributionPaymentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();

            return response()->json(['success' => false, 'message' => $e->getMessage()], max(400, min(503, $code ?: 502)));
        }

        return response()->json([
            'success' => true,
            'status' => $contribution->status->value,
            'reference' => $contribution->reference,
            'payment_instructions' => $result['paymentInstructions'] ?? null,
            'bank_details' => $result['bankDetails'] ?? null,
            'payment_url' => $result['paymentUrl'] ?? null,
        ]);
    }

    public function status(string $reference): View
    {
        $contribution = EventContribution::query()
            ->where('reference', $reference)
            ->with(['event', 'payments' => fn ($q) => $q->latest()])
            ->firstOrFail();

        return view('events.contribution-status', ['contribution' => $contribution]);
    }

    public function pay(
        string $reference,
        StoreContributionInstallmentRequest $request,
        ContributionCheckoutService $checkout,
    ): JsonResponse {
        $contribution = EventContribution::findByReferenceOrToken($reference);

        if ($contribution === null) {
            return response()->json(['success' => false, 'message' => 'Contribution not found.'], 404);
        }

        try {
            ['contribution' => $contribution, 'result' => $result] = $checkout->pay(
                $contribution,
                (float) $request->input('amount'),
                [
                    'method' => $request->string('payment_method')->toString(),
                    'provider' => $request->input('provider'),
                    'phone' => $request->input('momo_phone'),
                    'bank_name' => $request->input('bank_name'),
                ],
            );
        } catch (ContributionPaymentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();

            return response()->json(['success' => false, 'message' => $e->getMessage()], max(400, min(503, $code ?: 502)));
        }

        return response()->json([
            'success' => true,
            'status' => $contribution->status->value,
            'payment_instructions' => $result['paymentInstructions'] ?? null,
            'bank_details' => $result['bankDetails'] ?? null,
            'payment_url' => $result['paymentUrl'] ?? null,
        ]);
    }

    public function verify(string $reference, LencoService $lenco, ContributionPaymentStatusService $statusService): JsonResponse
    {
        $contribution = EventContribution::findByReferenceOrToken($reference);

        if ($contribution === null) {
            return response()->json(['success' => false, 'message' => 'Contribution not found.'], 404);
        }

        $payment = $contribution->payments()->latest()->first();

        if ($payment === null) {
            return $this->statusResponse($contribution);
        }

        if ($payment->isTerminal() && ! $payment->canRecoverToCompleted()) {
            return $this->statusResponse($contribution->fresh());
        }

        try {
            $verification = $lenco->verifyByReference($payment->payment_reference);
            $statusService->applyVerificationResult($payment, $verification);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'status' => $contribution->status->value,
                'message' => $e->getMessage(),
            ], 502);
        }

        return $this->statusResponse($contribution->fresh());
    }

    private function statusResponse(EventContribution $contribution): JsonResponse
    {
        $payment = $contribution->payments()->latest()->first();

        return response()->json([
            'success' => true,
            'status' => $contribution->status->value,
            'remaining_amount' => $contribution->remainingAmount(),
            'redirect_url' => route('contributions.show', $contribution->reference),
            'failure_reason' => $payment?->failure_reason,
        ]);
    }
}
