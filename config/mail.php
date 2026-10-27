<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        // Never end this list with `log`: a failed send would be "delivered" into the log file, guest details and all.
        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'info@eventhostzm.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Support Address
    |--------------------------------------------------------------------------
    |
    | Where contact form submissions are delivered. Falls back to the global
    | "from" address so the form still reaches someone if it is not set.
    |
    */

    'support_address' => env('MAIL_SUPPORT_ADDRESS', env('MAIL_FROM_ADDRESS', 'info@eventhostzm.com')),

    /*
    |--------------------------------------------------------------------------
    | Bounces
    |--------------------------------------------------------------------------
    |
    | Every outgoing email carries `bounce_address` as its Return-Path, so the
    | reports from servers that could not deliver it land in that mailbox, and
    | `mail:process-bounces` reads them over IMAP. Both are off while unset.
    | See plans/email-gaps.md and docs/deployment.md §3e.
    |
    */

    'bounce_address' => env('MAIL_BOUNCE_ADDRESS'),

    'bounce_imap' => [
        'host' => env('MAIL_BOUNCE_IMAP_HOST'),
        'port' => (int) env('MAIL_BOUNCE_IMAP_PORT', 993),
        'encryption' => env('MAIL_BOUNCE_IMAP_ENCRYPTION', 'ssl'),
        'validate_cert' => (bool) env('MAIL_BOUNCE_IMAP_VALIDATE_CERT', true),
        'username' => env('MAIL_BOUNCE_IMAP_USERNAME'),
        'password' => env('MAIL_BOUNCE_IMAP_PASSWORD'),
        'folder' => env('MAIL_BOUNCE_IMAP_FOLDER', 'INBOX'),
        'processed_folder' => env('MAIL_BOUNCE_IMAP_PROCESSED_FOLDER', 'Processed'),
    ],

];
