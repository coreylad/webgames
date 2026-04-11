<?php

declare(strict_types=1);

require_once __DIR__ . '/analytics.php';

// Enhanced webhook event logging and processing

function ensure_webhook_store(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'webhook-events.json';
    if (!is_file($file)) {
        file_put_contents($file, json_encode([
            'events' => [],
            'retries' => [],
            'health' => [
                'lastHealthCheck' => null,
                'processedCount' => 0,
                'failureCount' => 0,
                'avgProcessingTime' => 0
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $file;
}

function read_webhook_store(): array
{
    $file = ensure_webhook_store();
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return [
            'events' => [],
            'retries' => [],
            'health' => [
                'lastHealthCheck' => null,
                'processedCount' => 0,
                'failureCount' => 0
            ]
        ];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['events' => [], 'retries' => []];
}

function write_webhook_store(array $store): void
{
    $file = ensure_webhook_store();
    file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function log_webhook_event(array $event, string $source = 'stripe'): array
{
    $store = read_webhook_store();
    
    $logged = [
        'id' => generate_id(),
        'source' => $source,
        'eventType' => (string)($event['type'] ?? ''),
        'eventId' => (string)($event['id'] ?? ''),
        'data' => $event,
        'ip' => client_ip_address(),
        'receivedAt' => now_iso(),
        'processed' => false,
        'error' => null,
        'retries' => 0,
        'forward' => [
            'enabled' => false,
            'attempted' => false,
            'success' => null,
            'status' => null,
            'target' => null,
            'error' => null,
            'completedAt' => null
        ]
    ];

    $store['events'][] = $logged;
    if (count($store['events']) > 5000) {
        $store['events'] = array_slice($store['events'], -2500);
    }

    write_webhook_store($store);
    
    return $logged;
}

function mark_webhook_forward_result(
    string $webhookId,
    bool $enabled,
    bool $attempted,
    bool $success,
    ?int $statusCode,
    ?string $target,
    ?string $error = null
): void {
    $store = read_webhook_store();

    foreach ($store['events'] as &$event) {
        if ($event['id'] !== $webhookId) {
            continue;
        }

        $event['forward'] = [
            'enabled' => $enabled,
            'attempted' => $attempted,
            'success' => $attempted ? $success : null,
            'status' => $statusCode,
            'target' => $target,
            'error' => $error,
            'completedAt' => now_iso()
        ];
        break;
    }

    write_webhook_store($store);
}

function mark_webhook_processed(string $webhookId, bool $success, ?string $error = null): void
{
    $store = read_webhook_store();
    
    foreach ($store['events'] as &$event) {
        if ($event['id'] === $webhookId) {
            $event['processed'] = true;
            if (!$success) {
                $event['error'] = $error;
                $event['retries'] = ($event['retries'] ?? 0) + 1;
            }
            break;
        }
    }

    $store['health']['processedCount']++;
    if (!$success) {
        $store['health']['failureCount']++;
    }
    $store['health']['lastHealthCheck'] = now_iso();

    write_webhook_store($store);
}

function queue_webhook_retry(string $webhookId, int $delaySeconds = 300): array
{
    $store = read_webhook_store();
    
    $retry = [
        'id' => generate_id(),
        'webhookId' => $webhookId,
        'retryAt' => gmdate('c', time() + $delaySeconds),
        'attempts' => 0,
        'maxAttempts' => 5,
        'createdAt' => now_iso()
    ];

    $store['retries'][] = $retry;
    write_webhook_store($store);
    
    return $retry;
}

function get_pending_retries(): array
{
    $store = read_webhook_store();
    $now = now_iso();
    
    $pending = [];
    foreach ($store['retries'] as $retry) {
        if ($retry['attempts'] >= $retry['maxAttempts']) {
            continue;
        }
        
        if ($retry['retryAt'] <= $now) {
            $pending[] = $retry;
        }
    }
    
    return $pending;
}

function get_webhook_health(): array
{
    $store = read_webhook_store();
    $health = $store['health'] ?? [];
    
    $successRate = 0;
    if ($health['processedCount'] > 0) {
        $successRate = round((($health['processedCount'] - $health['failureCount']) / $health['processedCount']) * 100, 2);
    }
    
    return [
        'totalProcessed' => $health['processedCount'] ?? 0,
        'failureCount' => $health['failureCount'] ?? 0,
        'successRate' => $successRate,
        'lastHealthCheck' => $health['lastHealthCheck'],
        'pendingRetries' => count(get_pending_retries()),
        'status' => $successRate >= 95 ? 'healthy' : 'degraded'
    ];
}

function get_webhook_events(string $eventType = '', int $limit = 100): array
{
    $store = read_webhook_store();
    
    $events = [];
    foreach ($store['events'] as $event) {
        if ($eventType !== '' && $event['eventType'] !== $eventType) {
            continue;
        }
        $events[] = $event;
    }
    
    usort($events, fn($a, $b) => strcmp($b['receivedAt'], $a['receivedAt']));
    
    return array_slice($events, 0, $limit);
}

function replay_webhook_guard(string $eventId, string $source = 'stripe'): bool
{
    $store = read_webhook_store();
    
    foreach ($store['events'] as $event) {
        if ($event['eventId'] === $eventId && $event['source'] === $source) {
            return false; // Already processed
        }
    }
    
    return true; // Safe to process
}

function process_checkout_completed(array $event, string $webhookId): void
{
    require_once __DIR__ . '/common.php';
    
    $session = $event['data']['object'] ?? null;
    if (!is_array($session)) {
        mark_webhook_processed($webhookId, false, 'Invalid session data');
        return;
    }

    $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
    $tipRecordId = (string)($metadata['tipRecordId'] ?? '');
    $username = (string)($metadata['username'] ?? 'anonymous');
    $integration = (string)($metadata['integration'] ?? '');

    $updates = [
        'username' => $username,
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

    if ($integration === 'one_time_checkout') {
        add_one_time_checkout_completion([
            'eventId' => (string)($event['id'] ?? ''),
            'sessionId' => (string)($session['id'] ?? ''),
            'paymentStatus' => (string)($session['payment_status'] ?? ''),
            'amountCents' => (int)($session['amount_total'] ?? 0),
            'currency' => strtolower((string)($session['currency'] ?? 'usd')),
            'completedAt' => now_iso()
        ]);
    }

    // Track revenue analytics
    if ($updates['amountCents'] > 0) {
        track_revenue_event($username, 'tip', $updates['amountCents'], $updates['currency'], [
            'sessionId' => $updates['sessionId'],
            'paymentIntentId' => $updates['paymentIntentId']
        ]);
        
        // Award supporter achievement if first tip
        require_once __DIR__ . '/achievements.php';
        $tips = find_tip_record(fn($t) => ($t['username'] ?? '') === $username);
        if ($tips !== null && ($tips['status'] ?? '') === 'paid') {
            earn_achievement($username, 'supporter');
        }
    }

    mark_webhook_processed($webhookId, true);
}

function process_checkout_expired(array $event, string $webhookId): void
{
    require_once __DIR__ . '/common.php';
    
    $session = $event['data']['object'] ?? null;
    if (!is_array($session)) {
        mark_webhook_processed($webhookId, false, 'Invalid session data');
        return;
    }

    update_tip_record(
        static fn(array $tip): bool => ($tip['sessionId'] ?? '') === ($session['id'] ?? ''),
        ['status' => 'expired']
    );

    mark_webhook_processed($webhookId, true);
}

function process_charge_refunded(array $event, string $webhookId): void
{
    require_once __DIR__ . '/common.php';
    
    $charge = $event['data']['object'] ?? null;
    if (!is_array($charge)) {
        mark_webhook_processed($webhookId, false, 'Invalid charge data');
        return;
    }

    $chargeId = (string)($charge['id'] ?? '');
    
    $tip = find_tip_record(fn($t) => ($t['paymentIntentId'] ?? '') === $chargeId);
    if ($tip !== null) {
        update_tip_record(
            fn($t) => ($t['id'] ?? '') === ($tip['id'] ?? ''),
            ['status' => 'refunded', 'refundedAt' => now_iso()]
        );

        // Track analytics
        track_revenue_event($tip['username'] ?? 'unknown', 'refund', -($tip['amountCents'] ?? 0), $tip['currency'] ?? 'usd');
    }

    mark_webhook_processed($webhookId, true);
}
