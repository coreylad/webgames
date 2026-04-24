<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_input();
$sessionId = trim((string)($body['session_id'] ?? ''));
$txHash = trim((string)($body['txHash'] ?? ''));

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

if (($tip['processor'] ?? '') !== 'coinbase') {
    json_response(['error' => 'This endpoint only accepts local crypto sessions'], 400);
}

update_tip_record(
    static fn(array $item): bool => ($item['sessionId'] ?? '') === $sessionId,
    [
        'status' => 'payment_submitted',
        'txHash' => $txHash,
        'submittedAt' => now_iso()
    ]
);

json_response([
    'status' => 'ok',
    'message' => 'Crypto payment hash submitted. Admin confirmation pending.'
]);
