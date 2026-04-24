<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$requestedProcessor = strtolower(trim((string)($_GET['processor'] ?? '')));
$allowedProcessors = ['stripe', 'coinbase'];
$processor = $requestedProcessor !== '' ? $requestedProcessor : active_payment_processor();

if (!in_array($processor, $allowedProcessors, true)) {
    json_response(['error' => 'Unsupported payment processor'], 400);
}

if ($processor === 'coinbase') {
    $coinbase = coinbase_tip_tiers();
    $tiers = $coinbase['tiers'];

    if (empty($tiers)) {
        json_response([
            'processor' => 'coinbase',
            'tiers' => [],
            'error' => 'No Coinbase tip amounts configured. Set COINBASE_TIP_AMOUNTS in .env or admin settings.'
        ], 500);
    }

    json_response([
        'processor' => 'coinbase',
        'currency' => $coinbase['currency'] ?? 'USD',
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
