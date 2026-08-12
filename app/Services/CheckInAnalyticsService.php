<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Carbon;

/**
 * Simple arrivals-over-time trend for the check-in scanner page. Buckets are computed
 * in PHP rather than via DB-specific date-truncation SQL so this behaves identically
 * on SQLite (tests) and MySQL (production) — event guest counts are small enough that
 * this costs nothing meaningful.
 */
class CheckInAnalyticsService
{
    /**
     * @return list<array{label: string, count: int}>
     */
    public function arrivalsByBucket(Event $event, int $bucketMinutes = 15): array
    {
        $timestamps = $event->guests()
            ->whereNotNull('checked_in_at')
            ->orderBy('checked_in_at')
            ->pluck('checked_in_at');

        if ($timestamps->isEmpty()) {
            return [];
        }

        $buckets = [];

        foreach ($timestamps as $timestamp) {
            $carbon = $timestamp instanceof Carbon ? $timestamp : Carbon::parse($timestamp);
            $bucketStart = $carbon->copy()->setTime(
                $carbon->hour,
                intdiv($carbon->minute, $bucketMinutes) * $bucketMinutes
            );
            $key = $bucketStart->format('Y-m-d H:i');

            $buckets[$key] ??= ['label' => $bucketStart->format('g:ia'), 'count' => 0];
            $buckets[$key]['count']++;
        }

        ksort($buckets);

        return array_values($buckets);
    }
}
