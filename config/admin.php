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
    | `require_help_request` is the consent gate: a session may only start while the
    | client has an in-progress help request assigned to that admin, and it ends the
    | moment that request is cancelled, completed, declined or expires. Leave it on —
    | turning it off lets any admin with the permission act on any active client, and
    | exists only for exercising the session itself in local development.
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

    /*
    | How long a claimed help request lets the assigned admin act on the client's account
    | before it expires. The client can end it sooner by cancelling.
    */

    'help_requests' => [
        'access_days' => (int) env('ADMIN_HELP_REQUEST_ACCESS_DAYS', 7),
    ],

];
