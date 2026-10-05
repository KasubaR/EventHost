<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * What the invitation's countdown shows before (or without) JavaScript. The ticker in invitation-public.js
 * takes over and updates it, so the page never flashes "0 0 0 0" first, and with scripts off or still loading it
 * reads a real value. plans/invitation-page-resilience.md Phase 1.
 */
final class InvitationCountdown
{
    /**
     * The same formatting the ticker uses: days unpadded, hours / minutes / seconds two digits. All zero once
     * the start has passed (the ticker shows "This event has started" for that).
     *
     * @return array{days: string, hours: string, minutes: string, seconds: string}
     */
    public static function parts(CarbonInterface $startsAt, ?CarbonInterface $now = null): array
    {
        $diff = max(0, ($now ?? now())->diffInSeconds($startsAt, false));
        $diff = (int) floor($diff);

        return [
            'days' => (string) intdiv($diff, 86400),
            'hours' => str_pad((string) intdiv($diff % 86400, 3600), 2, '0', STR_PAD_LEFT),
            'minutes' => str_pad((string) intdiv($diff % 3600, 60), 2, '0', STR_PAD_LEFT),
            'seconds' => str_pad((string) ($diff % 60), 2, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * The start as a sentence of its own, in the venue's wall-clock time (the instant is already in the venue
     * timezone via Event::startsAt()), for guests whose countdown cannot tick.
     */
    public static function staticLine(CarbonInterface $startsAt): string
    {
        return $startsAt->format('l, F j, Y \a\t g:i A');
    }
}
