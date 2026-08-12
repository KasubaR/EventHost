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
                'Unlimited guests',
                '30+ premium templates',
                'Advanced RSVP dashboard',
                'WhatsApp reminders',
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
