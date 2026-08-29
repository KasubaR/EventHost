<?php

namespace App\Http\Controllers;

use App\Exceptions\TicketPurchaseException;
use App\Http\Requests\StoreTicketCheckoutRequest;
use App\Models\Event;
use App\Models\TicketOrder;
use App\Services\LencoService;
use App\Services\PublicInvitationResolver;
use App\Services\TicketCheckoutService;
use App\Services\TicketPaymentStatusService;
use App\Services\TicketReservationService;
use App\Support\TicketCart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Buyer details + Lenco checkout for a held cart. No login. See
 * plans/ticketing.md §5.
 */
class EventTicketCheckoutController extends Controller
{
    public function show(
        string $slug,
        Request $request,
        TicketReservationService $reservations,
        PublicInvitationResolver $resolver,
    ): View|RedirectResponse {
        $event = $this->resolveEvent($slug, $resolver);

        if ($event instanceof RedirectResponse) {
            return $event;
        }

        $cartId = TicketCart::idFor($request, $event);

        $held = $cartId !== null ? $reservations->activeForCart($event, $cartId) : collect();

        if ($held->isEmpty()) {
            return redirect()
                ->route('events.public.tickets', $event->slug)
                ->withErrors(['tickets' => 'Your ticket hold has expired. Please choose your tickets again.']);
        }

        return view('events.tickets.checkout', [
            'event' => $event,
            'reservations' => $held,
            'bankTransferEnabled' => (bool) config('services.lenco.bank_transfer_enabled', true),
            'buyer' => $request->user(),
        ]);
    }

    public function store(
        string $slug,
        StoreTicketCheckoutRequest $request,
        TicketCheckoutService $checkout,
        PublicInvitationResolver $resolver,
    ): JsonResponse|RedirectResponse {
        $event = $this->resolveEvent($slug, $resolver);

        if ($event instanceof RedirectResponse) {
            return $event;
        }

        $cartId = TicketCart::idFor($request, $event);

        if ($cartId === null) {
            return response()->json(['success' => false, 'message' => 'Your ticket hold has expired. Please choose your tickets again.'], 422);
        }

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
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();

            return response()->json(['success' => false, 'message' => $e->getMessage()], max(400, min(503, $code ?: 502)));
        }

        return response()->json([
            'success' => true,
            'status' => $order->status->value,
            'order_reference' => $order->order_reference,
            'payment_instructions' => $result['paymentInstructions'] ?? null,
            'bank_details' => $result['bankDetails'] ?? null,
            'payment_url' => $result['paymentUrl'] ?? null,
        ]);
    }

    public function status(string $orderReference): View
    {
        $order = TicketOrder::query()
            ->where('order_reference', $orderReference)
            ->with(['event', 'items', 'tickets'])
            ->firstOrFail();

        return view('events.tickets.order-status', ['order' => $order]);
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

    private function statusResponse(TicketOrder $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status' => $order->status->value,
            'redirect_url' => $order->isPaid() ? route('ticket.orders.show', $order->order_reference) : null,
            'failure_reason' => $order->payment?->failure_reason,
        ]);
    }

    private function resolveEvent(string $slug, PublicInvitationResolver $resolver): Event|RedirectResponse
    {
        return $resolver->resolveForTickets($slug);
    }
}
