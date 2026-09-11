<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\TicketPurchaseException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTicketCheckoutApiRequest;
use App\Http\Resources\Api\V1\TicketCheckoutResultResource;
use App\Http\Resources\Api\V1\TicketHoldResource;
use App\Http\Resources\Api\V1\TicketOrderResource;
use App\Models\Event;
use App\Models\TicketOrder;
use App\Services\LencoService;
use App\Services\PublicInvitationResolver;
use App\Services\TicketCheckoutService;
use App\Services\TicketPaymentStatusService;
use App\Services\TicketReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * JSON sibling of App\Http\Controllers\EventTicketCheckoutController — no session, no
 * redirect+flash, no Blade view. cart_id is an explicit param/field everywhere instead
 * of read from TicketCart's session value. verify()'s body is copied verbatim from web
 * except statusResponse() drops redirect_url (a web-only Blade URL). The web controller
 * is untouched by this class. See the Slice B2 plan for the full rationale.
 */
class EventTicketCheckoutController extends Controller
{
    public function show(
        string $slug,
        Request $request,
        TicketReservationService $reservations,
        PublicInvitationResolver $resolver,
    ): TicketHoldResource|JsonResponse|RedirectResponse {
        $event = $this->resolveEvent($slug, $resolver);

        if ($event instanceof RedirectResponse) {
            return $event;
        }

        $cartId = $request->query('cart_id');

        if (! is_string($cartId) || $cartId === '') {
            return response()->json(['success' => false, 'message' => 'cart_id is required.'], 422);
        }

        $held = $reservations->activeForCart($event, $cartId);

        if ($held->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Your ticket hold has expired. Please choose your tickets again.',
            ], 422);
        }

        return new TicketHoldResource($event, $cartId, $held);
    }

    public function store(
        string $slug,
        StoreTicketCheckoutApiRequest $request,
        TicketCheckoutService $checkout,
        PublicInvitationResolver $resolver,
    ): JsonResponse|RedirectResponse {
        $event = $this->resolveEvent($slug, $resolver);

        if ($event instanceof RedirectResponse) {
            return $event;
        }

        $cartId = $request->string('cart_id')->toString();

        try {
            ['order' => $order, 'result' => $result] = $checkout->checkout(
                $event,
                $cartId,
                [
                    'name' => $request->string('name')->toString(),
                    'email' => $request->string('email')->toString(),
                    'phone' => $request->string('phone')->toString(),
                ],
                [
                    'method' => $request->string('payment_method')->toString(),
                    'provider' => $request->input('provider'),
                    'phone' => $request->input('momo_phone'),
                    'bank_name' => $request->input('bank_name'),
                ],
            );
        } catch (TicketPurchaseException $e) {
            // Must precede the RuntimeException catch below — TicketPurchaseException
            // extends it.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();

            return response()->json(['success' => false, 'message' => $e->getMessage()], max(400, min(503, $code ?: 502)));
        }

        // $order is always a freshly created TicketOrder — forced to 200, never left to
        // JsonResource's automatic wasRecentlyCreated-based status calculation, which
        // would otherwise report 201 on every call. See the Slice B2 plan, decision #6.
        return (new TicketCheckoutResultResource($order, $result))->response()->setStatusCode(200);
    }

    public function status(string $orderReference): TicketOrderResource|JsonResponse
    {
        $order = TicketOrder::query()
            ->where('order_reference', $orderReference)
            ->with(['event', 'items', 'tickets', 'payment'])
            ->first();

        if ($order === null) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        return new TicketOrderResource($order);
    }

    public function verify(string $orderReference, LencoService $lenco, TicketPaymentStatusService $statusService): JsonResponse
    {
        if (! preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $orderReference)) {
            return response()->json(['success' => false, 'message' => 'Invalid reference.'], 422);
        }

        $order = TicketOrder::query()->where('order_reference', $orderReference)->first();
        if ($order === null) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        $payment = $order->payment;
        if ($payment === null) {
            return response()->json(['success' => true, 'status' => $order->status->value]);
        }

        if ($payment->isTerminal() && ! $payment->canRecoverToCompleted()) {
            $statusService->retryFulfillmentIfNeeded($payment);

            return $this->statusResponse($order->fresh());
        }

        try {
            $verification = $lenco->verifyByReference($orderReference);
            $statusService->applyVerificationResult($payment, $verification);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'status' => $order->status->value,
                'message' => $e->getMessage(),
            ], 502);
        }

        return $this->statusResponse($order->fresh());
    }

    /**
     * Same as web's statusResponse() except redirect_url is dropped — it points at the
     * Blade order-status page, meaningless for a native client.
     */
    private function statusResponse(TicketOrder $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status' => $order->status->value,
            'failure_reason' => $order->payment?->failure_reason,
        ]);
    }

    private function resolveEvent(string $slug, PublicInvitationResolver $resolver): Event|RedirectResponse
    {
        return $resolver->resolveForTickets($slug);
    }
}
