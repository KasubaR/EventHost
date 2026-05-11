<?php

return [
    'bulk_send_per_hour' => (int) env('COMM_BULK_SEND_PER_HOUR', 12),
    'reminder_hourly_cap_per_event' => (int) env('COMM_REMINDER_HOURLY_CAP_PER_EVENT', 500),

    'sms' => [
        'enabled' => (bool) env('COMM_SMS_ENABLED', false),
        'driver' => env('COMM_SMS_DRIVER', 'null'),
        'from' => env('COMM_SMS_FROM'),
    ],
];
