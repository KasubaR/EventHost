<?php

namespace App\Services;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DashboardAnalyticsService
{
    /**
     * @return array{
     *     has_events: bool,
     *     totals: array{
     *         events: int,
     *         invitation_views: int,
     *         guests: int,
     *         awaiting_guests: int,
     *         responded_guests: int,
     *         rsvp_accepted: int,
     *         rsvp_declined: int,
     *         rsvp_maybe: int,
     *         accepted_headcount: int,
     *         conversion_pct: float|null,
     *         attendance_pct: float|null,
     *     },
     *     daily_rsvps: list<array{date:string,count:int}>,
     *     status_chart: list<array{key:string,label:string,count:int,pct:float}>,
     *     group_breakdown: list<array{label:string,count:int,pct:float}>,
     *     top_guests: list<array{name:string,event_name:string,attendee_count:int}>,
     * }
     */
    public function forUser(User $user): array
    {
        $eventIds = Event::query()->where('user_id', $user->id)->pluck('id');
        $hasEvents = $eventIds->isNotEmpty();

        if (! $hasEvents) {
            return [
                'has_events' => false,
                'totals' => [
                    'events' => 0,
                    'invitation_views' => 0,
                    'guests' => 0,
                    'awaiting_guests' => 0,
                    'responded_guests' => 0,
                    'rsvp_accepted' => 0,
                    'rsvp_declined' => 0,
                    'rsvp_maybe' => 0,
                    'accepted_headcount' => 0,
                    'conversion_pct' => null,
                    'attendance_pct' => null,
                ],
                'daily_rsvps' => [],
                'status_chart' => [],
                'group_breakdown' => [],
                'top_guests' => [],
            ];
        }

        $payload = $this->payloadForEventIds($eventIds);

        return array_merge(['has_events' => true], $payload);
    }

    /**
     * Analytics for a single event (event detail page).
     *
     * @return array{
     *     totals: array{
     *         events: int,
     *         invitation_views: int,
     *         guests: int,
     *         awaiting_guests: int,
     *         responded_guests: int,
     *         rsvp_accepted: int,
     *         rsvp_declined: int,
     *         rsvp_maybe: int,
     *         accepted_headcount: int,
     *         conversion_pct: float|null,
     *         attendance_pct: float|null,
     *     },
     *     daily_rsvps: list<array{date:string,count:int}>,
     *     status_chart: list<array{key:string,label:string,count:int,pct:float}>,
     *     group_breakdown: list<array{label:string,count:int,pct:float}>,
     *     top_guests: list<array{name:string,event_name:string,attendee_count:int}>,
     * }
     */
    public function forEvent(Event $event): array
    {
        return $this->payloadForEventIds(collect([(int) $event->getKey()]));
    }

    /**
     * @param  Collection<int, int>  $eventIds
     * @return array{
     *     totals: array{events:int,invitation_views:int,guests:int,awaiting_guests:int,responded_guests:int,rsvp_accepted:int,rsvp_declined:int,rsvp_maybe:int,accepted_headcount:int,conversion_pct:float|null,attendance_pct:float|null},
     *     daily_rsvps: list<array{date:string,count:int}>,
     *     status_chart: list<array{key:string,label:string,count:int,pct:float}>,
     *     group_breakdown: list<array{label:string,count:int,pct:float}>,
     *     top_guests: list<array{name:string,event_name:string,attendee_count:int}>,
     * }
     */
    private function payloadForEventIds(Collection $eventIds): array
    {
        // Drafts are not live invitations, so they do not count towards the
        // "Events" stat on the overview.
        $eventsCount = (int) Event::query()
            ->whereIn('id', $eventIds)
            ->where('is_published', true)
            ->count();
        $invitationViews = (int) Event::query()->whereIn('id', $eventIds)->sum('invitation_views_count');

        $guestsTotal = (int) Guest::query()->whereIn('event_id', $eventIds)->count();
        $respondedGuests = (int) Guest::query()->whereIn('event_id', $eventIds)->whereHas('rsvp')->count();
        $awaitingGuests = max(0, $guestsTotal - $respondedGuests);

        $statusCounts = Rsvp::query()
            ->whereIn('event_id', $eventIds)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $acceptedCount = (int) ($statusCounts[RsvpStatus::Accepted->value] ?? 0);
        $declinedCount = (int) ($statusCounts[RsvpStatus::Declined->value] ?? 0);
        $maybeCount = (int) ($statusCounts[RsvpStatus::Maybe->value] ?? 0);
        $totalRsvps = $acceptedCount + $declinedCount + $maybeCount;

        $acceptedHeadcount = (int) Rsvp::query()
            ->whereIn('event_id', $eventIds)
            ->where('status', RsvpStatus::Accepted)
            ->sum('attendee_count');

        $conversionPct = $guestsTotal > 0
            ? round(($respondedGuests / $guestsTotal) * 100, 1)
            : null;

        $attendancePct = $guestsTotal > 0
            ? round(min(100, ($acceptedHeadcount / $guestsTotal) * 100), 1)
            : null;

        $dailyRsvps = $this->dailyRsvpSeries($eventIds, 14);

        $statusChart = [];
        if ($totalRsvps > 0) {
            foreach ([
                ['key' => RsvpStatus::Accepted->value, 'label' => 'Accepted', 'count' => $acceptedCount],
                ['key' => RsvpStatus::Declined->value, 'label' => 'Declined', 'count' => $declinedCount],
                ['key' => RsvpStatus::Maybe->value, 'label' => 'Maybe', 'count' => $maybeCount],
            ] as $row) {
                if ($row['count'] > 0) {
                    $statusChart[] = [
                        'key' => $row['key'],
                        'label' => $row['label'],
                        'count' => $row['count'],
                        'pct' => round(($row['count'] / $totalRsvps) * 100, 1),
                    ];
                }
            }
        }

        $groupBreakdown = Guest::query()
            ->whereIn('guests.event_id', $eventIds)
            ->whereNotNull('guests.guest_group_id')
            ->join('guest_groups', 'guest_groups.id', '=', 'guests.guest_group_id')
            ->selectRaw('guest_groups.name as label')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('guest_groups.name')
            ->orderByDesc('cnt')
            ->limit(8)
            ->get();

        $groupTotal = (int) $groupBreakdown->sum('cnt');
        $groupBars = [];
        foreach ($groupBreakdown as $g) {
            $cnt = (int) $g->cnt;
            $groupBars[] = [
                'label' => (string) $g->label,
                'count' => $cnt,
                'pct' => $groupTotal > 0 ? round(($cnt / $groupTotal) * 100, 1) : 0.0,
            ];
        }

        $topGuests = Rsvp::query()
            ->whereIn('event_id', $eventIds)
            ->where('status', RsvpStatus::Accepted)
            ->with(['guest:id,name', 'event:id,name'])
            ->orderByDesc('attendee_count')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (Rsvp $r) => [
                'name' => $r->guest?->name ?? 'Guest',
                'event_name' => $r->event?->name ?? 'Event',
                'attendee_count' => $r->attendee_count,
            ])
            ->values()
            ->all();

        return [
            'totals' => [
                'events' => $eventsCount,
                'invitation_views' => $invitationViews,
                'guests' => $guestsTotal,
                'awaiting_guests' => $awaitingGuests,
                'responded_guests' => $respondedGuests,
                'rsvp_accepted' => $acceptedCount,
                'rsvp_declined' => $declinedCount,
                'rsvp_maybe' => $maybeCount,
                'accepted_headcount' => $acceptedHeadcount,
                'conversion_pct' => $conversionPct,
                'attendance_pct' => $attendancePct,
            ],
            'daily_rsvps' => $dailyRsvps,
            'status_chart' => $statusChart,
            'group_breakdown' => $groupBars,
            'top_guests' => $topGuests,
        ];
    }

    /**
     * @param  Collection<int, int>  $eventIds
     * @return list<array{date:string,count:int}>
     */
    private function dailyRsvpSeries(Collection $eventIds, int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $driver = Schema::getConnection()->getDriverName();
        $dayExpr = match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d', created_at)",
            default => 'DATE(created_at)',
        };

        /** @var Collection<string, int> $counts */
        $counts = Rsvp::query()
            ->whereIn('event_id', $eventIds)
            ->where('created_at', '>=', $start)
            ->selectRaw("{$dayExpr} as day, COUNT(*) as cnt")
            ->groupByRaw($dayExpr)
            ->pluck('cnt', 'day');

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $start->copy()->addDays($i)->format('Y-m-d');
            $out[] = [
                'date' => $d,
                'count' => (int) ($counts[$d] ?? 0),
            ];
        }

        return $out;
    }
}
