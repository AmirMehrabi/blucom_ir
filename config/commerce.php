<?php

return [
    'currency' => 'IRT',
    'payment_provider' => 'mellat',
    'catalog_enabled' => (bool) env('COMMERCE_CATALOG_ENABLED', false),
    'reservation_enabled' => (bool) env('COMMERCE_RESERVATION_ENABLED', false),
    // Pilot-only pro forma hold; real-payment policy must be agreed before launch.
    'reservation_minutes' => (int) env('COMMERCE_RESERVATION_MINUTES', 15),
    'checkout_enabled' => (bool) env('COMMERCE_CHECKOUT_ENABLED', false),
];
