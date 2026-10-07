<?php

/*
|--------------------------------------------------------------------------
| Astragate (card payments)
|--------------------------------------------------------------------------
|
| Card payments go through Astragate's hosted checkout; mobile money and bank
| transfer stay on Lenco (config/services.php). The two gateways share no
| configuration or code. Docs: https://docs.astragate.africa
|
*/

return [

    // Card is off until this is true AND a client id/secret are set — see
    // App\Services\AstragateService::cardEnabled().
    'card_enabled' => filter_var(env('ASTRAGATE_CARD_ENABLED', false), FILTER_VALIDATE_BOOL),

    'base_url' => env('ASTRAGATE_API_BASE_URL', 'https://api.dev.astragate.africa'),
    'auth_url' => env('ASTRAGATE_AUTH_URL', 'https://auth.dev.astragate.africa'),
    'client_id' => env('ASTRAGATE_CLIENT_ID'),
    'client_secret' => env('ASTRAGATE_CLIENT_SECRET'),

    // Callbacks are unsigned, so this random string is the secret part of the callback URL
    // ({APP_URL}/webhooks/astragate/{secret}). The callback body is never trusted: the app
    // re-reads the payment's status from Astragate before acting on it.
    'webhook_secret' => env('ASTRAGATE_WEBHOOK_SECRET'),

];
