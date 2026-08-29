<?php

namespace App\Services;

use App\Enums\TicketOrderStatus;
use App\Enums\TicketReservationStatus;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketType;
use App\Notifications\TicketOrderConfirmationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Issues tickets once a TicketOrder's payment is confirmed. Parallel to
 * PaymentCompletionService, not a shared abstraction — see
 * plans/ticketing.md §5.2 for why.
 */
class TicketOrderFulfillmentService
{
    public function __construct(private readonly TicketRevenueLedgerService $ledger) {}

    /**
     * Idempotent: a duplicate webhook/verify call on an already-paid order is
     * a no-op. Refunded orders are never re-issued. Failed / cancelled /
     * expired orders are fulfilled only when the linked TicketPayment is
     * already `completed` (late Lenco success after a prior terminal result).
     */
    public function complete(TicketOrder $order): TicketOrder
    {
        $orderId = DB::transaction(function () use ($order): ?int {
            /** @var TicketOrder $locked */
            $locked = TicketOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === TicketOrderStatus::Paid) {
                return null;
            }

            if ($locked->status === TicketOrderStatus::Refunded) {
                return null;
            }

            $payment = $locked->payment;
            if ($payment === null || $payment->status !== 'completed') {
                return null;
            }

            if (! $locked->status->isInFlight() && ! $locked->status->isRecoverable()) {
                return null;
            }

            $locked->loadMissing('items');

            $typeIds = $locked->items->pluck('ticket_type_id')->unique()->filter()->sort()->values();
            if ($typeIds->isNotEmpty()) {
                TicketType::query()
                    ->whereKey($typeIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            foreach ($locked->items as $item) {
                for ($i = 0; $i < $item->quantity; $i++) {
                    Ticket::query()->create([
                        'ticket_order_id' => $locked->id,
                        'ticket_order_item_id' => $item->id,
                        'event_id' => $locked->event_id,
                        'ticket_type_id' => $item->ticket_type_id,
                        'public_token' => Ticket::generateUniqueToken(),
                        'attendee_name' => $locked->buyer_name,
                        'attendee_email' => $locked->buyer_email,
                        'attendee_phone' => $locked->buyer_phone,
                        'price_paid' => $item->unit_price,
                        'status' => TicketStatus::Valid,
                        'issued_at' => now(),
                    ]);
                }
            }

            $locked->reservations()
                ->where('status', TicketReservationStatus::Held)
                ->update(['status' => TicketReservationStatus::Converted]);

            $locked->status = TicketOrderStatus::Paid;
            $locked->paid_at = now();
            $locked->save();

            // Same transaction as the status flip — this is financial
            // history, not a best-effort side effect, so it must commit or
            // roll back atomically with "the order is paid".
            $this->ledger->recordSale($locked);

            return $locked->id;
        });

        $fresh = $order->fresh(['items', 'tickets']);

        if ($orderId !== null && $fresh !== null) {
            $this->sendConfirmation($fresh);
        }

        return $fresh ?? $order;
    }

    /**
     * The provider (or the buyer, via a "cancelled/expired" status from
     * Lenco) called it off before payment completed — free the held capacity
     * immediately rather than making the buyer wait out the hold.
     */
    public function cancel(TicketOrder $order, string $reason): TicketOrder
    {
        return $this->transitionToUnsuccessful($order, TicketOrderStatus::Cancelled, $reason);
    }

    /**
     * Payment actually failed — provider rejected it, an amount/currency
     * mismatch was caught, or Lenco could never be reached at all. Distinct
     * from cancel() so a host looking at "failed" orders can tell "the
     * payment didn't go through" apart from "cancelled" and any future
     * organizer-initiated cancellation. Same capacity release either way.
     */
    public function fail(TicketOrder $order, string $reason): TicketOrder
    {
        return $this->transitionToUnsuccessful($order, TicketOrderStatus::Failed, $reason);
    }

    /**
     * Provider collection expired (Lenco `expired`, or the poller max-age
     * sweep). Distinct from cancel() so a host can tell "the window lapsed"
     * apart from an explicit cancel. Same capacity release either way.
     */
    public function expire(TicketOrder $order, string $reason): TicketOrder
    {
        return $this->transitionToUnsuccessful($order, TicketOrderStatus::Expired, $reason);
    }

    /**
     * Lenco reports the payment as still in flight (e.g. a mobile money
     * prompt sent and awaiting the buyer's approval). Surfaces on the order
     * itself so a host glancing at pending_payment vs payment_processing can
     * tell "checkout not started" apart from "actively being collected".
     * One-way: never reverts a further-along order back to this state.
     */
    public function markProcessing(TicketOrder $order): TicketOrder
    {
        return DB::transaction(function () use ($order): TicketOrder {
            /** @var TicketOrder $locked */
            $locked = TicketOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TicketOrderStatus::PendingPayment) {
                return $locked;
            }

            $locked->status = TicketOrderStatus::PaymentProcessing;
            $locked->save();

            return $locked;
        });
    }

    private function transitionToUnsuccessful(TicketOrder $order, TicketOrderStatus $status, string $reason): TicketOrder
    {
        return DB::transaction(function () use ($order, $status, $reason): TicketOrder {
            /** @var TicketOrder $locked */
            $locked = TicketOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                return $locked;
            }

            $locked->reservations()
                ->where('status', TicketReservationStatus::Held)
                ->update(['status' => TicketReservationStatus::Released]);

            $locked->status = $status;
            $locked->save();

            Log::info('ticket_order.'.$status->value, ['order_id' => $locked->id, 'reason' => $reason]);

            return $locked;
        });
    }

    private function sendConfirmation(TicketOrder $order): void
    {
        try {
            Notification::route('mail', $order->buyer_email)
                ->notify(new TicketOrderConfirmationNotification($order));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
