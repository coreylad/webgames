<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/webhook-advanced.php';

function forward_webhook_payload(string $payload, string $signatureHeader, array $event, string $source): array
{
    $forwardUrl = trim(env_value('WEBHOOK_FORWARD_URL', ''));
    if ($forwardUrl === '') {
        return [
            'enabled' => false,
            'attempted' => false,
            'success' => false,
            'status' => null,
            'target' => null,
            'error' => null
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'enabled' => true,
            'attempted' => true,
            'success' => false,
            'status' => 500,
            'target' => $forwardUrl,
            'error' => 'PHP curl extension is required for webhook forwarding'
        ];
    }

    $authHeaderName = trim(env_value('WEBHOOK_FORWARD_AUTH_HEADER', 'x-webgames-proxy-token'));
    if ($authHeaderName === '') {
        $authHeaderName = 'x-webgames-proxy-token';
    }
    $authToken = trim(env_value('WEBHOOK_FORWARD_AUTH_TOKEN', ''));

    $headers = [
        'Content-Type: application/json',
        'User-Agent: webgames-webhook-proxy/1.0',
        'X-Webgames-Proxy-Hop: 1',
        'X-Webgames-Proxy-Source: ' . $source,
        'X-Webgames-Proxy-Event: ' . (string)($event['id'] ?? ''),
        'X-Webgames-Proxy-Type: ' . (string)($event['type'] ?? '')
    ];

    if ($signatureHeader !== '') {
        $headers[] = 'Stripe-Signature: ' . $signatureHeader;
    }

    if ($authToken !== '') {
        $headers[] = $authHeaderName . ': ' . $authToken;
    }

    $ch = curl_init($forwardUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false) {
        return [
            'enabled' => true,
            'attempted' => true,
            'success' => false,
            'status' => 500,
            'target' => $forwardUrl,
            'error' => $curlError !== '' ? $curlError : 'Webhook forward request failed'
        ];
    }

    $ok = $statusCode >= 200 && $statusCode < 300;
    return [
        'enabled' => true,
        'attempted' => true,
        'success' => $ok,
        'status' => $statusCode,
        'target' => $forwardUrl,
        'error' => $ok ? null : 'Webhook forward endpoint returned non-2xx status'
    ];
}

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

$proxyHopHeader = trim(get_header_value('x-webgames-proxy-hop'));
$isProxiedRequest = ctype_digit($proxyHopHeader) && (int)$proxyHopHeader > 0;

$forwardResult = [
    'enabled' => false,
    'attempted' => false,
    'success' => false,
    'status' => null,
    'target' => null,
    'error' => null
];

if (!$isProxiedRequest) {
    $forwardResult = forward_webhook_payload($payload, $signatureHeader, $event, env_value('BASE_URL', detect_base_url()));
}

mark_webhook_forward_result(
    $webhookId,
    (bool)($forwardResult['enabled'] ?? false),
    (bool)($forwardResult['attempted'] ?? false),
    (bool)($forwardResult['success'] ?? false),
    isset($forwardResult['status']) ? (int)$forwardResult['status'] : null,
    isset($forwardResult['target']) ? (string)$forwardResult['target'] : null,
    isset($forwardResult['error']) ? (string)$forwardResult['error'] : null
);

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
    'error' => $error,
    'forwarded' => [
        'enabled' => (bool)($forwardResult['enabled'] ?? false),
        'attempted' => (bool)($forwardResult['attempted'] ?? false),
        'success' => (bool)($forwardResult['success'] ?? false),
        'status' => $forwardResult['status'] ?? null,
        'target' => $forwardResult['target'] ?? null,
        'error' => $forwardResult['error'] ?? null,
        'skippedReason' => $isProxiedRequest ? 'Request already contains proxy hop header' : null
    ]
]);
