<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\TicketRevenueEntry;
use App\Services\TicketRevenueAnalyticsService;
use App\Services\TicketRevenueLedgerService;
use Illuminate\View\View;

/**
 * Host-side Revenue and Payouts tabs (Phase 23) — read-only. Payouts are
 * admin-recorded only (plans/ticketing.md: "Manual. Admin records a
 * payout..."), so there is no write action anywhere in this controller.
 */
class EventTicketRevenueController extends Controller
{
    public function revenue(Event $event, TicketRevenueLedgerService $ledger): View
    {
        $this->authorize('update', $event);
        abort_unless($event->isTicketed(), 404);

        $summary = $ledger->summaryFor($event);

        $entries = TicketRevenueEntry::query()
            ->where('event_id', $event->id)
            ->newestFirst()
            ->paginate(25);

        return view('events.tickets.revenue', [
            'event' => $event,
            'grossSales' => $summary['gross_amount'],
            'platformFees' => $summary['platform_fee'],
            'pendingPayable' => $ledger->balanceFor($event),
            'entries' => $entries,
        ]);
    }

    public function payouts(Event $event, TicketRevenueAnalyticsService $analytics, TicketRevenueLedgerService $ledger): View
    {
        $this->authorize('update', $event);
        abort_unless($event->isTicketed(), 404);

        return view('events.tickets.payouts', [
            'event' => $event,
            'pendingPayable' => $ledger->balanceFor($event),
            'payouts' => $analytics->payoutsFor($event),
        ]);
    }
}
