<?php

namespace App\Services;

use App\Models\ContributionPayment;
use App\Models\ContributionPayout;
use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator as EloquentLengthAwarePaginator;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Admin-facing contribution revenue reporting — the platform-wide summary
 * on /admin/contributions/revenue and the per-event drill-down. Read-only;
 * every write goes through ContributionPayoutService. There is no ledger
 * table to sum here the way ticketing has ticket_revenue_entries — with no
 * commission split, contribution_payments (status=completed) already IS the
 * append-only "money in" record, so every total below is derived straight
 * from it and contribution_payouts. See plans/contributions.md.
 */
class ContributionRevenueAnalyticsService
{
    /**
     * @return array{today_collected: float, total_collected: float, pending_payouts: float, completed_payouts: float}
     */
    public function platformSummary(): array
    {
        $totalCollected = (float) ContributionPayment::query()
            ->where('status', 'completed')
            ->sum('amount');

        $todayCollected = (float) ContributionPayment::query()
            ->where('status', 'completed')
            ->whereDate('completed_at', now()->toDateString())
            ->sum('amount');

        $completedPayouts = (float) ContributionPayout::query()->sum('amount');

        return [
            'today_collected' => $todayCollected,
            'total_collected' => $totalCollected,
            'pending_payouts' => round($totalCollected - $completedPayouts, 2),
            'completed_payouts' => $completedPayouts,
        ];
    }

    /**
     * One row per event with at least one completed contribution payment —
     * built from two grouped queries merged in PHP, same shape as
     * TicketRevenueAnalyticsService::perEventBreakdown(). Ordered by pending
     * payable desc: events owed the most money surface first.
     *
     * @return LengthAwarePaginator<int, object{event: Event, collected: float, pending_payable: float, paid_out: float}>
     */
    public function perEventBreakdown(int $perPage = 20): LengthAwarePaginator
    {
        $collectedTotals = ContributionPayment::query()
            ->join('event_contributions', 'contribution_payments.event_contribution_id', '=', 'event_contributions.id')
            ->where('contribution_payments.status', 'completed')
            ->selectRaw('event_contributions.event_id as event_id, COALESCE(SUM(contribution_payments.amount), 0) as collected')
            ->groupBy('event_contributions.event_id')
            ->get()
            ->keyBy('event_id');

        $paidOut = ContributionPayout::query()
            ->selectRaw('event_id, COALESCE(SUM(amount), 0) as paid_out')
            ->groupBy('event_id')
            ->pluck('paid_out', 'event_id');

        $eventIds = $collectedTotals->keys();
        $events = Event::query()->whereIn('id', $eventIds)->get()->keyBy('id');

        $rows = $eventIds
            ->map(function (int $eventId) use ($collectedTotals, $paidOut, $events): ?object {
                $event = $events->get($eventId);
                if ($event === null) {
                    return null;
                }

                $collected = (float) $collectedTotals->get($eventId)->collected;
                $out = (float) ($paidOut->get($eventId) ?? 0);

                return (object) [
                    'event' => $event,
                    'collected' => $collected,
                    'pending_payable' => round($collected - $out, 2),
                    'paid_out' => $out,
                ];
            })
            ->filter()
            ->sortByDesc('pending_payable')
            ->values();

        return $this->paginate($rows, $perPage);
    }

    /**
     * Completed installments for one event, newest first — the "payments"
     * leg of the drill-down.
     *
     * @return LengthAwarePaginator<int, ContributionPayment>
     */
    public function paymentsFor(Event $event, int $perPage = 20): LengthAwarePaginator
    {
        return ContributionPayment::query()
            ->whereHas('contribution', fn ($q) => $q->where('event_id', $event->id))
            ->where('status', 'completed')
            ->with('contribution:id,contributor_name,contributor_phone')
            ->orderByDesc('completed_at')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, ContributionPayout>
     */
    public function payoutsFor(Event $event): Collection
    {
        return ContributionPayout::query()
            ->where('event_id', $event->id)
            ->with('paidBy:id,name,email')
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Lifetime collected for one event — every completed installment across
     * every pledge.
     */
    public function collectedFor(Event $event): float
    {
        return (float) ContributionPayment::query()
            ->whereHas('contribution', fn ($q) => $q->where('event_id', $event->id))
            ->where('status', 'completed')
            ->sum('amount');
    }

    /**
     * Current pending payable for one event — collected minus already paid
     * out. Never negative in practice: ContributionPayoutService never lets
     * a payout exceed this.
     */
    public function balanceFor(Event $event): float
    {
        $paidOut = (float) ContributionPayout::query()->where('event_id', $event->id)->sum('amount');

        return round($this->collectedFor($event) - $paidOut, 2);
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
