<?php

return [
    'bulk_send_per_hour' => (int) env('COMM_BULK_SEND_PER_HOUR', 12),
    'reminder_hourly_cap_per_event' => (int) env('COMM_REMINDER_HOURLY_CAP_PER_EVENT', 500),

    'sms' => [
        'enabled' => (bool) env('COMM_SMS_ENABLED', false),
        'driver' => env('COMM_SMS_DRIVER', 'null'),
        'from' => env('COMM_SMS_FROM'),
    ],

    // Slice E (Android push) — mirrors 'sms' above exactly: disabled by
    // default, App\Services\NullPushNotificationService is bound until real
    // Firebase credentials (config('services.fcm.*')) are added.
    'push' => [
        'enabled' => (bool) env('COMM_PUSH_ENABLED', false),
    ],

    // Guest invitations via Twilio WhatsApp (plans/whatsapp-invitations.md). Same
    // enabled-flag posture as 'sms'/'push' — App\Services\NullWhatsAppService is bound until
    // this is true and config('services.twilio.*') is fully populated.
    'whatsapp' => [
        'enabled' => (bool) env('COMM_WHATSAPP_ENABLED', false),
        // Second, event-scoped guard on top of 'bulk_send_per_hour' above — WhatsApp sends cost
        // money per message (unlike the free wa.me manual link), so a bulk action on one event
        // shouldn't be able to blow through Twilio's/Meta's own rate limits in one click.
        'hourly_cap_per_event' => (int) env('COMM_WHATSAPP_HOURLY_CAP_PER_EVENT', 100),
    ],
];
