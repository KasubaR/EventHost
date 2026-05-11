<?php

namespace App\Support;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class EventCalendarLinks
{
    /**
     * Start/end instants in app timezone for calendar URLs and ICS.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function window(Event $event): ?array
    {
        if ($event->event_date === null) {
            return null;
        }

        $tz = config('app.timezone');
        $dateStr = $event->event_date->format('Y-m-d');
        $timeStr = $event->event_time ? substr((string) $event->event_time, 0, 8) : '12:00:00';

        try {
            $start = Carbon::parse($dateStr.' '.$timeStr, $tz);
        } catch (\Throwable) {
            return null;
        }

        $end = $start->copy()->addHours(2);

        return [$start, $end];
    }

    public static function googleCalendarUrl(Event $event): ?string
    {
        $w = self::window($event);
        if ($w === null) {
            return null;
        }

        [$start, $end] = $w;
        $startUtc = $start->copy()->utc();
        $endUtc = $end->copy()->utc();
        $dates = $startUtc->format('Ymd\THis\Z').'/'.$endUtc->format('Ymd\THis\Z');

        $query = http_build_query([
            'action' => 'TEMPLATE',
            'text' => $event->name,
            'dates' => $dates,
            'details' => Str::limit(strip_tags((string) ($event->description ?? '')), 800),
            'location' => self::locationString($event),
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://calendar.google.com/calendar/render?'.$query;
    }

    /**
     * Outlook Web compose deeplink (best-effort).
     */
    public static function outlookCalendarUrl(Event $event): ?string
    {
        $w = self::window($event);
        if ($w === null) {
            return null;
        }

        [$start, $end] = $w;

        $params = [
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $event->name,
            'startdt' => $start->format('Y-m-d\TH:i:s'),
            'enddt' => $end->format('Y-m-d\TH:i:s'),
            'body' => Str::limit(strip_tags((string) ($event->description ?? '')), 1200),
            'location' => self::locationString($event),
        ];

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return 'https://outlook.live.com/calendar/0/deeplink/compose?'.$query;
    }

    public static function locationString(Event $event): string
    {
        $parts = array_filter([
            $event->venue,
            $event->location_name,
        ], fn ($v) => is_string($v) && $v !== '');

        return implode(', ', $parts);
    }
}
