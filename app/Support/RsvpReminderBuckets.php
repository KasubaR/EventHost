<?php

namespace App\Support;

use App\Models\Guest;

/**
 * Canonical RSVP reminder send markers stored on {@see Guest::$rsvp_reminders_sent}.
 *
 * Shape: JSON array of distinct string bucket ids only — never objects or nested structures.
 * Each element records that the reminder window "N days or fewer until the deadline" has been used
 * (`SendRsvpReminderNotificationsCommand` counts whole venue-calendar days to the deadline day).
 * A window is consumed when a reminder goes out while the deadline is inside it, so a deadline set
 * 5 days out still gets its reminder, and a missed scheduler day is caught up on the next run.
 * Sending marks every window already crossed, so a guest gets at most one reminder per run.
 * See plans/rsvp-deadline-fixes.md (G6, G7, D6).
 *
 * Allowed values (string digits):
 * - {@see self::BUCKET_7} — reminder sent when deadline was 7 days away
 * - {@see self::BUCKET_3} — 3 days away
 * - {@see self::BUCKET_1} — 1 day away (typically “tomorrow” copy in email)
 *
 * Examples:
 * - After first reminder only: `["7"]`
 * - Full cadence: `["7","3","1"]` (order reflects send order)
 *
 * Any legacy or malformed entries are stripped by {@see self::normalize()}; unknown strings never persist.
 */
final class RsvpReminderBuckets
{
    public const BUCKET_7 = '7';

    public const BUCKET_3 = '3';

    public const BUCKET_1 = '1';

    /**
     * @var list<string>
     */
    public const ALL = [self::BUCKET_7, self::BUCKET_3, self::BUCKET_1];

    /**
     * The windows a deadline `$daysUntil` whole days away has reached: every bucket of that many days or
     * more (7, 3 and 1 days). Empty while the deadline is more than 7 days away. 0 (closes today) has
     * reached all three.
     *
     * @return list<string>
     */
    public static function eligibleFor(int $daysUntil): array
    {
        return array_values(array_filter(
            self::ALL,
            fn (string $bucket): bool => (int) $bucket >= $daysUntil,
        ));
    }

    /**
     * The window a deadline `$daysUntil` days away is currently in: the smallest bucket it has reached
     * (5 days out is the 7-day window, 2 days out the 3-day window, today and tomorrow the 1-day
     * window), or null while more than 7 days away. Used in the idempotency key.
     */
    public static function windowFor(int $daysUntil): ?string
    {
        $eligible = self::eligibleFor($daysUntil);

        return $eligible === [] ? null : end($eligible);
    }

    /**
     * Append several buckets at once (the windows a reminder just consumed).
     *
     * @param  list<string>  $current
     * @param  list<string>  $buckets
     * @return list<string>
     */
    public static function withBucketsAppended(array $current, array $buckets): array
    {
        $out = self::normalize($current);

        foreach ($buckets as $bucket) {
            $out = self::withBucketAppended($out, $bucket);
        }

        return $out;
    }

    public static function isAllowed(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }

    /**
     * Normalize raw DB JSON / request values into only allowed bucket ids, deduped, stable order.
     *
     * @return list<string>
     */
    public static function normalize(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (! is_array($decoded)) {
                return [];
            }
            $value = $decoded;
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_string($item) || is_int($item)) {
                $candidate = (string) $item;
            } else {
                continue;
            }

            if ($candidate !== '' && self::isAllowed($candidate) && ! in_array($candidate, $out, true)) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * Validate then merge one bucket into an existing normalized list (used by the reminder job).
     *
     * @param  list<string>  $current  Already-normalized list (e.g. from model attribute)
     * @return list<string>
     */
    public static function withBucketAppended(array $current, string $bucket): array
    {
        $normalizedCurrent = self::normalize($current);

        if (! self::isAllowed($bucket)) {
            return $normalizedCurrent;
        }

        if (in_array($bucket, $normalizedCurrent, true)) {
            return $normalizedCurrent;
        }

        $normalizedCurrent[] = $bucket;

        return $normalizedCurrent;
    }
}
