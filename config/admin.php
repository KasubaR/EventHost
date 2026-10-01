<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Acting as a client
    |--------------------------------------------------------------------------
    |
    | Lets a permitted admin use the host screens as one client, so an event can
    | be set up on their behalf. Plan: plans/admin-create-events.md.
    |
    | `enabled` is the production kill switch — off, nothing can start a session.
    |
    | `require_help_request` is the consent gate (plan Step 0): a session may only
    | start while the client has an in-progress help request assigned to that admin.
    | Step 0 is not built yet, so with this on (the default) no session can start.
    | Turn it off only in local development to exercise the session itself.
    |
    | `ttl_minutes` is a hard ceiling on one session; the admin is returned to the
    | client's admin page when it runs out.
    |
    */

    'acting_as' => [
        'enabled' => (bool) env('ADMIN_ACT_AS_ENABLED', false),
        'require_help_request' => (bool) env('ADMIN_ACT_AS_REQUIRE_REQUEST', true),
        'ttl_minutes' => (int) env('ADMIN_ACT_AS_TTL_MINUTES', 60),
    ],

];
