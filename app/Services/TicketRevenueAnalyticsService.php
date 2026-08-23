<?php

namespace App\Services;

use App\Enums\TicketOrderStatus;
use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\TicketPayout;
use App\Models\TicketRevenueEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator as EloquentLengthAwarePaginator;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Admin-facing ticket revenue reporting (Phase 23) — the platform-wide
 * summary cards on /admin/ticketing/revenue and the per-event drill-down.
 * Read-only; every write goes through TicketRevenueLedgerService (sales) or
 * TicketPayoutService (payouts). Every query here is ZMW-only, same
 * single-currency assumption ticketing already makes end to end.
 */
class TicketRevenueAnalyticsService
{
    /**
     * @return array{today_sales: float, gross_sales: float, platform_revenue: float, pending_payouts: float, completed_payouts: float}
     */
    public function platformSummary(): array
    {
        $saleTotals = TicketRevenueEntry::query()
            ->where('type', TicketRevenueEntry::TYPE_SALE)
            ->selectRaw('COALESCE(SUM(gross_amount), 0) as gross_sales, COALESCE(SUM(platform_fee), 0) as platform_revenue')
            ->first();

        $todaySales = (float) TicketRevenueEntry::query()
            ->where('type', TicketRevenueEntry::TYPE_SALE)
            ->whereDate('created_at', now()->toDateString())
            ->sum('gross_amount');

        // SUM(host_amount) over every type, across every event — sale rows
        // positive, payout rows negative — is exactly the platform's total
        // pending payable. TicketPayoutService never lets a single event's
        // balance go negative, so this sum can't either.
        $pendingPayouts = (float) TicketRevenueEntry::query()->sum('host_amount');

        $completedPayouts = (float) TicketPayout::query()->sum('amount');

        return [
            'today_sales' => $todaySales,
            'gross_sales' => (float) ($saleTotals->gross_sales ?? 0),
            'platform_revenue' => (float) ($saleTotals->platform_revenue ?? 0),
            'pending_payouts' => $pendingPayouts,
            'completed_payouts' => $completedPayouts,
        ];
    }

    /**
     * One row per ticketed event with at least one ledger entry (sale or
     * payout) — built from two grouped queries merged in PHP rather than one
     * query mixing two aggregation grains. Ordered by pending payable desc:
     * events owed the most money surface first.
     *
     * @return LengthAwarePaginator<int, object{event: Event, gross_amount: float, platform_fee: float, pending_payable: float, paid_out: float}>
     */
    public function perEventBreakdown(int $perPage = 20): LengthAwarePaginator
    {
        // event_id is nullable (ticket_revenue_entries.event_id nullOnDelete —
        // a deleted event's sale rows survive for audit history, per that
        // migration's own comment). A null-keyed row can't be joined to an
        // Event to display in this table, so it's excluded here explicitly
        // rather than relying on the later ->filter() to catch it.
        $saleTotals = TicketRevenueEntry::query()
            ->where('type', TicketRevenueEntry::TYPE_SALE)
            ->whereNotNull('event_id')
            ->selectRaw('event_id, COALESCE(SUM(gross_amount), 0) as gross_amount, COALESCE(SUM(platform_fee), 0) as platform_fee')
            ->groupBy('event_id')
            ->get()
            ->keyBy('event_id');

        $balances = TicketRevenueEntry::query()
            ->selectRaw('event_id, COALESCE(SUM(host_amount), 0) as balance')
            ->groupBy('event_id')
            ->pluck('balance', 'event_id');

        $paidOut = TicketPayout::query()
            ->selectRaw('event_id, COALESCE(SUM(amount), 0) as paid_out')
            ->groupBy('event_id')
            ->pluck('paid_out', 'event_id');

        $eventIds = $saleTotals->keys();
        $events = Event::query()->whereIn('id', $eventIds)->get()->keyBy('id');

        $rows = $eventIds
            ->map(function (int $eventId) use ($saleTotals, $balances, $paidOut, $events): ?object {
                $event = $events->get($eventId);
                if ($event === null) {
                    return null;
                }

                $sale = $saleTotals->get($eventId);

                return (object) [
                    'event' => $event,
                    'gross_amount' => (float) $sale->gross_amount,
                    'platform_fee' => (float) $sale->platform_fee,
                    'pending_payable' => (float) ($balances->get($eventId) ?? 0),
                    'paid_out' => (float) ($paidOut->get($eventId) ?? 0),
                ];
            })
            ->filter()
            ->sortByDesc('pending_payable')
            ->values();

        return $this->paginate($rows, $perPage);
    }

    /**
     * Paid orders for one event, newest first — the "Orders → Payments" leg
     * of the drill-down.
     *
     * @return LengthAwarePaginator<int, TicketOrder>
     */
    public function ordersFor(Event $event, int $perPage = 20): LengthAwarePaginator
    {
        return TicketOrder::query()
            ->where('event_id', $event->id)
            ->where('status', TicketOrderStatus::Paid)
            ->with('payment')
            ->orderByDesc('paid_at')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, TicketPayout>
     */
    public function payoutsFor(Event $event): Collection
    {
        return TicketPayout::query()
            ->where('event_id', $event->id)
            ->with('paidBy:id,name,email')
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  BaseCollection<int, object>  $items
     * @return LengthAwarePaginator<int, object>
     */
    private function paginate(BaseCollection $items, int $perPage): LengthAwarePaginator
    {
        $page = (int) request()->query('page', 1);
        $slice = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new EloquentLengthAwarePaginator(
            $slice,
            $items->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }
}
