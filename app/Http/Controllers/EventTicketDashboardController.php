<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Services\TicketRevenueLedgerService;
use Illuminate\View\View;

/**
 * The "Ticketing" section's Overview tab (Phase 15) — sold/remaining/checked-in
 * counts plus the gross/fees/host-revenue money trio, the latter read straight
 * from TicketRevenueEntry via TicketRevenueLedgerService::summaryFor() rather
 * than re-derived from ticket_orders. See resources/views/events/tickets/partials/nav.blade.php
 * for the section's tab strip, shared by every ticketing page.
 */
class EventTicketDashboardController extends Controller
{
    public function overview(Event $event, TicketRevenueLedgerService $ledger): View
    {
        $this->authorize('update', $event);
        abort_unless($event->isTicketed(), 404);

        $event->load('ticketTypes');

        $ticketsSold = Ticket::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [TicketStatus::Valid, TicketStatus::Used])
            ->count();

        $checkedIn = Ticket::query()
            ->where('event_id', $event->id)
            ->whereNotNull('checked_in_at')
            ->count();

        // Unlimited types (quantity = null) don't contribute a "remaining"
        // figure — same reasoning TicketType::availableQuantity() already
        // uses for the public ticket picker's "N left" badge.
        $ticketsRemaining = $event->ticketTypes->reduce(
            fn (int $carry, $type) => $type->quantity === null ? $carry : $carry + $type->availableQuantity(),
            0,
        );

        $summary = $ledger->summaryFor($event);

        return view('events.tickets.overview', [
            'event' => $event,
            'ticketsSold' => $ticketsSold,
            'ticketsRemaining' => $ticketsRemaining,
            'checkedIn' => $checkedIn,
            'grossSales' => $summary['gross_amount'],
            'platformFees' => $summary['platform_fee'],
            'hostRevenue' => $summary['host_amount'],
            // Lifetime host_amount minus what's already been paid out
            // (Phase 23) — balanceFor() sums every ledger row, sale and
            // payout, so this is the current pending figure, not lifetime
            // earnings (that's hostRevenue above, unaffected by payouts).
            'pendingPayout' => $ledger->balanceFor($event),
        ]);
    }
}
