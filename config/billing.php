<?php

return [
    // Adapters receive tenant credentials per call; never cache merchant clients globally.
    'providers' => [
        'handypay' => \App\Billing\Providers\HandyPayProvider::class,
    ],

    // Currency for new demo invoices; does not relabel existing billing history.
    'seed_currency' => env('BILLING_SEED_CURRENCY', 'USD'),

    // Required only when upgrading a database containing legacy invoices.
    'legacy_currency' => env('BILLING_LEGACY_CURRENCY'),
];
