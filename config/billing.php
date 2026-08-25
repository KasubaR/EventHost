<?php

return [

    'currency' => 'ZMW',

    'plans' => [
        'base' => [
            'label' => 'Base',
            'amount' => 450.00,
            'credits' => 1,
            'tier' => 'base',
            'guest_limit_default' => 50,
            'features' => [
                '1 active event',
                'Up to 50 guests',
                '1 free template',
                'Basic RSVP tracking',
                'WhatsApp sharing',
            ],
        ],
        'pro' => [
            'label' => 'Pro',
            'amount' => 750.00,
            'credits' => 1,
            'tier' => 'pro',
            'guest_limit_default' => null,
            'features' => [
                'Up to 150 guests',
                // {template_count} is resolved at render time — see
                // billing/checkout.blade.php — from InvitationTemplate::activeCount().
                '{template_count} premium templates',
                'Advanced RSVP dashboard',
                'Photo gallery',
                'Countdown timer',
                'Analytics & exports',
            ],
        ],
        'pro_plus' => [
            'label' => 'Pro+',
            'amount' => 1500.00,
            'credits' => 1,
            'tier' => 'pro_plus',
            'guest_limit_default' => null,
            'features' => [
                'Everything in Pro',
                'Custom branding',
                'Email + WhatsApp reminders',
                'Multiple team members',
                'White-label invitations',
                'Priority support',
            ],
        ],
    ],

    /*
    | Homepage / checkout "Most Popular" badge. Driven by completed plan
    | purchases over a rolling window. When no plan meets min_sales, no card
    | gets the badge (there is no hardcoded fallback plan).
    */
    'popular' => [
        'window_days' => 30,
        'min_sales' => 3,
        'lead_margin' => 0.20,
        'cache_ttl_hours' => 24,
    ],

    /*
    | Used when the Lenco /banks API is unavailable or returns no Zambia banks.
    | Names should match what Lenco expects in bankName on bank-transfer initiate.
    */
    'fallback_banks' => [
        'Zanaco',
        'Stanbic Bank Zambia',
        'First National Bank (FNB)',
        'Standard Chartered Bank',
        'Access Bank Zambia',
        'Ecobank Zambia',
        'Indo-Zambia Bank',
        'Investrust Bank',
        'National Savings and Credit Bank (NATSAVE)',
        'United Bank for Africa (UBA)',
        'Zambia Industrial Commercial Bank (ZICB)',
        'AB Bank Zambia',
        'Atlas Mara Bank',
        'Bank of China Zambia',
        'Citibank Zambia',
    ],

];
