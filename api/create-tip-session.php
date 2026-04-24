<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_input();
$username = trim((string)($body['username'] ?? ''));
$priceId = trim((string)($body['priceId'] ?? ''));
$requestedProcessor = strtolower(trim((string)($body['processor'] ?? '')));
$allowedProcessors = ['stripe', 'coinbase'];
$processor = $requestedProcessor !== '' ? $requestedProcessor : active_payment_processor();

if (!in_array($processor, $allowedProcessors, true)) {
    json_response(['error' => 'Unsupported payment processor'], 400);
}

if (!is_valid_username($username)) {
    json_response(['error' => 'Username must be 3-24 characters and only include letters, numbers, _ or -.'], 400);
}

if ($priceId === '') {
    json_response(['error' => 'Please select a valid tip tier.'], 400);
}

$tiers = [];
if ($processor === 'coinbase') {
    $coinbase = coinbase_tip_tiers();
    $tiers = $coinbase['tiers'];
} else {
    if ($processor === 'stripe' && preg_match('/^price_[a-zA-Z0-9]+$/', $priceId) !== 1) {
        json_response(['error' => 'Please select a valid Stripe tip tier.'], 400);
    }
    $tiers = fetch_tip_tiers();
}

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

if ($processor === 'coinbase') {
    $baseUrl = rtrim(env_value('BASE_URL', detect_base_url()), '/');
    $coinOptions = crypto_supported_coins();
    $addresses = crypto_receive_addresses();
    $addressMeta = [];
    $derivation = derive_crypto_receive_addresses(
        (string)$tipRecord['id'],
        $username,
        $coinOptions,
        (int)($selectedTier['amountCents'] ?? 0),
        (string)($selectedTier['currency'] ?? 'USD')
    );

    if (($derivation['ok'] ?? false) === true && is_array($derivation['addresses'] ?? null)) {
        foreach ($derivation['addresses'] as $coin => $address) {
            $symbol = strtoupper(trim((string)$coin));
            $value = trim((string)$address);
            if ($symbol !== '' && $value !== '') {
                $addresses[$symbol] = $value;
            }
        }

        if (is_array($derivation['meta'] ?? null)) {
            foreach ($derivation['meta'] as $coin => $metaValue) {
                $symbol = strtoupper(trim((string)$coin));
                if ($symbol !== '' && is_array($metaValue)) {
                    $addressMeta[$symbol] = $metaValue;
                }
            }
        }
    }

    $cryptoAsset = strtoupper(trim(env_value('CRYPTO_ASSET', 'USDC')));
    if (!in_array($cryptoAsset, $coinOptions, true)) {
        $cryptoAsset = $coinOptions[0] ?? 'BTC';
    }
    $receiveAddress = trim((string)($addresses[$cryptoAsset] ?? ''));

    if ($receiveAddress === '') {
        foreach ($coinOptions as $coin) {
            $candidate = trim((string)($addresses[$coin] ?? ''));
            if ($candidate !== '') {
                $cryptoAsset = $coin;
                $receiveAddress = $candidate;
                break;
            }
        }
    }

    if ($receiveAddress === '') {
        update_tip_record(
            static fn(array $tip): bool => ($tip['id'] ?? '') === $tipRecord['id'],
            ['status' => 'checkout_failed']
        );
        json_response(['error' => 'Crypto receive addresses are not configured. Set CRYPTO_RECEIVE_ADDRESSES_JSON in payment settings.'], 500);
    }

    update_tip_record(
        static fn(array $tip): bool => ($tip['id'] ?? '') === $tipRecord['id'],
        [
            'status' => 'awaiting_crypto_payment',
            'sessionId' => (string)$tipRecord['id'],
            'paymentIntentId' => '',
            'receiveAddress' => $receiveAddress,
            'cryptoAsset' => $cryptoAsset,
            'supportedAssets' => $coinOptions,
            'receiveAddresses' => $addresses,
            'receiveAddressMeta' => $addressMeta,
            'receiveAddressSource' => (($derivation['ok'] ?? false) === true) ? 'derived' : 'static',
            'derivationReference' => (string)($derivation['reference'] ?? ''),
            'derivationError' => (string)($derivation['error'] ?? ''),
            'coinbaseTransferStatus' => 'not_requested'
        ]
    );

    json_response([
        'processor' => 'coinbase',
        'checkoutUrl' => $baseUrl . '/public/success.html?session_id=' . rawurlencode((string)$tipRecord['id']),
        'sessionId' => (string)$tipRecord['id']
    ]);
}

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
