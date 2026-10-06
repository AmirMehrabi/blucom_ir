<?php

return [
    'currency' => 'IRT',
    'payment_provider' => 'mellat',
    'catalog_enabled' => (bool) env('COMMERCE_CATALOG_ENABLED', false),
    'checkout_enabled' => (bool) env('COMMERCE_CHECKOUT_ENABLED', false),
];
