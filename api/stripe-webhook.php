F<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/webhook-advanced.php';

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
    json_response(['error' => 'Invalid webhook signature'], 401);
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    json_response(['error' => 'Invalid event payload'], 400);
}

$eventId = (string)($event['id'] ?? '');

// Prevent replay attacks
if (!replay_webhook_guard($eventId, 'stripe')) {
    json_response(['received' => true, 'cached' => true]);
}

// Log the event for analytics and tracking
$webhookLog = log_webhook_event($event, 'stripe');
$webhookId = $webhookLog['id'];

$eventType = (string)($event['type'] ?? '');
$startTime = microtime(true);
$success = false;
$error = null;

try {
    switch ($eventType) {
        case 'checkout.session.completed':
            process_checkout_completed($event, $webhookId);
            $success = true;
            break;
            
        case 'checkout.session.expired':
            process_checkout_expired($event, $webhookId);
            $success = true;
            break;
            
        case 'charge.refunded':
            process_charge_refunded($event, $webhookId);
            $success = true;
            break;
            
        default:
            // Log but don't error on unknown event types
            mark_webhook_processed($webhookId, true);
            $success = true;
            break;
    }
} catch (Exception $e) {
    $error = $e->getMessage();
    $success = false;
    
    // Queue for retry
    queue_webhook_retry($webhookId, 300);
    
    mark_webhook_processed($webhookId, false, $error);
}

$processingTime = microtime(true) - $startTime;

json_response([
    'received' => true,
    'eventId' => $eventId,
    'eventType' => $eventType,
    'processed' => $success,
    'processingTime' => round($processingTime * 1000, 2) . 'ms',
    'error' => $error
]);
