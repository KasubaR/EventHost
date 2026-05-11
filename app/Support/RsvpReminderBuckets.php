<?php

namespace App\Support;

use App\Models\Guest;

/**
 * Canonical RSVP reminder send markers stored on {@see Guest::$rsvp_reminders_sent}.
 *
 * Shape: JSON array of distinct string bucket ids only — never objects or nested structures.
 * Each element records that the reminder for "exactly N whole calendar days until deadline start"
 * was sent (`SendRsvpReminderNotificationsCommand` aligns `$today->startOfDay()` with deadline day).
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
