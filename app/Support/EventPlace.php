<?php

namespace App\Support;

use App\Models\Event;

/**
 * Where an event is, as far as the host has said. Venue, location label and map pin are each optional, so a guest page needs one
 * answer to "do we know anything about the place?" instead of every layout testing the three fields its own way.
 */
final class EventPlace
{
    /** Printed where a guest would look for the venue and there is nothing to show. */
    public const TO_BE_ANNOUNCED = 'To be announced';

    public const TO_BE_ANNOUNCED_LINE = 'Venue to be announced';

    public static function hasPin(Event $event): bool
    {
        return $event->latitude !== null && $event->longitude !== null;
    }

    /** True when the host has given no venue, no location label, no address and no pin. */
    public static function isUnknown(Event $event): bool
    {
        return trim((string) $event->venue) === ''
            && trim((string) $event->location_name) === ''
            && trim((string) $event->formatted_address) === ''
            && ! self::hasPin($event);
    }

    /**
     * A Google Maps search for the typed place, for an event with a name but no pin (the map links need coordinates). Null when there is
     * a pin (those links are used instead) or nothing to search for.
     */
    public static function searchUrl(Event $event): ?string
    {
        if (self::hasPin($event)) {
            return null;
        }

        $query = trim(implode(', ', array_filter([
            trim((string) $event->venue),
            trim((string) $event->location_name),
        ], static fn (string $part): bool => $part !== '')));

        if ($query === '') {
            $query = trim((string) $event->formatted_address);
        }

        return $query === '' ? null : 'https://www.google.com/maps/search/?api=1&query='.urlencode(mb_substr($query, 0, 300));
    }
}
