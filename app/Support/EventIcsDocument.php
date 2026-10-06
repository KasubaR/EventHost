<?php

namespace App\Support;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class EventIcsDocument
{
    public static function filename(Event $event): string
    {
        $slug = $event->slug ?: 'event';

        return Str::slug($slug).'.ics';
    }

    /**
     * RFC 5545-ish ICS document (UTF-8).
     */
    public static function build(Event $event): ?string
    {
        $w = EventCalendarLinks::window($event);
        if ($w === null) {
            return null;
        }

        [$start, $end] = $w;
        $startUtc = $start->copy()->utc();
        $endUtc = $end->copy()->utc();

        $uid = 'evt-'.$event->getKey().'-'.parse_url(config('app.url'), PHP_URL_HOST ?: 'localhost');
        $stamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $dtStart = $startUtc->format('Ymd\THis\Z');
        $dtEnd = $endUtc->format('Ymd\THis\Z');

        $summary = self::escapeText($event->name);
        $location = self::escapeText(EventCalendarLinks::locationString($event));
        $desc = self::escapeText(Str::limit(strip_tags((string) ($event->description ?? '')), 2000));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Event Host//Invitation//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.$stamp,
            'DTSTART:'.$dtStart,
            'DTEND:'.$dtEnd,
            'SUMMARY:'.$summary,
            // An empty LOCATION line reads as "location: nothing" in some calendars; leave the property out instead.
            ...($location !== '' ? ['LOCATION:'.$location] : []),
            'DESCRIPTION:'.$desc,
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * RFC 5545 §3.1: a content line is at most 75 octets; a longer one is folded by starting each continuation line with one space.
     * Folds fall on character boundaries, never inside a multi-byte UTF-8 character, which would corrupt the text.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = [];
        $current = '';
        $limit = 75;

        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out[] = $current;
                $current = '';
                $limit = 74; // the leading space of a continuation line counts toward the 75
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }

    private static function escapeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\n"], '\n', $text);

        return str_replace(['\\', ',', ';'], ['\\\\', '\\,', '\\;'], $text);
    }
}
