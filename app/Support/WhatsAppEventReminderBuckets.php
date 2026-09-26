<?php

namespace App\Support;

/**
 * Canonical WhatsApp event-reminder markers on {@see Guest::$whatsapp_event_reminders_sent}.
 *
 * Timed to event_date (not rsvp_deadline). Allowed buckets:
 * - {@see self::BUCKET_7} — 7 calendar days before the event
 * - {@see self::BUCKET_1} — 1 day before (tomorrow)
 * - {@see self::BUCKET_0} — event day
 *
 * The bucket ids, their schedule and their wording live in {@see EventReminderBuckets}, shared with the
 * email reminder; this class keeps only what is specific to the `guests` column (normalising it).
 */
final class WhatsAppEventReminderBuckets
{
    public const BUCKET_7 = EventReminderBuckets::BUCKET_7;

    public const BUCKET_1 = EventReminderBuckets::BUCKET_1;

    public const BUCKET_0 = EventReminderBuckets::BUCKET_0;

    /**
     * @var list<string>
     */
    public const ALL = EventReminderBuckets::ALL;

    public static function isAllowed(string $value): bool
    {
        return EventReminderBuckets::isAllowed($value);
    }

    /**
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
     * @param  list<string>  $current
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

    public static function leadForBucket(string $eventName, string $bucket): string
    {
        return EventReminderBuckets::lead($eventName, $bucket);
    }
}
