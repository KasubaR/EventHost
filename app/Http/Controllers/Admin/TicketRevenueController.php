<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\TicketPayoutExceedsBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTicketPayoutRequest;
use App\Models\Admin;
use App\Models\Event;
use App\Models\TicketPayout;
use App\Services\CommunicationService;
use App\Services\TicketPayoutService;
use App\Services\TicketRevenueAnalyticsService;
use App\Services\TicketRevenueLedgerService;
use App\Support\AdminActivity;
use App\Support\TicketPayoutProof;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'payoutState' => $ledger->payoutStateFor($event),
            'paidOut' => (float) $summary['host_amount'] - $pendingPayable,
            'orders' => $analytics->ordersFor($event),
            'payouts' => $analytics->payoutsFor($event),
        ]);
    }

    public function storePayout(
        StoreTicketPayoutRequest $request,
        Event $event,
        TicketPayoutService $payouts,
        TicketRevenueLedgerService $ledger,
        CommunicationService $communication,
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
                (float) ($validated['amount'] ?? 0),
                $validated['note'] ?? null,
                Carbon::parse($validated['paid_on']),
                $request->file('proof'),
                $validated['payment_type'] === 'full',
            );
        } catch (TicketPayoutExceedsBalanceException $e) {
            return redirect()
                ->route('admin.ticketing.revenue.show', $event)
                ->withErrors(['amount' => $e->getMessage()]);
        }

        $remaining = round($ledger->balanceFor($event), 2);

        // After the commit. A mail failure is reported, never allowed to look like a failed payout.
        try {
            $host = $event->user()->first();
            if ($host !== null) {
                $communication->notifyHostPayoutRecorded($host, $event, $payout, $remaining);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        AdminActivity::log('Admin recorded a ticket payout', [
            'event_id' => $event->id,
            'ticket_payout_id' => $payout->id,
            'amount' => (string) $payout->amount,
            'settled_in_full' => $remaining <= 0,
            'has_proof' => $payout->hasProof(),
        ]);

        return redirect()
            ->route('admin.ticketing.revenue.show', $event)
            ->with('status', 'payout-recorded')
            ->with('payout_remaining', $remaining);
    }

    /**
     * Streams a payout's proof of payment from the private disk. Never a public URL.
     */
    public function proof(Event $event, TicketPayout $payout): StreamedResponse
    {
        abort_unless($event->isTicketed() && $payout->event_id === $event->id, 404);

        return TicketPayoutProof::response($payout);
    }
}
