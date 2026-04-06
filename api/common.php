<?php

declare(strict_types=1);

function detect_base_url(): string
{
    if (PHP_SAPI === 'cli') {
        return 'http://localhost';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function load_env_values(): array
{
    static $values = null;
    if ($values !== null) {
        return $values;
    }

    $values = [
        'STRIPE_SECRET_KEY' => '',
        'STRIPE_WEBHOOK_SECRET' => '',
        'ADMIN_DASHBOARD_TOKEN' => 'dev-admin-token',
        'BASE_URL' => detect_base_url(),
        'STRIPE_TIER_PRODUCT_IDS' => '',
        'STRIPE_TIER_PRICE_IDS' => ''
    ];

    $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envPath)) {
        return $values;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return $values;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($value !== '' && (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        )) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '') {
            $values[$key] = $value;
        }
    }

    return $values;
}

function env_value(string $key, string $fallback = ''): string
{
    $values = load_env_values();
    $value = $values[$key] ?? $fallback;
    return is_string($value) ? $value : $fallback;
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function ensure_tip_store(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'tips.json';
    if (!is_file($file)) {
        file_put_contents($file, json_encode(['tips' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $file;
}

function read_tip_store(): array
{
    $file = ensure_tip_store();
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return ['tips' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['tips']) || !is_array($decoded['tips'])) {
        return ['tips' => []];
    }

    return $decoded;
}

function write_tip_store(array $store): void
{
    $file = ensure_tip_store();
    file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function generate_id(): string
{
    return bin2hex(random_bytes(16));
}

function now_iso(): string
{
    return gmdate('c');
}

function add_tip_record(array $tip): array
{
    $store = read_tip_store();
    $store['tips'][] = $tip;
    write_tip_store($store);
    return $tip;
}

function update_tip_record(callable $matcher, array $updates): ?array
{
    $store = read_tip_store();

    foreach ($store['tips'] as $index => $tip) {
        if (!$matcher($tip)) {
            continue;
        }

        $store['tips'][$index] = array_merge($tip, $updates, ['updatedAt' => now_iso()]);
        write_tip_store($store);
        return $store['tips'][$index];
    }

    return null;
}

function find_tip_record(callable $matcher): ?array
{
    $store = read_tip_store();
    foreach ($store['tips'] as $tip) {
        if ($matcher($tip)) {
            return $tip;
        }
    }

    return null;
}

function is_valid_username(string $username): bool
{
    return preg_match('/^[a-zA-Z0-9_-]{3,24}$/', $username) === 1;
}

function get_header_value(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return (string)($_SERVER[$serverKey] ?? '');
}

function require_admin_token(): void
{
    $token = trim(get_header_value('x-admin-token'));
    if ($token === '' && isset($_GET['token']) && is_string($_GET['token'])) {
        $token = trim($_GET['token']);
    }

    $expected = env_value('ADMIN_DASHBOARD_TOKEN', 'dev-admin-token');

    if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
        json_response(['error' => 'Unauthorized'], 401);
    }
}

function parse_csv_env(string $key): array
{
    $raw = env_value($key, '');
    if ($raw === '') {
        return [];
    }

    $parts = array_map('trim', explode(',', $raw));
    return array_values(array_filter($parts, static fn($value) => $value !== ''));
}

function format_money(int $amountCents, string $currency): string
{
    $value = number_format($amountCents / 100, 2);
    if (strtoupper($currency) === 'USD') {
        return '$' . $value;
    }

    return $value . ' ' . strtoupper($currency);
}

function stripe_request(string $method, string $path, array $params = []): array
{
    $secretKey = env_value('STRIPE_SECRET_KEY', '');
    if ($secretKey === '' || str_contains($secretKey, '...')) {
        return ['ok' => false, 'status' => 500, 'error' => 'Stripe is not configured'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 500, 'error' => 'PHP curl extension is required for Stripe API calls'];
    }

    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $method = strtoupper($method);

    if ($method === 'GET' && !empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $secretKey
    ];

    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method
    ];

    if ($method !== 'GET') {
        $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($params);
    }

    curl_setopt_array($ch, $curlOptions);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        return ['ok' => false, 'status' => 500, 'error' => $curlError !== '' ? $curlError : 'Stripe request failed'];
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => 500, 'error' => 'Invalid response from Stripe'];
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = $decoded['error']['message'] ?? 'Stripe API request failed';
        return ['ok' => false, 'status' => $statusCode, 'error' => $message, 'data' => $decoded];
    }

    return ['ok' => true, 'status' => $statusCode, 'data' => $decoded];
}

function stripe_verify_signature(string $payload, string $signatureHeader, string $secret): bool
{
    if ($secret === '') {
        return true;
    }

    if ($signatureHeader === '') {
        return false;
    }

    $pairs = explode(',', $signatureHeader);
    $timestamp = '';
    $v1Signatures = [];

    foreach ($pairs as $pair) {
        $parts = explode('=', trim($pair), 2);
        if (count($parts) !== 2) {
            continue;
        }

        if ($parts[0] === 't') {
            $timestamp = $parts[1];
        }

        if ($parts[0] === 'v1') {
            $v1Signatures[] = $parts[1];
        }
    }

    if ($timestamp === '' || empty($v1Signatures) || !ctype_digit($timestamp)) {
        return false;
    }

    $toleranceSeconds = 300;
    if (abs(time() - (int)$timestamp) > $toleranceSeconds) {
        return false;
    }

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);

    foreach ($v1Signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}

function fetch_tip_tiers(): array
{
    $productIds = parse_csv_env('STRIPE_TIER_PRODUCT_IDS');
    $priceIds = parse_csv_env('STRIPE_TIER_PRICE_IDS');

    $rawPrices = [];

    if (!empty($productIds)) {
        foreach ($productIds as $productId) {
            $response = stripe_request('GET', 'prices', [
                'active' => 'true',
                'limit' => '100',
                'type' => 'one_time',
                'product' => $productId,
                'expand[0]' => 'data.product'
            ]);

            if ($response['ok'] && isset($response['data']['data']) && is_array($response['data']['data'])) {
                $rawPrices = array_merge($rawPrices, $response['data']['data']);
            }
        }
    }

    if (!empty($priceIds)) {
        foreach ($priceIds as $priceId) {
            $response = stripe_request('GET', 'prices/' . rawurlencode($priceId), [
                'expand[0]' => 'product'
            ]);

            if ($response['ok'] && isset($response['data']) && is_array($response['data'])) {
                $rawPrices[] = $response['data'];
            }
        }
    }

    if (empty($productIds) && empty($priceIds)) {
        $response = stripe_request('GET', 'prices', [
            'active' => 'true',
            'limit' => '20',
            'type' => 'one_time',
            'expand[0]' => 'data.product'
        ]);

        if ($response['ok'] && isset($response['data']['data']) && is_array($response['data']['data'])) {
            $rawPrices = $response['data']['data'];
        }
    }

    $seen = [];
    $tiers = [];

    foreach ($rawPrices as $price) {
        if (!is_array($price)) {
            continue;
        }

        $priceId = (string)($price['id'] ?? '');
        if ($priceId === '' || isset($seen[$priceId])) {
            continue;
        }

        $seen[$priceId] = true;

        $amount = (int)($price['unit_amount'] ?? 0);
        $currency = strtoupper((string)($price['currency'] ?? 'USD'));
        $active = (bool)($price['active'] ?? false);

        if (!$active || $amount <= 0) {
            continue;
        }

        $productName = 'Tip Tier';
        if (isset($price['product']) && is_array($price['product'])) {
            $productName = (string)($price['product']['name'] ?? $productName);
        }

        if (isset($price['nickname']) && is_string($price['nickname']) && trim($price['nickname']) !== '') {
            $productName = trim($price['nickname']);
        }

        $tiers[] = [
            'id' => $priceId,
            'productName' => $productName,
            'currency' => $currency,
            'amountCents' => $amount,
            'label' => $productName . ' - ' . format_money($amount, $currency)
        ];
    }

    usort($tiers, static function (array $a, array $b): int {
        return $a['amountCents'] <=> $b['amountCents'];
    });

    return $tiers;
}
