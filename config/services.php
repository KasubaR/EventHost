<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'lenco' => [
        'base_url' => env('LENCO_API_BASE_URL', 'https://api.lenco.co/access/v2'),
        'api_secret_key' => env('LENCO_API_SECRET_KEY'),
        'public_key' => env('LENCO_PUBLIC_KEY'),
        'webhook_secret' => env('LENCO_WEBHOOK_SECRET'),
        'webhook_url' => env('LENCO_WEBHOOK_URL'),
        'webhook_path' => env('LENCO_WEBHOOK_PATH', 'lenco/webhook'),
        'environment' => env('LENCO_ENVIRONMENT', 'production'),
        'pay_script_url' => env('LENCO_PAY_SCRIPT_URL') ?: (
            env('LENCO_ENVIRONMENT', 'sandbox') === 'production'
                ? 'https://pay.lenco.co/js/v1/inline.js'
                : 'https://pay.sandbox.lenco.co/js/v1/inline.js'
        ),
        'pending_max_age_hours' => (int) env('LENCO_PENDING_MAX_AGE_HOURS', 24),
        'stuck_payment_hours' => (int) env('LENCO_STUCK_PAYMENT_HOURS', 2),
        'poll_max_per_run' => (int) env('LENCO_POLL_MAX_PER_RUN', 50),
        'poll_throttle_ms' => (int) env('LENCO_POLL_THROTTLE_MS', 200),
        'bank_transfer_enabled' => filter_var(env('LENCO_BANK_TRANSFER_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

];
