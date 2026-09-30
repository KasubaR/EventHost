<?php

namespace App\Services;

use App\Models\Event;
use App\Models\GuestGroup;

/**
 * plans/group-rsvp-links.md — decides what a visitor to a group's shared RSVP link sees.
 * One place, so the page and the submit action can never disagree about "open".
 */
class GroupRsvpResolver
{
    public const OPEN = 'open';

    public const FULL = 'full';

    /** The host switched the link off, or the RSVP window/event is over. */
    public const CLOSED = 'closed';

    /** Cancelled, paused or deleted. */
    public const UNAVAILABLE = 'unavailable';

    /**
     * @return array{group: GuestGroup, event: Event, state: string, remaining: int}
     */
    public function resolve(string $token): array
    {
        abort_if($token === '', 404);

        /** @var GuestGroup $group */
        $group = GuestGroup::query()
            ->where('rsvp_token', $token)
            ->whereNotNull('seat_limit')
            ->firstOrFail();

        /** @var Event|null $event */
        $event = Event::query()->withTrashed()->with('user')->find($group->event_id);

        abort_if($event === null || ! $event->isInvitation(), 404);

        $remaining = $group->seatsRemaining();

        return [
            'group' => $group,
            'event' => $event,
            'state' => $this->stateFor($group, $event, $remaining),
            'remaining' => $remaining,
        ];
    }

    public function stateFor(GuestGroup $group, Event $event, int $remaining): string
    {
        if ($event->trashed() || $event->isCancelled() || $event->isInvitationPaused() || ! $event->is_published) {
            return self::UNAVAILABLE;
        }

        if (! $group->isLinkOpen() || ! $event->isRsvpOpen()) {
            return self::CLOSED;
        }

        return $remaining <= 0 ? self::FULL : self::OPEN;
    }
}
