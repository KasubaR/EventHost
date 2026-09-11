<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TicketPayoutResource;
use App\Http\Resources\Api\V1\TicketRevenueEntryResource;
use App\Models\Event;
use App\Models\TicketRevenueEntry;
use App\Services\TicketRevenueAnalyticsService;
use App\Services\TicketRevenueLedgerService;
use Illuminate\Http\JsonResponse;

/**
 * JSON sibling of App\Http\Controllers\EventTicketRevenueController (Slice D).
 * Read-only, as promised in plans/android-implementation.md §0.5 — no write
 * action anywhere here, payouts stay admin-recorded only.
 */
class EventTicketRevenueController extends Controller
{
    public function revenue(Event $event, TicketRevenueLedgerService $ledger): JsonResponse
    {
        $this->authorize('update', $event);
        abort_unless($event->isTicketed(), 404);

        $summary = $ledger->summaryFor($event);

        $entries = TicketRevenueEntry::query()
            ->where('event_id', $event->id)
            ->with('order:id,order_reference')
            ->newestFirst()
            ->paginate(25);

        return response()->json([
            'gross_sales' => (string) $summary['gross_amount'],
            'platform_fees' => (string) $summary['platform_fee'],
            'pending_payable' => (string) $ledger->balanceFor($event),
            'entries' => TicketRevenueEntryResource::collection($entries->items()),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    public function payouts(Event $event, TicketRevenueAnalyticsService $analytics, TicketRevenueLedgerService $ledger): JsonResponse
    {
        $this->authorize('update', $event);
        abort_unless($event->isTicketed(), 404);

        return response()->json([
            'pending_payable' => (string) $ledger->balanceFor($event),
            'payouts' => TicketPayoutResource::collection($analytics->payoutsFor($event)),
        ]);
    }
}
