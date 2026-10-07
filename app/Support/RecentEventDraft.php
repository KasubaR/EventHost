<?php

namespace App\Support;

use App\Enums\EventProductKind;
use App\Models\Event;

/**
 * Finds the draft a host just created, so pressing Back from the next wizard step and clicking Next again
 * continues that event instead of creating a second one (each stray draft counts toward Event::MAX_OPEN_DRAFTS).
 *
 * "The same" means the same host, name, date, kind and audience, still unpublished, created in the last
 * WINDOW_MINUTES. Anything older, published, or different is a genuinely new event. The window is short on purpose:
 * a host who really wants two events with the same name and date can do so a few minutes later.
 */
final class RecentEventDraft
{
    public const WINDOW_MINUTES = 10;

    /**
     * @param  array<string, mixed>  $data  the validated StoreEventRequest fields
     */
    public static function find(int $userId, array $data): ?Event
    {
        $name = $data['name'] ?? null;
        $kind = $data['product_kind'] ?? null;

        if (! is_string($name) || $name === '' || ! is_string($kind)) {
            return null;
        }

        $query = Event::query()
            ->where('user_id', $userId)
            ->where('is_published', false)
            ->where('name', $name)
            ->where('product_kind', $kind)
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES));

        isset($data['event_date']) && $data['event_date'] !== ''
            ? $query->whereDate('event_date', (string) $data['event_date'])
            : $query->whereNull('event_date');

        // A ticketed event is always public whatever was submitted, so its audience says nothing.
        if ($kind !== EventProductKind::Ticketed->value && isset($data['audience']) && is_string($data['audience'])) {
            $query->where('audience', $data['audience']);
        }

        return $query->latest('id')->first();
    }
}
