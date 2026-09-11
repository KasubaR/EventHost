<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Ticket;
use App\Services\TicketRevenueLedgerService;
use Illuminate\Http\JsonResponse;

/**
 * JSON sibling of App\Http\Controllers\EventTicketDashboardController (Slice D).
 * Same counts and ledger totals, verbatim — see the web controller's docblock
 * for why gross/fees/host-revenue read from TicketRevenueLedgerService rather
 * than being re-derived from ticket_orders.
 */
class EventTicketDashboardController extends Controller
{
    public function overview(Event $event, TicketRevenueLedgerService $ledger): JsonResponse
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

        $ticketsRemaining = $event->ticketTypes->reduce(
            fn (int $carry, $type) => $type->quantity === null ? $carry : $carry + $type->availableQuantity(),
            0,
        );

        $summary = $ledger->summaryFor($event);

        return response()->json([
            'tickets_sold' => $ticketsSold,
            'tickets_remaining' => $ticketsRemaining,
            'checked_in' => $checkedIn,
            'gross_sales' => (string) $summary['gross_amount'],
            'platform_fees' => (string) $summary['platform_fee'],
            'host_revenue' => (string) $summary['host_amount'],
            'pending_payout' => (string) $ledger->balanceFor($event),
        ]);
    }
}
