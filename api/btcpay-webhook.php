<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    json_response(['error' => 'Invalid payload'], 400);
}

$secret = trim(env_value('BTCPAY_WEBHOOK_SECRET', ''));
if ($secret !== '') {
    $header = trim((string)($_SERVER['HTTP_BTCPAY_SIG'] ?? ''));
    if ($header === '') {
        json_response(['error' => 'Missing signature'], 401);
    }

    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    if (!hash_equals($expected, $header)) {
        json_response(['error' => 'Invalid signature'], 401);
    }
}

$event = json_decode($rawBody, true);
if (!is_array($event)) {
    json_response(['error' => 'Invalid JSON payload'], 400);
}

$eventType = trim((string)($event['type'] ?? ''));
$invoiceId = trim((string)($event['invoiceId'] ?? ''));
if ($invoiceId === '' && is_array($event['data'] ?? null)) {
    $invoiceId = trim((string)($event['data']['id'] ?? ''));
}

if ($invoiceId === '') {
    json_response(['status' => 'ignored', 'reason' => 'missing-invoice-id']);
}

$tip = find_tip_record(static fn(array $item): bool =>
    (($item['btcpayInvoiceId'] ?? '') === $invoiceId) || (($item['paymentIntentId'] ?? '') === $invoiceId)
);

if ($tip === null) {
    json_response(['status' => 'ignored', 'reason' => 'tip-not-found']);
}

$normalizedType = strtolower($eventType);
$isPaid = in_array($normalizedType, ['invoicesettled', 'invoiceprocessing', 'invoicereceivedpayment'], true);
$isFailed = in_array($normalizedType, ['invoiceexpired', 'invoiceinvalid'], true);

$updates = [
    'updatedAt' => now_iso(),
    'btcpayLastEventType' => $eventType,
    'btcpayLastEventAt' => now_iso(),
    'btcpayInvoiceId' => $invoiceId
];

if ($isPaid) {
    $updates['status'] = 'paid';
    $updates['paidAt'] = now_iso();
} elseif ($isFailed) {
    $updates['status'] = 'checkout_failed';
} else {
    $updates['status'] = (string)($tip['status'] ?? 'checkout_created');
}

update_tip_record(
    static fn(array $item): bool => ($item['id'] ?? '') === ($tip['id'] ?? ''),
    $updates
);

json_response(['status' => 'ok']);
