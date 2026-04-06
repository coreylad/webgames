<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$tiers = fetch_tip_tiers();
if (empty($tiers)) {
    json_response([
        'tiers' => [],
        'error' => 'No Stripe tip tiers found. Configure STRIPE_TIER_PRODUCT_IDS or STRIPE_TIER_PRICE_IDS in .env.'
    ], 500);
}

json_response(['tiers' => $tiers]);
