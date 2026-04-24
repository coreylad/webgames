<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_input();
$sessionId = trim((string)($body['session_id'] ?? ''));
$txHash = trim((string)($body['txHash'] ?? ''));
$asset = strtoupper(trim((string)($body['asset'] ?? '')));

if ($sessionId === '') {
    json_response(['error' => 'session_id is required'], 400);
}

if ($txHash === '') {
    json_response(['error' => 'txHash is required'], 400);
}

$tip = find_tip_record(static fn(array $item): bool => ($item['sessionId'] ?? '') === $sessionId);
if ($tip === null) {
    json_response(['error' => 'Tip session not found'], 404);
}

if (!in_array((string)($tip['processor'] ?? ''), ['btcpay', 'coinbase'], true)) {
    json_response(['error' => 'This endpoint only accepts local crypto sessions'], 400);
}

$supportedAssets = is_array($tip['supportedAssets'] ?? null) ? $tip['supportedAssets'] : crypto_supported_coins();
if ($asset === '' || !in_array($asset, $supportedAssets, true)) {
    $asset = strtoupper((string)($tip['cryptoAsset'] ?? ($supportedAssets[0] ?? 'BTC')));
}

$addresses = is_array($tip['receiveAddresses'] ?? null) ? $tip['receiveAddresses'] : crypto_receive_addresses();
$selectedAddress = trim((string)($addresses[$asset] ?? ($tip['receiveAddress'] ?? '')));

update_tip_record(
    static fn(array $item): bool => ($item['sessionId'] ?? '') === $sessionId,
    [
        'status' => 'payment_submitted',
        'txHash' => $txHash,
        'cryptoAsset' => $asset,
        'receiveAddress' => $selectedAddress,
        'submittedAt' => now_iso()
    ]
);

json_response([
    'status' => 'ok',
    'message' => 'Crypto payment hash submitted. Admin confirmation pending.'
]);
