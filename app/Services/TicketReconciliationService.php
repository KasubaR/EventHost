<?php

namespace App\Services;

use App\Enums\TicketOrderStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketOrderItem;
use App\Models\TicketPayment;
use App\Models\TicketPayout;
use App\Models\TicketRevenueEntry;
use App\Support\TicketingSettings;
use Illuminate\Support\Collection;

/**
 * Phase 24 — read-only. Answers two questions an admin must always be able
 * to answer before go-live: "does the whole chain (Lenco payment -> order ->
 * tickets -> ledger -> payouts) balance?" (runAll()) and "where did this
 * specific payment go?" (search()/traceFor()). Every write this system makes
 * already goes through TicketPaymentStatusService / TicketOrderFulfillmentService
 * / TicketRevenueLedgerService / TicketPayoutService — this service never
 * writes to any of those tables, it only audits them.
 */
class TicketReconciliationService
{
    public function __construct(private readonly TicketRevenueLedgerService $ledger) {}

    /**
     * One entry per health check, ordered most-critical-first. Each row is
     * pre-normalized ({title, subtitle, order}) so the view can render every
     * check with one generic partial regardless of what it queried.
     *
     * @return list<array{key: string, label: string, description: string, rows: list<array{title: string, subtitle: string, order: ?TicketOrder}>}>
     */
    public function runAll(): array
    {
        return [
            [
                'key' => 'payment_without_paid_order',
                'label' => 'Payment completed, order not paid',
                'description' => 'The buyer was charged but TicketOrderFulfillmentService::complete() never ran or failed silently — tickets were never issued for money already collected.',
                'rows' => $this->normalizePayments($this->completedPaymentsWithUnpaidOrder()),
            ],
            [
                'key' => 'paid_order_without_payment',
                'label' => 'Order paid, no completed payment on record',
                'description' => 'The order says Paid but its TicketPayment disagrees or is missing.',
                'rows' => $this->normalizeOrders($this->ordersPaidWithoutCompletedPayment()),
            ],
            [
                'key' => 'ticket_count_mismatch',
                'label' => 'Paid order with a ticket-count mismatch',
                'description' => 'Issued ticket count does not match the quantities on the order.',
                'rows' => $this->normalizeOrders($this->ordersWithTicketCountMismatch()),
            ],
            [
                'key' => 'missing_sale_entry',
                'label' => 'Paid order with no sale ledger entry',
                'description' => 'No TicketRevenueEntry (type=sale) row exists for this paid order — it never entered the revenue ledger.',
                'rows' => $this->normalizeOrders($this->ordersMissingSaleEntry()),
            ],
            [
                'key' => 'ledger_amount_drift',
                'label' => 'Ledger entry amount differs from its order',
                'description' => 'Sale entries are copied verbatim from the order at write time — a mismatch means one side was edited after the fact.',
                'rows' => $this->normalizeOrders($this->ordersWithLedgerAmountDrift()),
            ],
            [
                'key' => 'negative_balance',
                'label' => 'Event with a negative running balance',
                'description' => 'Sum of ledger entries for the event is negative — payouts should never be able to exceed revenue.',
                'rows' => $this->normalizeBalances($this->negativeEventBalances()),
            ],
            [
                'key' => 'stuck_in_flight',
                'label' => 'Payment stuck pending/processing for 2+ hours',
                'description' => 'Lenco may have completed the charge while the webhook was missed and the retry job exhausted itself. Open the order and use "Re-verify with Lenco".',
                'rows' => $this->normalizePayments($this->stuckInFlightPayments()),
            ],
            [
                'key' => 'settlement_mismatch',
                'label' => 'Amount/currency mismatch caught at verification',
                'description' => 'TicketPaymentStatusService already failed these automatically rather than accept a settlement that did not match the recorded amount — surfaced here so it does not require a log search.',
                'rows' => $this->normalizePayments($this->settlementMismatches()),
            ],
        ];
    }

    /**
     * @return Collection<int, TicketPayment>
     */
    public function completedPaymentsWithUnpaidOrder(): Collection
    {
        return TicketPayment::query()
            ->where('status', 'completed')
            ->whereHas('order', fn ($q) => $q->where('status', '!=', TicketOrderStatus::Paid))
            ->with('order.event:id,name')
            ->orderByDesc('completed_at')
            ->get();
    }

    /**
     * @return Collection<int, TicketOrder>
     */
    public function ordersPaidWithoutCompletedPayment(): Collection
    {
        return TicketOrder::query()
            ->where('status', TicketOrderStatus::Paid)
            ->whereDoesntHave('payment', fn ($q) => $q->where('status', 'completed'))
            ->with('event:id,name')
            ->orderByDesc('paid_at')
            ->get();
    }

    /**
     * Two grouped queries (order items vs. issued tickets) merged in PHP —
     * same two-grain-aggregation pattern TicketRevenueAnalyticsService::
     * perEventBreakdown() already uses, rather than one query mixing grains.
     *
     * @return Collection<int, TicketOrder>
     */
    public function ordersWithTicketCountMismatch(): Collection
    {
        $paidOrderIds = TicketOrder::query()->where('status', TicketOrderStatus::Paid)->pluck('id');
        if ($paidOrderIds->isEmpty()) {
            return collect();
        }

        $expected = TicketOrderItem::query()
            ->whereIn('ticket_order_id', $paidOrderIds)
            ->selectRaw('ticket_order_id, SUM(quantity) as qty')
            ->groupBy('ticket_order_id')
            ->pluck('qty', 'ticket_order_id');

        $issued = Ticket::query()
            ->whereIn('ticket_order_id', $paidOrderIds)
            ->selectRaw('ticket_order_id, COUNT(*) as cnt')
            ->groupBy('ticket_order_id')
            ->pluck('cnt', 'ticket_order_id');

        $mismatchedIds = $paidOrderIds->filter(
            fn (int $id) => (int) ($expected->get($id) ?? 0) !== (int) ($issued->get($id) ?? 0)
        );

        if ($mismatchedIds->isEmpty()) {
            return collect();
        }

        return TicketOrder::query()->whereIn('id', $mismatchedIds)->with('event:id,name')->get();
    }

    /**
     * @return Collection<int, TicketOrder>
     */
    public function ordersMissingSaleEntry(): Collection
    {
        return TicketOrder::query()
            ->where('status', TicketOrderStatus::Paid)
            ->whereDoesntHave('revenueEntries', fn ($q) => $q->where('type', TicketRevenueEntry::TYPE_SALE))
            ->with('event:id,name')
            ->orderByDesc('paid_at')
            ->get();
    }

    /**
     * @return Collection<int, TicketOrder>
     */
    public function ordersWithLedgerAmountDrift(): Collection
    {
        return TicketOrder::query()
            ->where('status', TicketOrderStatus::Paid)
            ->whereHas('revenueEntries', fn ($q) => $q->where('type', TicketRevenueEntry::TYPE_SALE))
            ->with(['event:id,name', 'revenueEntries' => fn ($q) => $q->where('type', TicketRevenueEntry::TYPE_SALE)])
            ->get()
            ->filter(function (TicketOrder $order): bool {
                $entry = $order->revenueEntries->first();
                if ($entry === null) {
                    return false;
                }

                return $this->amountsDiffer((float) $entry->gross_amount, (float) $order->face_value)
                    || $this->amountsDiffer((float) $entry->platform_fee, (float) $order->commission_amount)
                    || $this->amountsDiffer((float) $entry->host_amount, (float) $order->host_amount)
                    || $this->amountsDiffer((float) $entry->buyer_total, (float) $order->buyer_total);
            })
            ->values();
    }

    /**
     * @return Collection<int, object{event: ?Event, balance: float}>
     */
    public function negativeEventBalances(): Collection
    {
        $rows = TicketRevenueEntry::query()
            ->selectRaw('event_id, SUM(host_amount) as balance')
            ->whereNotNull('event_id')
            ->groupBy('event_id')
            ->havingRaw('SUM(host_amount) < 0')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $events = Event::query()->whereIn('id', $rows->pluck('event_id'))->get()->keyBy('id');

        return $rows
            ->map(fn ($row) => (object) [
                'event' => $events->get((int) $row->event_id),
                'balance' => (float) $row->balance,
            ])
            ->filter(fn ($row) => $row->event !== null)
            ->values();
    }

    /**
     * @return Collection<int, TicketPayment>
     */
    public function stuckInFlightPayments(int $hours = 2): Collection
    {
        return TicketPayment::query()
            ->inProgress()
            ->where('created_at', '<', now()->subHours($hours))
            ->with('order.event:id,name')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return Collection<int, TicketPayment>
     */
    public function settlementMismatches(): Collection
    {
        return TicketPayment::query()
            ->where('failure_reason', 'like', '%mismatch%')
            ->with('order.event:id,name')
            ->orderByDesc('failed_at')
            ->get();
    }

    /**
     * Resolves any of: exact order reference, exact payment reference/Lenco
     * transaction id/Lenco reference, or a fuzzy match on buyer email/phone/
     * amount (buyer_total, within a cent). Exact matches return a single-item
     * collection; fuzzy matches are capped at 25, newest first — same posture
     * as Ticket::scopeSearch()'s LIKE-wildcard escaping.
     *
     * @return Collection<int, TicketOrder>
     */
    public function search(string $term): Collection
    {
        $term = trim($term);
        if ($term === '') {
            return collect();
        }

        $order = TicketOrder::query()->where('order_reference', $term)->first();
        if ($order !== null) {
            return collect([$order]);
        }

        $payment = TicketPayment::query()
            ->where('payment_reference', $term)
            ->orWhere('lenco_transaction_id', $term)
            ->orWhere('lenco_reference', $term)
            ->first();
        if ($payment !== null) {
            $order = $payment->order;

            return $order !== null ? collect([$order]) : collect();
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $amount = is_numeric($term) ? round((float) $term, 2) : null;

        return TicketOrder::query()
            ->where(function ($query) use ($like, $amount): void {
                $query->where('buyer_email', 'like', $like)
                    ->orWhere('buyer_phone', 'like', $like);

                if ($amount !== null) {
                    $query->orWhereBetween('buyer_total', [$amount - 0.01, $amount + 0.01]);
                }
            })
            ->with('event:id,name')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();
    }

    /**
     * Everything needed to render one order's full chain — the "where did
     * this K500 go?" page.
     *
     * @return array{order: TicketOrder, balance: ?float, payouts: Collection<int, TicketPayout>}
     */
    public function traceFor(TicketOrder $order): array
    {
        $order->loadMissing([
            'event',
            'items.ticketType',
            'payment',
            'tickets' => fn ($q) => $q->orderBy('id'),
            'revenueEntries' => fn ($q) => $q->orderBy('created_at'),
        ]);

        $balance = $order->event !== null ? $this->ledger->balanceFor($order->event) : null;

        $payouts = $order->event !== null
            ? TicketPayout::query()
                ->where('event_id', $order->event_id)
                ->with('paidBy:id,name')
                ->orderByDesc('paid_on')
                ->orderByDesc('id')
                ->get()
            : collect();

        return [
            'order' => $order,
            'balance' => $balance,
            'payouts' => $payouts,
        ];
    }

    private function amountsDiffer(float $a, float $b): bool
    {
        return abs($a - $b) >= 0.01;
    }

    /**
     * @param  Collection<int, TicketOrder>  $orders
     * @return list<array{title: string, subtitle: string, order: TicketOrder}>
     */
    private function normalizeOrders(Collection $orders): array
    {
        return $orders->map(fn (TicketOrder $order) => [
            'title' => $order->order_reference,
            'subtitle' => ($order->event?->name ?? 'Deleted event').' · '.$order->buyer_name,
            'order' => $order,
        ])->all();
    }

    /**
     * @param  Collection<int, TicketPayment>  $payments
     * @return list<array{title: string, subtitle: string, order: ?TicketOrder}>
     */
    private function normalizePayments(Collection $payments): array
    {
        return $payments->map(function (TicketPayment $payment) {
            $order = $payment->order;

            return [
                'title' => $payment->payment_reference,
                'subtitle' => ($order?->event?->name ?? 'No order').
                    ' · '.TicketingSettings::formatZmw((float) $payment->amount).
                    ' · '.$payment->status.
                    ($payment->created_at !== null ? ' · '.$payment->created_at->diffForHumans() : ''),
                'order' => $order,
            ];
        })->all();
    }

    /**
     * @param  Collection<int, object{event: ?Event, balance: float}>  $rows
     * @return list<array{title: string, subtitle: string, order: null}>
     */
    private function normalizeBalances(Collection $rows): array
    {
        return $rows->map(fn ($row) => [
            'title' => $row->event?->name ?? 'Deleted event',
            'subtitle' => TicketingSettings::formatZmw($row->balance),
            'order' => null,
            'event' => $row->event,
        ])->all();
    }
}
