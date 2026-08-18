<?php

namespace App\Services;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AdminAnalyticsService
{
    /**
     * Full payload for admin charts (all events).
     *
     * @return array{
     *     daily_rsvps: list<array{date:string,count:int}>,
     *     status_chart: list<array{key:string,label:string,count:int,pct:float}>,
     *     monthly_registrations: list<array{label:string,count:int}>,
     *     weekly_events_created: list<array{label:string,count:int}>,
     *     event_types: list<array{key:string,label:string,count:int,pct:float}>,
     * }
     */
    public function chartPayload(): array
    {
        $eventIds = Event::query()->pluck('id');

        if ($eventIds->isEmpty()) {
            return [
                'daily_rsvps' => $this->emptyDailySeries(14),
                'status_chart' => [],
                'monthly_registrations' => [],
                'weekly_events_created' => [],
                'event_types' => [],
            ];
        }

        return [
            'daily_rsvps' => $this->dailyRsvpSeries($eventIds, 14),
            'status_chart' => $this->rsvpStatusChart($eventIds),
            'monthly_registrations' => $this->monthlyRegistrations(12),
            'weekly_events_created' => $this->weeklyEventsCreated(8),
            'event_types' => $this->eventTypeBreakdown(),
        ];
    }

    /**
     * @return list<array{date:string,count:int}>
     */
    private function emptyDailySeries(int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $out[] = [
                'date' => $start->copy()->addDays($i)->format('Y-m-d'),
                'count' => 0,
            ];
        }

        return $out;
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

    /**
     * @param  Collection<int, int>  $eventIds
     * @return list<array{key:string,label:string,count:int,pct:float}>
     */
    private function rsvpStatusChart(Collection $eventIds): array
    {
        $statusCounts = Rsvp::query()
            ->whereIn('event_id', $eventIds)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $acceptedCount = (int) ($statusCounts[RsvpStatus::Accepted->value] ?? 0);
        $declinedCount = (int) ($statusCounts[RsvpStatus::Declined->value] ?? 0);
        $maybeCount = (int) ($statusCounts[RsvpStatus::Maybe->value] ?? 0);
        $totalRsvps = $acceptedCount + $declinedCount + $maybeCount;

        if ($totalRsvps === 0) {
            return [];
        }

        $statusChart = [];
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

        return $statusChart;
    }

    /**
     * @return list<array{label:string,count:int}>
     */
    private function monthlyRegistrations(int $months): array
    {
        $start = now()->subMonths($months - 1)->startOfMonth();
        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $mStart = $start->copy()->addMonths($i)->startOfMonth();
            $mEnd = $start->copy()->addMonths($i)->endOfMonth();
            $count = (int) User::query()
                ->where('created_at', '>=', $mStart)
                ->where('created_at', '<=', $mEnd)
                ->count();
            $out[] = [
                'label' => $mStart->format('M Y'),
                'count' => $count,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{label:string,count:int}>
     */
    private function weeklyEventsCreated(int $weeks): array
    {
        $out = [];
        for ($i = 0; $i < $weeks; $i++) {
            $ws = now()->subWeeks($weeks - 1 - $i)->startOfWeek();
            $we = $ws->copy()->endOfWeek();
            $count = (int) Event::query()
                ->where('created_at', '>=', $ws)
                ->where('created_at', '<=', $we)
                ->count();
            $out[] = [
                'label' => $ws->format('M j').' – '.$we->format('M j'),
                'count' => $count,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{key:string,label:string,count:int,pct:float}>
     */
    private function eventTypeBreakdown(): array
    {
        $rows = Event::query()
            ->selectRaw('event_type, COUNT(*) as c')
            ->groupBy('event_type')
            ->pluck('c', 'event_type');

        $total = (int) $rows->sum();
        if ($total === 0) {
            return [];
        }

        $out = [];
        foreach ($rows as $type => $count) {
            $c = (int) $count;
            if ($c > 0) {
                $out[] = [
                    'key' => (string) $type,
                    'label' => Event::TYPE_LABELS[(string) $type] ?? (string) $type,
                    'count' => $c,
                    'pct' => round(($c / $total) * 100, 1),
                ];
            }
        }

        usort($out, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $out;
    }
}
