<?php

namespace App\Services;

use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\TicketRevenueEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only place that should write to ticket_revenue_entries. Every row
 * takes a lock on the event (the closest thing to a "balance holder" here —
 * there's no single running-total column to lock, unlike users.event_credits)
 * and snapshots balance_after so the ledger and its running total can never
 * drift apart. Mirrors EventCreditService's shape exactly.
 *
 * Safe to call from inside an existing transaction — Laravel nests via savepoints.
 */
class TicketRevenueLedgerService
{
    /**
     * Records the sale once an order is confirmed paid. Idempotent: a
     * duplicate call for the same order (e.g. fulfillment re-running because
     * of a replayed webhook) is a no-op, both here and via the DB's own
     * unique(ticket_order_id, type) constraint as a second line of defence.
     *
     * Must be called from inside the same transaction that marks the order
     * paid — this is financial history, not a best-effort side effect like
     * the confirmation email, so it must commit or roll back atomically with
     * the order status change.
     */
    public function recordSale(TicketOrder $order): ?TicketRevenueEntry
    {
        return DB::transaction(function () use ($order): ?TicketRevenueEntry {
            /** @var Event $event */
            $event = Event::query()->whereKey($order->event_id)->lockForUpdate()->firstOrFail();

            $alreadyRecorded = TicketRevenueEntry::query()
                ->where('ticket_order_id', $order->id)
                ->where('type', TicketRevenueEntry::TYPE_SALE)
                ->exists();

            if ($alreadyRecorded) {
                return null;
            }

            $previousBalance = (float) TicketRevenueEntry::query()
                ->where('event_id', $event->id)
                ->sum('host_amount');

            $entry = new TicketRevenueEntry;
            $entry->forceFill([
                'event_id' => $event->id,
                'ticket_order_id' => $order->id,
                'type' => TicketRevenueEntry::TYPE_SALE,
                'gross_amount' => $order->face_value,
                'platform_fee' => $order->commission_amount,
                'buyer_fee' => $order->buyer_fee,
                'host_amount' => $order->host_amount,
                'buyer_total' => $order->buyer_total,
                'currency' => $order->currency,
                'balance_after' => round($previousBalance + (float) $order->host_amount, 2),
            ]);

            try {
                $entry->save();
            } catch (UniqueConstraintViolationException) {
                return null;
            }

            return $entry;
        });
    }

    /**
     * Running balance for an event right now — SUM(host_amount) over every
     * entry. Payout rows, if added later, should store host_amount negative
     * so this stays a plain sum without special-casing type.
     */
    public function balanceFor(Event $event): float
    {
        return (float) TicketRevenueEntry::query()
            ->where('event_id', $event->id)
            ->sum('host_amount');
    }

    /**
     * Overview dashboard totals — gross sales, EventHost fees, and host
     * revenue. One query over every entry for the event.
     *
     * @return array{gross_amount: float, platform_fee: float, host_amount: float}
     */
    public function summaryFor(Event $event): array
    {
        $row = TicketRevenueEntry::query()
            ->where('event_id', $event->id)
            ->selectRaw('COALESCE(SUM(gross_amount), 0) as gross_amount, COALESCE(SUM(platform_fee), 0) as platform_fee, COALESCE(SUM(host_amount), 0) as host_amount')
            ->first();

        return [
            'gross_amount' => (float) ($row->gross_amount ?? 0),
            'platform_fee' => (float) ($row->platform_fee ?? 0),
            'host_amount' => (float) ($row->host_amount ?? 0),
        ];
    }
}
