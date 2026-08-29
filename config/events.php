<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Venue timezone
    |--------------------------------------------------------------------------
    |
    | The clock a door actually runs on. Stored timestamps stay UTC
    | (config('app.timezone')) — this is only for venue-facing rules, of which
    | the check-in window below is the first. A single platform-wide value
    | rather than a per-event column: EventHost sells in ZMW, validates Zambian
    | phone numbers and is registered in Lusaka, so every venue is UTC+2 today.
    | Give events their own timezone column before selling across borders.
    |
    */

    'timezone' => env('EVENT_VENUE_TIMEZONE', 'Africa/Lusaka'),

    /*
    |--------------------------------------------------------------------------
    | Door check-in window
    |--------------------------------------------------------------------------
    |
    | Hours either side of an event's start instant during which staff may scan.
    | The lead covers early entry the evening before; the tail covers a session
    | that runs past midnight onto a new calendar date. Both are also the blast
    | radius of a leaked ticket, so widen them deliberately.
    |
    */

    'check_in' => [
        'opens_hours_before' => (int) env('CHECKIN_OPENS_HOURS_BEFORE', 24),
        'closes_hours_after' => (int) env('CHECKIN_CLOSES_HOURS_AFTER', 12),
    ],

];
