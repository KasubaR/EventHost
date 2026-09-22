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

    /*
    |--------------------------------------------------------------------------
    | Contributions
    |--------------------------------------------------------------------------
    |
    | Platform-wide switch for guest contributions (plans/contributions.md).
    | Off while the two-portal split is built (plans/public-private-portals.md).
    | It only stops new pledges: Event::acceptsContributions() reads it, so the
    | contribute page, invitation banner, host summary and API flags all go
    | dark together. A pledge already in flight can still be paid and verified,
    | and the payment webhook still credits it — money already on its way is
    | never orphaned. Stored contribution settings are left untouched, so turning
    | this back on restores every event exactly as it was.
    |
    */

    'contributions' => [
        'enabled' => (bool) env('CONTRIBUTIONS_ENABLED', false),
    ],

];
