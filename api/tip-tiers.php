<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$processor = active_payment_processor();

if ($processor === 'paypal') {
    $paypal = paypal_tip_tiers();
    $tiers = $paypal['tiers'];

    if (empty($tiers)) {
        json_response([
            'processor' => 'paypal',
            'tiers' => [],
            'error' => 'No PayPal tip amounts configured. Set PAYPAL_TIP_AMOUNTS in .env or admin settings.'
        ], 500);
    }

    json_response([
        'processor' => 'paypal',
        'checkoutUrl' => $paypal['checkoutUrl'] ?? '',
        'currency' => $paypal['currency'] ?? 'USD',
        'tiers' => $tiers
    ]);
}

$tiers = fetch_tip_tiers();
if (empty($tiers)) {
    json_response([
        'processor' => 'stripe',
        'tiers' => [],
        'error' => 'No Stripe tip tiers found. Configure STRIPE_TIER_PRODUCT_IDS or STRIPE_TIER_PRICE_IDS in .env.'
    ], 500);
}

json_response([
    'processor' => 'stripe',
    'tiers' => $tiers
]);
