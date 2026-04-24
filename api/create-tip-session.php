<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

function btcpay_request(string $method, string $path, array $payload = []): array
{
    $serverUrl = rtrim(trim(env_value('BTCPAY_SERVER_URL', '')), '/');
    $apiKey = trim(env_value('BTCPAY_API_KEY', ''));

    if ($serverUrl === '' || $apiKey === '') {
        return ['ok' => false, 'error' => 'BTCPay is not configured. Set server URL and API key in admin crypto settings.'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL extension is required for BTCPay integration.'];
    }

    $url = $serverUrl . '/api/v1/' . ltrim($path, '/');
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'Unable to initialize BTCPay request.'];
    }

    $body = !empty($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES) : '';
    if ($body === false) {
        $body = '';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => [
            'Authorization: token ' . $apiKey,
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $responseBody = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        return ['ok' => false, 'error' => $curlErr !== '' ? $curlErr : 'BTCPay request failed'];
    }

    $decoded = json_decode((string)$responseBody, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $error = trim((string)($decoded['message'] ?? ''));
        if ($error === '') {
            $error = 'BTCPay API request failed with status ' . $statusCode;
        }
        return ['ok' => false, 'error' => $error, 'status' => $statusCode, 'data' => $decoded];
    }

    return ['ok' => true, 'status' => $statusCode, 'data' => $decoded];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_input();
$username = trim((string)($body['username'] ?? ''));
$priceId = trim((string)($body['priceId'] ?? ''));
$requestedProcessor = strtolower(trim((string)($body['processor'] ?? '')));
$allowedProcessors = ['stripe', 'btcpay', 'coinbase'];
$processor = $requestedProcessor !== '' ? $requestedProcessor : active_payment_processor();

if ($processor === 'coinbase') {
    $processor = 'btcpay';
}

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
if ($processor === 'btcpay') {
    $btcpay = btcpay_tip_tiers();
    $tiers = $btcpay['tiers'];
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

if ($processor === 'btcpay') {
    $baseUrl = rtrim(env_value('BASE_URL', detect_base_url()), '/');
    $storeId = trim(env_value('BTCPAY_STORE_ID', ''));
    if ($storeId === '') {
        update_tip_record(
            static fn(array $tip): bool => ($tip['id'] ?? '') === $tipRecord['id'],
            ['status' => 'checkout_failed']
        );
        json_response(['error' => 'BTCPay store ID is not configured.'], 500);
    }

    $amountCents = (int)($selectedTier['amountCents'] ?? 0);
    $currency = strtoupper((string)($selectedTier['currency'] ?? env_value('COINBASE_CURRENCY', 'GBP')));
    $successUrl = $baseUrl . '/public/success.html?session_id=' . rawurlencode((string)$tipRecord['id']);
    $cancelUrl = $baseUrl . '/public/tip.html?tip=cancelled';
    $webhookUrl = $baseUrl . '/api/btcpay-webhook.php';

    $invoicePayload = [
        'amount' => number_format($amountCents / 100, 2, '.', ''),
        'currency' => $currency,
        'orderId' => (string)$tipRecord['id'],
        'itemDesc' => 'Tip for ' . $username,
        'notificationURL' => $webhookUrl,
        'checkout' => [
            'speedPolicy' => 'HighSpeed',
            'redirectURL' => $successUrl,
            'redirectAutomatically' => true,
            'defaultPaymentMethod' => ''
        ],
        'metadata' => [
            'tipRecordId' => (string)$tipRecord['id'],
            'username' => $username,
            'cancelURL' => $cancelUrl
        ]
    ];

    $invoiceResponse = btcpay_request('POST', 'stores/' . rawurlencode($storeId) . '/invoices', $invoicePayload);
    if (($invoiceResponse['ok'] ?? false) !== true) {
        update_tip_record(
            static fn(array $tip): bool => ($tip['id'] ?? '') === $tipRecord['id'],
            ['status' => 'checkout_failed']
        );

        $error = (string)($invoiceResponse['error'] ?? 'Unable to create BTCPay invoice');
        json_response(['error' => $error], 500);
    }

    $invoice = is_array($invoiceResponse['data'] ?? null) ? $invoiceResponse['data'] : [];
    $invoiceId = trim((string)($invoice['id'] ?? ''));
    $checkoutLink = trim((string)($invoice['checkoutLink'] ?? ''));

    if ($invoiceId === '' || $checkoutLink === '') {
        update_tip_record(
            static fn(array $tip): bool => ($tip['id'] ?? '') === $tipRecord['id'],
            ['status' => 'checkout_failed']
        );
        json_response(['error' => 'BTCPay invoice response was missing checkout data.'], 500);
    }

    update_tip_record(
        static fn(array $tip): bool => ($tip['id'] ?? '') === $tipRecord['id'],
        [
            'status' => 'checkout_created',
            'sessionId' => (string)$tipRecord['id'],
            'paymentIntentId' => $invoiceId,
            'btcpayInvoiceId' => $invoiceId,
            'btcpayCheckoutUrl' => $checkoutLink,
            'coinbaseTransferStatus' => 'not_applicable'
        ]
    );

    json_response([
        'processor' => 'btcpay',
        'checkoutUrl' => $checkoutLink,
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
