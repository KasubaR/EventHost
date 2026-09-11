<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ContributionPaymentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContributionInstallmentRequest;
use App\Http\Requests\StoreContributionPledgeRequest;
use App\Http\Resources\Api\V1\ContributionCheckoutResultResource;
use App\Http\Resources\Api\V1\ContributionResource;
use App\Http\Resources\Api\V1\PublicEventListResource;
use App\Models\EventContribution;
use App\Services\ContributionCheckoutService;
use App\Services\ContributionPaymentStatusService;
use App\Services\LencoService;
use App\Services\PublicInvitationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * JSON sibling of App\Http\Controllers\EventContributionController — no session, no
 * Blade view. store()/pay()/verify() bodies are copied verbatim from the web controller
 * (which already returns pure JSON for all three); only statusResponse() diverges by
 * dropping redirect_url. show()/status() are new JSON twins of the web show()/status()
 * Blade views. The web controller is untouched by this class.
 */
class EventContributionController extends Controller
{
    public function show(string $slug, PublicInvitationResolver $resolver): JsonResponse|RedirectResponse
    {
        $event = $resolver->resolveForContributions($slug);

        if ($event instanceof RedirectResponse) {
            return $event;
        }

        return response()->json([
            'event' => new PublicEventListResource($event),
            'bank_transfer_enabled' => (bool) config('services.lenco.bank_transfer_enabled', true),
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
            // Must precede the RuntimeException catch below — ContributionPaymentException
            // extends it.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();

            return response()->json(['success' => false, 'message' => $e->getMessage()], max(400, min(503, $code ?: 502)));
        }

        // $contribution may be a brand-new EventContribution row (startOrResume() creates one
        // when no matching pledge exists) — forced to 200, not left to JsonResource's automatic
        // wasRecentlyCreated-based status calculation. See the Slice B3 plan, decision #4.
        return (new ContributionCheckoutResultResource($contribution, $result))->response()->setStatusCode(200);
    }

    public function status(string $reference): ContributionResource|JsonResponse
    {
        $contribution = EventContribution::findByReferenceOrToken($reference)
            ?->load(['event', 'payments' => fn ($q) => $q->latest()]);

        if ($contribution === null) {
            return response()->json(['success' => false, 'message' => 'Contribution not found.'], 404);
        }

        return new ContributionResource($contribution);
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

        return (new ContributionCheckoutResultResource($contribution, $result))->response()->setStatusCode(200);
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

    /**
     * Same as web's statusResponse() except redirect_url is dropped — it points at the
     * Blade contribution-status page, meaningless for a native client.
     */
    private function statusResponse(EventContribution $contribution): JsonResponse
    {
        $payment = $contribution->payments()->latest()->first();

        return response()->json([
            'success' => true,
            'status' => $contribution->status->value,
            'remaining_amount' => number_format($contribution->remainingAmount(), 2, '.', ''),
            'failure_reason' => $payment?->failure_reason,
        ]);
    }
}
