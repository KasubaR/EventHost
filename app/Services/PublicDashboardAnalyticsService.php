<?php

namespace App\Services;

use App\Enums\EventAudience;
use App\Enums\EventProductKind;
use App\Enums\TicketingStatus;
use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The public portal's overview (/public-dashboard) — plans/public-private-portals.md
 * Phase 3. Deliberately not a variant of DashboardAnalyticsService: that
 * service's whole shape (RSVP status chart, guest groups, daily RSVPs) is
 * guest/invitation-specific and does not fit ticket commerce data, which has
 * no Guest rows at all. This is the commerce-shaped twin, covering both
 * ticketed events (sales/check-in/revenue) and public+invitation "free
 * registration" events (an event count only — their own guest/RSVP detail
 * lives on the event's own page, same as any invitation event).
 */
class PublicDashboardAnalyticsService
{
    /**
     * @return array{
     *     has_events: bool,
     *     totals: array{
     *         events: int,
     *         ticketed_events: int,
     *         open_registration_events: int,
     *         pending_review: int,
     *         tickets_sold: int,
     *         checked_in: int,
     *         gross_amount: float,
     *         host_amount: float,
     *     },
     *     upcoming: Collection<int, Event>,
     * }
     */
    public function forUser(User $user, TicketRevenueLedgerService $ledger): array
    {
        $events = Event::query()
            ->where('user_id', $user->id)
            ->forAudience(EventAudience::Public)
            ->get();

        if ($events->isEmpty()) {
            return [
                'has_events' => false,
                'totals' => [
                    'events' => 0,
                    'ticketed_events' => 0,
                    'open_registration_events' => 0,
                    'pending_review' => 0,
                    'tickets_sold' => 0,
                    'checked_in' => 0,
                    'gross_amount' => 0.0,
                    'host_amount' => 0.0,
                ],
                'upcoming' => collect(),
            ];
        }

        $ticketedIds = $events->where('product_kind', EventProductKind::Ticketed)->pluck('id');

        $ticketsSold = $ticketedIds->isEmpty() ? 0 : Ticket::query()
            ->whereIn('event_id', $ticketedIds)
            ->whereIn('status', [TicketStatus::Valid, TicketStatus::Used])
            ->count();

        $checkedIn = $ticketedIds->isEmpty() ? 0 : Ticket::query()
            ->whereIn('event_id', $ticketedIds)
            ->whereNotNull('checked_in_at')
            ->count();

        $revenue = $ledger->summaryForEventIds($ticketedIds);

        return [
            'has_events' => true,
            'totals' => [
                'events' => $events->count(),
                'ticketed_events' => $ticketedIds->count(),
                'open_registration_events' => $events->count() - $ticketedIds->count(),
                'pending_review' => $events->where('ticketing_status', TicketingStatus::PendingReview)->count(),
                'tickets_sold' => $ticketsSold,
                'checked_in' => $checkedIn,
                'gross_amount' => $revenue['gross_amount'],
                'host_amount' => $revenue['host_amount'],
            ],
            'upcoming' => $events
                ->where('is_published', true)
                ->whereNull('cancelled_at')
                ->sortBy('event_date')
                ->take(5)
                ->values(),
        ];
    }
}
