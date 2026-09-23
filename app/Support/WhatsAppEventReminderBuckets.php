<?php

namespace App\Support;

/**
 * Canonical WhatsApp event-reminder markers on {@see Guest::$whatsapp_event_reminders_sent}.
 *
 * Timed to event_date (not rsvp_deadline). Allowed buckets:
 * - {@see self::BUCKET_7} — 7 calendar days before the event
 * - {@see self::BUCKET_1} — 1 day before (tomorrow)
 * - {@see self::BUCKET_0} — event day
 */
final class WhatsAppEventReminderBuckets
{
    public const BUCKET_7 = '7';

    public const BUCKET_1 = '1';

    public const BUCKET_0 = '0';

    /**
     * @var list<string>
     */
    public const ALL = [self::BUCKET_7, self::BUCKET_1, self::BUCKET_0];

    public static function isAllowed(string $value): bool
    {
        return in_array($value, self::ALL, true);
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
        return match ($bucket) {
            self::BUCKET_7 => $eventName.' is one week away!',
            self::BUCKET_1 => 'Reminder: '.$eventName.' is tomorrow.',
            self::BUCKET_0 => 'Today is the big day! We look forward to seeing you at '.$eventName.'.',
            default => $eventName.' is coming up.',
        };
    }
}
