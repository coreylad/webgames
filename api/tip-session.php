<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$sessionId = trim((string)($_GET['session_id'] ?? ''));
if ($sessionId === '') {
    json_response(['error' => 'Missing session_id'], 400);
}

$tip = find_tip_record(static fn(array $item): bool => ($item['sessionId'] ?? '') === $sessionId);
if ($tip === null) {
    json_response(['error' => 'Tip session not found'], 404);
}

json_response([
    'username' => (string)($tip['username'] ?? 'anonymous'),
    'amountCents' => (int)($tip['amountCents'] ?? 0),
    'status' => (string)($tip['status'] ?? 'processing'),
    'paidAt' => $tip['paidAt'] ?? null,
    'tierName' => (string)($tip['tierName'] ?? 'Tip Tier'),
    'processor' => (string)($tip['processor'] ?? 'stripe'),
    'cryptoAsset' => (string)($tip['cryptoAsset'] ?? ''),
    'receiveAddress' => (string)($tip['receiveAddress'] ?? ''),
    'txHash' => (string)($tip['txHash'] ?? ''),
    'coinbaseTransferStatus' => (string)($tip['coinbaseTransferStatus'] ?? 'not_requested')
]);
