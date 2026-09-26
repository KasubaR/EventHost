<?php

namespace App\Support;

use App\Models\Event;
use Carbon\CarbonInterface;

/**
 * The three days-before-the-event reminder points a guest can be sent — 7 days, 1 day and the day
 * itself — and the wording of each. Channel-independent: the WhatsApp reminder uses it today and the
 * guest email reminder will (plans/guest-email-reminders.md), so both are due on the same days and
 * say the same thing.
 *
 * Not the RSVP-deadline reminders, which count down to `rsvp_deadline` and use
 * {@see RsvpReminderBuckets}.
 */
final class EventReminderBuckets
{
    public const BUCKET_7 = '7';

    public const BUCKET_1 = '1';

    public const BUCKET_0 = '0';

    /**
     * @var list<string>
     */
    public const ALL = [self::BUCKET_7, self::BUCKET_1, self::BUCKET_0];

    /** The earliest reminder is 7 days out — nothing further away is ever due. */
    public const MAX_LEAD_DAYS = 7;

    public static function isAllowed(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }

    public static function forDaysUntil(int $days): ?string
    {
        return match ($days) {
            7 => self::BUCKET_7,
            1 => self::BUCKET_1,
            0 => self::BUCKET_0,
            default => null,
        };
    }

    /**
     * The bucket an event is due for on the given day, or null. Whole calendar days to `event_date`,
     * so the time of day the scheduler runs at makes no difference.
     */
    public static function forEvent(Event $event, ?CarbonInterface $now = null): ?string
    {
        if ($event->event_date === null) {
            return null;
        }

        $today = ($now ?? now())->copy()->startOfDay();

        return self::forDaysUntil((int) $today->diffInDays($event->event_date->copy()->startOfDay(), false));
    }

    public static function lead(string $eventName, string $bucket): string
    {
        return match ($bucket) {
            self::BUCKET_7 => $eventName.' is one week away!',
            self::BUCKET_1 => 'Reminder: '.$eventName.' is tomorrow.',
            self::BUCKET_0 => 'Today is the big day! We look forward to seeing you at '.$eventName.'.',
            default => $eventName.' is coming up.',
        };
    }
}
