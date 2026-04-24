<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$sessionId = trim((string)($_GET['session_id'] ?? ''));
$asset = strtoupper(trim((string)($_GET['asset'] ?? '')));

if ($sessionId === '') {
    json_response(['error' => 'Missing session_id'], 400);
}

$tip = find_tip_record(static fn(array $item): bool => ($item['sessionId'] ?? '') === $sessionId);
if ($tip === null || ($tip['processor'] ?? '') !== 'coinbase') {
    json_response(['error' => 'Crypto tip session not found'], 404);
}

$supported = is_array($tip['supportedAssets'] ?? null) ? $tip['supportedAssets'] : crypto_supported_coins();
if ($asset === '') {
    $asset = strtoupper((string)($tip['cryptoAsset'] ?? ''));
}
if ($asset === '' || !in_array($asset, $supported, true)) {
    $asset = $supported[0] ?? 'BTC';
}

$addresses = is_array($tip['receiveAddresses'] ?? null) ? $tip['receiveAddresses'] : crypto_receive_addresses();
$address = trim((string)($addresses[$asset] ?? ''));
if ($address === '') {
    json_response(['error' => 'No receive address configured for selected asset'], 400);
}

$fiatCurrency = strtoupper((string)($tip['currency'] ?? 'USD'));
$fiatAmount = ((int)($tip['amountCents'] ?? 0)) / 100;
if ($fiatAmount <= 0) {
    json_response(['error' => 'Invalid tip amount'], 400);
}

$rate = 0.0;
if ($asset === $fiatCurrency) {
    $rate = 1.0;
} else {
    $ch = curl_init('https://api.coinbase.com/v2/exchange-rates?currency=' . rawurlencode($asset));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: webgames-crypto-quote/1.0'
        ]
    ]);

    $body = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $statusCode < 200 || $statusCode >= 300) {
        json_response(['error' => 'Unable to fetch live conversion rate'], 502);
    }

    $decoded = json_decode($body, true);
    $rates = is_array($decoded['data']['rates'] ?? null) ? $decoded['data']['rates'] : [];
    $rateRaw = (string)($rates[$fiatCurrency] ?? '0');
    if (!is_numeric($rateRaw) || (float)$rateRaw <= 0) {
        json_response(['error' => 'No conversion rate available for selected asset'], 400);
    }

    $rate = (float)$rateRaw;
}

$cryptoAmount = $rate > 0 ? ($fiatAmount / $rate) : 0;
$cryptoAmountRounded = rtrim(rtrim(number_format($cryptoAmount, 8, '.', ''), '0'), '.');
if ($cryptoAmountRounded === '') {
    $cryptoAmountRounded = '0';
}

$schemeMap = [
    'BTC'  => 'bitcoin',
    'LTC'  => 'litecoin',
    'BCH'  => 'bitcoincash',
    'DOGE' => 'dogecoin',
    'ETH'  => 'ethereum',
    'XRP'  => 'ripple'
];
$scheme = $schemeMap[$asset] ?? strtolower($asset);
$paymentUri = $scheme . ':' . $address . '?amount=' . rawurlencode($cryptoAmountRounded);

json_response([
    'status' => 'ok',
    'asset' => $asset,
    'fiatCurrency' => $fiatCurrency,
    'fiatAmount' => $fiatAmount,
    'rate' => $rate,
    'cryptoAmount' => $cryptoAmountRounded,
    'address' => $address,
    'paymentUri' => $paymentUri,
    'qrUrl' => 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . rawurlencode($paymentUri)
]);
