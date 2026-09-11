<?php

return [

    'currency' => 'ZMW',

    'plans' => [
        'base' => [
            'label' => 'Base',
            'amount' => 450.00,
            'credits' => 1,
            'tier' => 'base',
            // Guest-list size ceiling, enforced live by Event::guestCapacity()
            // against every Guest row added (single add + CSV import) —
            // separate from the per-event `guest_limit` field a host can set
            // to cap *accepted* attendees. See plans/contributions.md-style
            // rationale in Event::guestCapacity()'s docblock.
            'guest_limit_default' => 150,
            'features' => [
                '1 active event',
                'Up to 150 guests',
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
            'guest_limit_default' => 300,
            'features' => [
                'Up to 300 guests',
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
            // null = unlimited. Enterprise (not sold through this config —
            // see below) is unlimited too, via Event::guestCapacity()'s own
            // rank check.
            'guest_limit_default' => null,
            'features' => [
                'Everything in Pro',
                'Unlimited guests',
                'Custom branding',
                'Email + WhatsApp reminders',
                'White-label invitations',
                'Priority support',
            ],
        ],
    ],

    /*
    | One-time per-event purchases — not subscription tiers, so deliberately
    | not inside `plans` above (no `credits`, no `tier`, nothing that raises
    | the buyer's subscription_tier). See plans/remove-branding.md.
    */
    'addons' => [
        'remove_branding' => [
            'label' => 'Remove Branding',
            'amount' => 250.00,
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
