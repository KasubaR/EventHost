<?php

namespace App\Services;

use App\Enums\CommissionMode;
use App\Enums\TicketOrderStatus;
use App\Enums\TicketReservationStatus;
use App\Exceptions\TicketPurchaseException;
use App\Jobs\RetryLencoTicketPayment;
use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\TicketPayment;
use App\Models\TicketReservation;
use App\Models\TicketType;
use App\Support\TicketOrderMoney;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a cart's active holds into a TicketOrder + TicketOrderItem rows and
 * hands off to LencoService — the same methods PaymentController::initiate
 * already calls. See plans/ticketing.md §5.2/§5.4.
 */
class TicketCheckoutService
{
    public const PAYMENT_EXPIRES_HOURS = 1;

    public function __construct(
        private readonly LencoService $lenco,
        private readonly TicketPaymentStatusService $paymentStatus,
    ) {}

    /**
     * @param  array{name: string, email: string, phone?: ?string}  $buyer
     * @param  array{method: string, provider?: ?string, phone?: ?string, bank_name?: ?string}  $payment
     * @return array{order: TicketOrder, result: array<string, mixed>}
     */
    public function checkout(Event $event, string $cartId, array $buyer, array $payment): array
    {
        $prepared = DB::transaction(function () use ($event, $cartId, $buyer, $payment): array {
            $held = TicketReservation::query()
                ->where('event_id', $event->id)
                ->where('cart_id', $cartId)
                ->where('status', TicketReservationStatus::Held)
                ->whereNull('ticket_order_id')
                ->where('expires_at', '>', now())
                ->orderBy('id')
                ->lockForUpdate()
                ->with('ticketType')
                ->get();

            if ($held->isEmpty()) {
                throw new TicketPurchaseException('Your ticket hold has expired. Please choose your tickets again.');
            }

            $inProgress = TicketOrder::query()
                ->where('cart_id', $cartId)
                ->whereIn('status', TicketOrderStatus::inFlight())
                ->lockForUpdate()
                ->exists();

            if ($inProgress) {
                throw new TicketPurchaseException('You already have a checkout in progress for these tickets.');
            }

            $typeIds = $held->pluck('ticket_type_id')->unique()->filter()->sort()->values();
            if ($typeIds->isNotEmpty()) {
                TicketType::query()
                    ->whereKey($typeIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            $faceValue = round($held->sum(fn ($r) => (float) $r->unit_price_snapshot * $r->quantity), 2);
            $money = TicketOrderMoney::calculate(
                $faceValue,
                $event->commissionPercent(),
                $event->commission_mode ?? CommissionMode::Absorb,
            );
            $paymentExpiresAt = now()->addHours(self::PAYMENT_EXPIRES_HOURS);

            $order = TicketOrder::query()->create(array_merge([
                'event_id' => $event->id,
                'order_reference' => TicketOrder::generateReference($event->id),
                'cart_id' => $cartId,
                'buyer_name' => $buyer['name'],
                'buyer_email' => $buyer['email'],
                'buyer_phone' => $buyer['phone'] ?? null,
                'status' => TicketOrderStatus::PendingPayment,
                'currency' => 'ZMW',
                'expires_at' => $paymentExpiresAt,
            ], $money->toOrderAttributes()));

            foreach ($held as $reservation) {
                $order->items()->create([
                    'ticket_type_id' => $reservation->ticket_type_id,
                    'ticket_type_name' => $reservation->ticketType->name,
                    'unit_price' => $reservation->unit_price_snapshot,
                    'quantity' => $reservation->quantity,
                    'subtotal' => round((float) $reservation->unit_price_snapshot * $reservation->quantity, 2),
                ]);
            }

            // Direct query, not $order->reservations() — that relation's own
            // constraint is "ticket_order_id = this order", which none of
            // these rows have yet.
            TicketReservation::query()
                ->whereIn('id', $held->pluck('id'))
                ->update([
                    'ticket_order_id' => $order->id,
                    'expires_at' => $paymentExpiresAt,
                ]);

            $ticketPayment = TicketPayment::query()->create([
                'ticket_order_id' => $order->id,
                'provider' => $payment['provider'] ?? null,
                'payment_method' => $payment['method'],
                'amount' => $money->buyerTotal,
                'currency' => 'ZMW',
                'status' => 'pending',
                'payment_reference' => $order->order_reference,
                'expires_at' => $paymentExpiresAt,
                'metadata' => [
                    'phone' => $payment['phone'] ?? null,
                    'provider' => $payment['provider'] ?? null,
                    'bank_name' => $payment['bank_name'] ?? null,
                ],
            ]);

            return [
                'order' => $order,
                'ticketPayment' => $ticketPayment,
                'buyerTotal' => $money->buyerTotal,
                'payment' => $payment,
            ];
        });

        /** @var TicketOrder $order */
        $order = $prepared['order'];
        /** @var TicketPayment $ticketPayment */
        $ticketPayment = $prepared['ticketPayment'];
        $buyerTotal = $prepared['buyerTotal'];
        $payment = $prepared['payment'];

        $context = [
            'user_id' => 0,
            'ref' => $order->order_reference,
            'amount' => (float) $buyerTotal,
            'currency' => 'ZMW',
            'description' => 'Tickets — '.$event->name,
            'reference' => $order->order_reference,
        ];

        try {
            $result = $payment['method'] === 'bank_transfer'
                ? $this->lenco->initiateBankTransfer($context, [
                    'bankName' => (string) ($payment['bank_name'] ?? ''),
                ])
                : $this->lenco->initiateMobileMoneyPayment(
                    $context,
                    (string) ($payment['phone'] ?? ''),
                    (string) ($payment['provider'] ?? 'mtn'),
                );
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();
            if ($code === 0 || $code >= 500) {
                RetryLencoTicketPayment::dispatch($ticketPayment);

                return [
                    'order' => $order->fresh(),
                    'result' => [
                        'status' => 'queued',
                        'paymentInstructions' => null,
                        'bankDetails' => null,
                        'paymentUrl' => null,
                    ],
                ];
            }

            $this->paymentStatus->markFailed($ticketPayment, 'Lenco initiate failed: '.$e->getMessage());

            throw $e;
        }

        $ticketPayment = $this->paymentStatus->applyInitiateResult($ticketPayment, array_merge($result, [
            'provider' => $result['provider'] ?? ($payment['provider'] ?? $ticketPayment->provider),
        ]));

        if ($ticketPayment->status === 'failed'
            && $ticketPayment->failure_reason === 'Amount or currency mismatch with provider settlement') {
            throw new TicketPurchaseException('Payment amount did not match what was charged. Please try again.');
        }

        return ['order' => $order->fresh(), 'result' => $result];
    }
}
