F<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$payload = file_get_contents('php://input');
if ($payload === false) {
    json_response(['error' => 'Unable to read webhook payload'], 400);
}

$signatureHeader = get_header_value('stripe-signature');
$webhookSecret = env_value('STRIPE_WEBHOOK_SECRET', '');

if (!stripe_verify_signature($payload, $signatureHeader, $webhookSecret)) {
    json_response(['error' => 'Invalid webhook signature'], 400);
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    json_response(['error' => 'Invalid event payload'], 400);
}

$eventType = (string)($event['type'] ?? '');
$session = $event['data']['object'] ?? null;

if ($eventType === 'checkout.session.completed' && is_array($session)) {
    $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
    $tipRecordId = (string)($metadata['tipRecordId'] ?? '');

    $updates = [
        'username' => (string)($metadata['username'] ?? 'anonymous'),
        'amountCents' => (int)($session['amount_total'] ?? 0),
        'currency' => strtolower((string)($session['currency'] ?? 'usd')),
        'status' => (($session['payment_status'] ?? '') === 'paid') ? 'paid' : 'completed',
        'sessionId' => (string)($session['id'] ?? ''),
        'customerEmail' => (string)($session['customer_details']['email'] ?? ''),
        'paidAt' => now_iso(),
        'paymentIntentId' => (string)($session['payment_intent'] ?? '')
    ];

    if ($tipRecordId !== '') {
        update_tip_record(
            static fn(array $tip): bool => ($tip['id'] ?? '') === $tipRecordId,
            $updates
        );
    } else {
        update_tip_record(
            static fn(array $tip): bool => ($tip['sessionId'] ?? '') === ($session['id'] ?? ''),
            $updates
        );
    }
}

if ($eventType === 'checkout.session.expired' && is_array($session)) {
    update_tip_record(
        static fn(array $tip): bool => ($tip['sessionId'] ?? '') === ($session['id'] ?? ''),
        ['status' => 'expired']
    );
}

json_response(['received' => true]);
