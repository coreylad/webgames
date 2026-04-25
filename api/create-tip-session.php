<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_input();
$username = trim((string)($body['username'] ?? ''));
$priceId = trim((string)($body['priceId'] ?? ''));
$requestedProcessor = strtolower(trim((string)($body['processor'] ?? 'stripe')));
if ($requestedProcessor !== '' && $requestedProcessor !== 'stripe') {
    json_response(['error' => 'Only Stripe is enabled.'], 400);
}

if (!is_valid_username($username)) {
    json_response(['error' => 'Username must be 3-24 characters and only include letters, numbers, _ or -.'], 400);
}

if ($priceId === '') {
    json_response(['error' => 'Please select a valid tip tier.'], 400);
}

$processor = 'stripe';

if (preg_match('/^price_[a-zA-Z0-9]+$/', $priceId) !== 1) {
    json_response(['error' => 'Please select a valid Stripe tip tier.'], 400);
}

$tiers = fetch_tip_tiers();

$selectedTier = null;
foreach ($tiers as $tier) {
    if (($tier['id'] ?? '') === $priceId) {
        $selectedTier = $tier;
        break;
    }
}

if ($selectedTier === null) {
    json_response(['error' => 'Selected tier is not available for the chosen payment processor.'], 400);
}

$tipRecord = add_tip_record([
    'id' => generate_id(),
    'username' => $username,
    'processor' => $processor,
    'priceId' => $selectedTier['id'],
    'tierName' => $selectedTier['productName'],
    'amountCents' => (int)$selectedTier['amountCents'],
    'currency' => strtolower((string)$selectedTier['currency']),
    'status' => 'checkout_pending',
    'sessionId' => '',
    'paymentIntentId' => '',
    'customerEmail' => '',
    'createdAt' => now_iso(),
    'updatedAt' => now_iso()
]);

$baseUrl = rtrim(env_value('BASE_URL', detect_base_url()), '/');
$response = stripe_request('POST', 'checkout/sessions', [
    'mode' => 'payment',
    'success_url' => $baseUrl . '/public/success.html?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => $baseUrl . '/public/tip.html?tip=cancelled',
    'line_items[0][price]' => $selectedTier['id'],
    'line_items[0][quantity]' => '1',
    'metadata[username]' => $username,
    'metadata[tipRecordId]' => $tipRecord['id']
]);

if (!$response['ok']) {
    update_tip_record(
        static fn(array $tip): bool => ($tip['id'] ?? '') === $tipRecord['id'],
        ['status' => 'checkout_failed']
    );

    $error = (string)($response['error'] ?? 'Unable to create Stripe checkout session');
    json_response(['error' => $error], 500);
}

$session = $response['data'];
update_tip_record(
    static fn(array $tip): bool => ($tip['id'] ?? '') === $tipRecord['id'],
    [
        'status' => 'checkout_created',
        'sessionId' => (string)($session['id'] ?? '')
    ]
);

json_response([
    'processor' => 'stripe',
    'checkoutUrl' => (string)($session['url'] ?? ''),
    'sessionId' => (string)($session['id'] ?? '')
]);
