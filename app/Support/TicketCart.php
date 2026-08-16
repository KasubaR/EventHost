<?php

namespace App\Support;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The anonymous buyer's cart identifier lives in their session, one per
 * event, so reopening the picker for a second event doesn't clobber the
 * first. See plans/ticketing.md §5.1.
 */
class TicketCart
{
    private static function key(Event $event): string
    {
        return 'ticket_cart.'.$event->id;
    }

    public static function idFor(Request $request, Event $event): ?string
    {
        $value = $request->session()->get(self::key($event));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function getOrCreate(Request $request, Event $event): string
    {
        $existing = self::idFor($request, $event);
        if ($existing !== null) {
            return $existing;
        }

        $cartId = (string) Str::uuid();
        $request->session()->put(self::key($event), $cartId);

        return $cartId;
    }
}
