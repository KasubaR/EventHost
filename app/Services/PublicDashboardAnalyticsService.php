<?php

namespace App\Services;

use App\Enums\EventAudience;
use App\Enums\EventProductKind;
use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Enums\TicketingStatus;
use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\Ticket;
use App\Models\User;
use App\Support\PublicDashboardProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

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
                'profile' => PublicDashboardProfile::forEvents(collect()),
                'registrations' => $this->registrationsFor(collect()),
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

        $registrationEvents = $events->where('product_kind', '!=', EventProductKind::Ticketed)->values();

        return [
            'has_events' => true,
            'profile' => PublicDashboardProfile::forEvents($events),
            'registrations' => $this->registrationsFor($registrationEvents),
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
            // Same "today counts" rule as Event::scopeUpcoming(); a past event is not upcoming.
            'upcoming' => $events
                ->where('is_published', true)
                ->whereNull('cancelled_at')
                ->filter(fn (Event $e): bool => $e->event_date !== null && $e->event_date->toDateString() >= Event::venueToday()->toDateString())
                ->sortBy('event_date')
                ->take(5)
                ->values(),
        ];
    }

    /**
     * Registration numbers for the free-registration events (the ones with no
     * ticket sales). Self-signups are ordinary `rsvps` rows, so this reads the
     * same tables the private dashboard does. A registration counts once it is
     * Accepted and not waiting on, or refused by, host approval.
     *
     * @param  Collection<int, Event>  $events
     * @return array{
     *     registered: int,
     *     headcount: int,
     *     awaiting_approval: int,
     *     checked_in: int,
     *     daily: list<array{date: string, count: int}>,
     *     events: list<array{event: Event, registered: int, headcount: int}>,
     * }
     */
    private function registrationsFor(Collection $events): array
    {
        $empty = [
            'registered' => 0,
            'headcount' => 0,
            'awaiting_approval' => 0,
            'checked_in' => 0,
            'daily' => [],
            'events' => [],
        ];

        if ($events->isEmpty()) {
            return $empty;
        }

        $ids = $events->pluck('id');

        $confirmed = fn () => Rsvp::query()
            ->whereIn('event_id', $ids)
            ->where('status', RsvpStatus::Accepted)
            ->whereNotIn('host_approval_status', [RsvpApprovalStatus::Pending, RsvpApprovalStatus::Rejected]);

        $perEvent = $confirmed()
            ->selectRaw('event_id, COUNT(*) as registered, SUM(attendee_count) as headcount')
            ->groupBy('event_id')
            ->get()
            ->keyBy('event_id');

        $start = now()->subDays(13)->startOfDay();
        $dayExpr = Schema::getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', created_at)"
            : 'DATE(created_at)';
        $counts = $confirmed()
            ->where('created_at', '>=', $start)
            ->selectRaw("{$dayExpr} as day, COUNT(*) as cnt")
            ->groupByRaw($dayExpr)
            ->pluck('cnt', 'day');

        $daily = [];
        for ($i = 0; $i < 14; $i++) {
            $date = $start->copy()->addDays($i)->format('Y-m-d');
            $daily[] = ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)];
        }

        return [
            'registered' => (int) $perEvent->sum('registered'),
            'headcount' => (int) $perEvent->sum('headcount'),
            'awaiting_approval' => Rsvp::query()
                ->whereIn('event_id', $ids)
                ->where('status', RsvpStatus::Accepted)
                ->where('host_approval_status', RsvpApprovalStatus::Pending)
                ->count(),
            'checked_in' => Guest::query()->whereIn('event_id', $ids)->whereNotNull('checked_in_at')->count(),
            'daily' => $daily,
            'events' => $events
                ->sortByDesc(fn (Event $e) => (int) ($perEvent[$e->id]->registered ?? 0))
                ->take(5)
                ->map(fn (Event $e) => [
                    'event' => $e,
                    'registered' => (int) ($perEvent[$e->id]->registered ?? 0),
                    'headcount' => (int) ($perEvent[$e->id]->headcount ?? 0),
                ])
                ->values()
                ->all(),
        ];
    }
}
