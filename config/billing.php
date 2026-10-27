<?php

return [
    // Currency for new demo invoices; does not relabel existing billing history.
    'seed_currency' => env('BILLING_SEED_CURRENCY', 'USD'),

    // Required only when upgrading a database containing legacy invoices.
    'legacy_currency' => env('BILLING_LEGACY_CURRENCY'),
];
