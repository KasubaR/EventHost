<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\TicketPayoutExceedsBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTicketPayoutRequest;
use App\Models\Admin;
use App\Models\Event;
use App\Services\TicketPayoutService;
use App\Services\TicketRevenueAnalyticsService;
use App\Services\TicketRevenueLedgerService;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Platform-wide ticket revenue (Phase 23) — kept separate from
 * Admin\TicketingController, which owns activation/approve/reject. This
 * controller owns money: the summary dashboard, the per-event drill-down,
 * and recording a manual payout.
 */
class TicketRevenueController extends Controller
{
    public function index(TicketRevenueAnalyticsService $analytics): View
    {
        return view('admin.ticketing.revenue.index', [
            'summary' => $analytics->platformSummary(),
            'rows' => $analytics->perEventBreakdown(),
        ]);
    }

    public function show(Event $event, TicketRevenueAnalyticsService $analytics, TicketRevenueLedgerService $ledger): View
    {
        abort_unless($event->isTicketed(), 404);

        $summary = $ledger->summaryFor($event);
        $pendingPayable = $ledger->balanceFor($event);

        return view('admin.ticketing.revenue.show', [
            'adminEvent' => $event,
            'grossSales' => $summary['gross_amount'],
            'platformFees' => $summary['platform_fee'],
            'pendingPayable' => $pendingPayable,
            'paidOut' => (float) $summary['host_amount'] - $pendingPayable,
            'orders' => $analytics->ordersFor($event),
            'payouts' => $analytics->payoutsFor($event),
        ]);
    }

    public function storePayout(
        StoreTicketPayoutRequest $request,
        Event $event,
        TicketPayoutService $payouts,
    ): RedirectResponse {
        abort_unless($event->isTicketed(), 404);

        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            abort(403);
        }

        $validated = $request->validated();

        try {
            $payout = $payouts->recordPayout(
                $event,
                $admin,
                (float) $validated['amount'],
                $validated['note'] ?? null,
                Carbon::parse($validated['paid_on']),
            );
        } catch (TicketPayoutExceedsBalanceException $e) {
            return redirect()
                ->route('admin.ticketing.revenue.show', $event)
                ->withErrors(['amount' => $e->getMessage()]);
        }

        AdminActivity::log('Admin recorded a ticket payout', [
            'event_id' => $event->id,
            'ticket_payout_id' => $payout->id,
            'amount' => (string) $payout->amount,
        ]);

        return redirect()
            ->route('admin.ticketing.revenue.show', $event)
            ->with('status', 'payout-recorded');
    }
}
