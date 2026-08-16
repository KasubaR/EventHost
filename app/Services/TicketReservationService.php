<?php

namespace App\Services;

use App\Enums\TicketOrderStatus;
use App\Enums\TicketReservationStatus;
use App\Exceptions\TicketPurchaseException;
use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\TicketReservation;
use App\Models\TicketType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 10-minute holds against a ticket type's capacity while an anonymous buyer
 * checks out. See plans/ticketing.md §5.1/§5.2.
 */
class TicketReservationService
{
    public const HOLD_MINUTES = 10;

    /**
     * @param  array<int, int>  $quantitiesByTicketTypeId
     * @return Collection<int, TicketReservation>
     */
    public function hold(Event $event, string $cartId, array $quantitiesByTicketTypeId): Collection
    {
        if (! $event->ticketSalesAreApproved()) {
            throw new TicketPurchaseException('Ticket sales are not open for this event.');
        }

        $requested = [];
        foreach ($quantitiesByTicketTypeId as $id => $qty) {
            $qty = (int) $qty;
            if ($qty > 0) {
                $requested[(int) $id] = $qty;
            }
        }

        if ($requested === []) {
            throw new TicketPurchaseException('Choose at least one ticket.');
        }

        return DB::transaction(function () use ($event, $cartId, $requested): Collection {
            $typeIds = array_keys($requested);
            sort($typeIds);

            // Lock types first (stable id order) so concurrent holds and
            // checkouts serialize on the same rows whose capacity is at stake.
            $ticketTypes = TicketType::query()
                ->where('event_id', $event->id)
                ->where('is_active', true)
                ->whereKey($typeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            TicketReservation::query()
                ->where('cart_id', $cartId)
                ->where('status', TicketReservationStatus::Held)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $hasOrderInProgress = TicketOrder::query()
                ->where('cart_id', $cartId)
                ->whereIn('status', TicketOrderStatus::inFlight())
                ->lockForUpdate()
                ->exists();

            if ($hasOrderInProgress) {
                throw new TicketPurchaseException('You already have a checkout in progress for these tickets.');
            }

            // Release this cart's own prior unconverted holds so changing
            // quantities re-holds instead of stacking. Own rows are Released
            // before availableQuantity(), so do not exclude the cart there —
            // excluding would let a concurrent same-cart hold stack past capacity.
            $this->release($cartId);

            $created = collect();

            foreach ($typeIds as $ticketTypeId) {
                $quantity = $requested[$ticketTypeId];
                $ticketType = $ticketTypes->get($ticketTypeId);

                if ($ticketType === null) {
                    throw new TicketPurchaseException('One of the selected ticket types is no longer available.');
                }

                if (! $ticketType->salesAreOpen()) {
                    throw new TicketPurchaseException("Sales for \"{$ticketType->name}\" are not currently open.");
                }

                if ($quantity < $ticketType->min_per_order || $quantity > $ticketType->max_per_order) {
                    throw new TicketPurchaseException(
                        "\"{$ticketType->name}\" requires between {$ticketType->min_per_order} and {$ticketType->max_per_order} tickets per order."
                    );
                }

                $available = $ticketType->availableQuantity();
                if ($available !== null && $quantity > $available) {
                    $message = $available > 0
                        ? "Only {$available} \"{$ticketType->name}\" ticket(s) left."
                        : "\"{$ticketType->name}\" is sold out.";
                    throw new TicketPurchaseException($message);
                }

                $created->push(TicketReservation::query()->create([
                    'event_id' => $event->id,
                    'ticket_type_id' => $ticketType->id,
                    'cart_id' => $cartId,
                    'quantity' => $quantity,
                    'unit_price_snapshot' => $ticketType->price,
                    'status' => TicketReservationStatus::Held,
                    'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
                ]));
            }

            return $created;
        });
    }

    public function release(string $cartId): void
    {
        TicketReservation::query()
            ->where('cart_id', $cartId)
            ->where('status', TicketReservationStatus::Held)
            ->whereNull('ticket_order_id')
            ->update(['status' => TicketReservationStatus::Released]);
    }

    /**
     * @return Collection<int, TicketReservation>
     */
    public function activeForCart(Event $event, string $cartId): Collection
    {
        return TicketReservation::query()
            ->where('event_id', $event->id)
            ->where('cart_id', $cartId)
            ->where('status', TicketReservationStatus::Held)
            ->whereNull('ticket_order_id')
            ->where('expires_at', '>', now())
            ->with('ticketType')
            ->get();
    }
}
