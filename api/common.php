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
        'WEBHOOK_FORWARD_URL' => '',
        'WEBHOOK_FORWARD_AUTH_HEADER' => 'x-webgames-proxy-token',
        'WEBHOOK_FORWARD_AUTH_TOKEN' => '',
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

function write_env_values(array $updates): bool
{
    if (empty($updates)) {
        return true;
    }

    $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    $lines = [];

    if (is_file($envPath)) {
        $loaded = file($envPath, FILE_IGNORE_NEW_LINES);
        if ($loaded === false) {
            return false;
        }
        $lines = $loaded;
    }

    $applied = [];
    foreach ($updates as $key => $value) {
        $applied[(string)$key] = false;
    }

    foreach ($lines as $index => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        if (!array_key_exists($key, $updates)) {
            continue;
        }

        $safeValue = str_replace(["\r", "\n"], '', trim((string)$updates[$key]));
        $lines[$index] = $key . '=' . $safeValue;
        $applied[$key] = true;
    }

    foreach ($updates as $key => $value) {
        $key = (string)$key;
        if (($applied[$key] ?? false) === true) {
            continue;
        }

        $safeValue = str_replace(["\r", "\n"], '', trim((string)$value));
        $lines[] = $key . '=' . $safeValue;
    }

    $content = implode(PHP_EOL, $lines);
    if ($content !== '') {
        $content .= PHP_EOL;
    }

    return file_put_contents($envPath, $content) !== false;
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

function leaderboard_game_slugs(): array
{
    return [
        'snake',
        'pong',
        'memory',
        'breakout',
        'dodger',
        'shooter',
        'tictactoe',
        'racer',
        'meteor',
        'skyhopper',
        'gravitywell',
        'pulserush',
        'hexavoid',
        'railrider',
        'chainreactor'
    ];
}

function is_valid_game_slug(string $game): bool
{
    return in_array($game, leaderboard_game_slugs(), true);
}

function ensure_leaderboard_store(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'leaderboards.json';
    if (!is_file($file)) {
        file_put_contents($file, json_encode(['games' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $file;
}

function read_leaderboard_store(): array
{
    $file = ensure_leaderboard_store();
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return ['games' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['games']) || !is_array($decoded['games'])) {
        return ['games' => []];
    }

    return $decoded;
}

function write_leaderboard_store(array $store): void
{
    $file = ensure_leaderboard_store();
    file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function ensure_leaderboard_rate_store(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'leaderboard-rate-limit.json';
    if (!is_file($file)) {
        file_put_contents($file, json_encode(['attempts' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $file;
}

function read_leaderboard_rate_store(): array
{
    $file = ensure_leaderboard_rate_store();
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return ['attempts' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['attempts']) || !is_array($decoded['attempts'])) {
        return ['attempts' => []];
    }

    return $decoded;
}

function write_leaderboard_rate_store(array $store): void
{
    $file = ensure_leaderboard_rate_store();
    file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function client_ip_address(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return $ip !== '' ? $ip : 'unknown';
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

function ensure_admin_store(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'admins.json';
    if (!is_file($file)) {
        file_put_contents($file, json_encode(['admins' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $file;
}

function read_admin_store(): array
{
    $file = ensure_admin_store();
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return ['admins' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['admins']) || !is_array($decoded['admins'])) {
        return ['admins' => []];
    }

    return $decoded;
}

function write_admin_store(array $store): void
{
    $file = ensure_admin_store();
    file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function normalize_admin_username(string $value): string
{
    return strtolower(trim($value));
}

function is_valid_admin_username(string $value): bool
{
    return preg_match('/^[a-z0-9_-]{3,24}$/', normalize_admin_username($value)) === 1;
}

function ensure_bootstrap_admin(): void
{
    $legacyToken = trim(env_value('ADMIN_DASHBOARD_TOKEN', ''));
    if ($legacyToken === '') {
        return;
    }

    $store = read_admin_store();
    foreach ($store['admins'] as $admin) {
        if (($admin['username'] ?? '') === 'owner') {
            return;
        }
    }

    $store['admins'][] = [
        'id' => generate_id(),
        'username' => 'owner',
        'tokenHash' => password_hash($legacyToken, PASSWORD_DEFAULT),
        'createdAt' => now_iso()
    ];
    write_admin_store($store);
}

function verify_admin_token(string $token): bool
{
    $token = trim($token);
    if ($token === '') {
        return false;
    }

    // Active admin sessions are first-class auth tokens for dashboard APIs.
    if (get_admin_session($token) !== null) {
        return true;
    }

    $legacy = trim(env_value('ADMIN_DASHBOARD_TOKEN', ''));
    if ($legacy !== '' && hash_equals($legacy, $token)) {
        return true;
    }

    ensure_bootstrap_admin();
    $store = read_admin_store();
    foreach ($store['admins'] as $admin) {
        $hash = (string)($admin['tokenHash'] ?? '');
        if ($hash !== '' && password_verify($token, $hash)) {
            return true;
        }
    }

    return false;
}

function require_admin_token(): void
{
    $token = trim(get_header_value('x-admin-token'));
    if ($token === '' && isset($_GET['token']) && is_string($_GET['token'])) {
        $token = trim($_GET['token']);
    }

    if (!verify_admin_token($token)) {
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

// ── Admin session store ────────────────────────────────────────────────────

function ensure_admin_sessions_store(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'admin-sessions.json';
    if (!is_file($file)) {
        file_put_contents($file, json_encode(['sessions' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $file;
}

function read_admin_sessions_store(): array
{
    $file = ensure_admin_sessions_store();
    $raw  = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return ['sessions' => []];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['sessions' => []];
}

function write_admin_sessions_store(array $store): void
{
    $file = ensure_admin_sessions_store();
    file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function create_admin_session(string $adminId, string $username, string $ip): array
{
    $store = read_admin_sessions_store();

    // Clean up expired sessions (7-day TTL)
    $cutoff = time() - 604800;
    $store['sessions'] = array_values(array_filter(
        $store['sessions'],
        fn($s) => strtotime((string)($s['createdAt'] ?? '0')) > $cutoff
    ));

    $token = bin2hex(random_bytes(32));

    $session = [
        'id'             => generate_id(),
        'adminId'        => $adminId,
        'username'       => $username,
        'token'          => $token,
        'ip'             => $ip,
        'userAgent'      => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'createdAt'      => now_iso(),
        'lastActivityAt' => now_iso(),
        'expiresAt'      => gmdate('c', time() + 604800),
    ];

    $store['sessions'][] = $session;
    write_admin_sessions_store($store);

    return $session;
}

function get_admin_session(string $token): ?array
{
    if (trim($token) === '') {
        return null;
    }

    $store = read_admin_sessions_store();
    $now   = time();

    foreach ($store['sessions'] as &$session) {
        if (!hash_equals((string)($session['token'] ?? ''), $token)) {
            continue;
        }

        if (strtotime((string)($session['expiresAt'] ?? '0')) < $now) {
            continue;
        }

        $session['lastActivityAt'] = now_iso();
        write_admin_sessions_store($store);

        return $session;
    }

    return null;
}

function destroy_admin_session(string $token): void
{
    $store = read_admin_sessions_store();
    $store['sessions'] = array_values(array_filter(
        $store['sessions'],
        fn($s) => !hash_equals((string)($s['token'] ?? ''), $token)
    ));
    write_admin_sessions_store($store);
}

function get_active_admin_sessions(string $adminId): array
{
    $store = read_admin_sessions_store();
    $now   = time();

    return array_values(array_filter(
        $store['sessions'],
        fn($s) => ($s['adminId'] ?? '') === $adminId &&
                  strtotime((string)($s['expiresAt'] ?? '0')) > $now
    ));
}

function stripe_checkout_defaults(): array
{
    return [
        'oneTimeCheckout' => [
            'productId' => '',
            'priceId' => '',
            'productName' => 'Example Product',
            'currency' => 'usd',
            'amountCents' => 2000,
            'lastSessionId' => '',
            'lastCheckoutUrl' => '',
            'lastCreatedAt' => null,
            'lastCompletedAt' => null,
            'lastCompletedEventId' => '',
            'completedSessions' => []
        ]
    ];
}

function ensure_stripe_checkout_store(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'stripe-checkout.json';
    if (!is_file($file)) {
        file_put_contents($file, json_encode(stripe_checkout_defaults(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $file;
}

function read_stripe_checkout_store(): array
{
    $file = ensure_stripe_checkout_store();
    $raw = file_get_contents($file);
    $defaults = stripe_checkout_defaults();
    if ($raw === false || $raw === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $merged = array_replace_recursive($defaults, $decoded);
    if (!isset($merged['oneTimeCheckout']['completedSessions']) || !is_array($merged['oneTimeCheckout']['completedSessions'])) {
        $merged['oneTimeCheckout']['completedSessions'] = [];
    }

    return $merged;
}

function write_stripe_checkout_store(array $store): void
{
    $file = ensure_stripe_checkout_store();
    file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function update_one_time_checkout_store(array $updates): array
{
    $store = read_stripe_checkout_store();
    $current = $store['oneTimeCheckout'] ?? [];
    $store['oneTimeCheckout'] = array_merge($current, $updates);
    write_stripe_checkout_store($store);
    return $store['oneTimeCheckout'];
}

function add_one_time_checkout_completion(array $entry): array
{
    $store = read_stripe_checkout_store();
    $oneTime = $store['oneTimeCheckout'] ?? stripe_checkout_defaults()['oneTimeCheckout'];
    $history = $oneTime['completedSessions'] ?? [];
    if (!is_array($history)) {
        $history = [];
    }

    $history[] = $entry;
    if (count($history) > 30) {
        $history = array_slice($history, -30);
    }

    $oneTime['completedSessions'] = $history;
    $oneTime['lastCompletedAt'] = (string)($entry['completedAt'] ?? now_iso());
    $oneTime['lastCompletedEventId'] = (string)($entry['eventId'] ?? '');
    $oneTime['lastSessionId'] = (string)($entry['sessionId'] ?? '');
    $store['oneTimeCheckout'] = $oneTime;
    write_stripe_checkout_store($store);

    return $oneTime;
}

function runtime_config_defaults(): array
{
    return [
        'platform' => [
            'BASE_URL' => env_value('BASE_URL', detect_base_url()),
            'STRIPE_TIER_PRODUCT_IDS' => env_value('STRIPE_TIER_PRODUCT_IDS', ''),
            'STRIPE_TIER_PRICE_IDS' => env_value('STRIPE_TIER_PRICE_IDS', '')
        ],
        'games' => [
            'skyhopper' => [
                'gravity' => 0.33,
                'flapVelocity' => -6.5,
                'gateInterval' => 95,
                'starInterval' => 140,
                'baseGateSpeed' => 2.8
            ],
            'gravitywell' => [
                'coreMass' => 2300,
                'thrust' => 0.19,
                'fuelDrain' => 0.12,
                'shardSpawnChance' => 0.025
            ],
            'pulserush' => [
                'spawnInterval' => 36,
                'perfectWindow' => 7,
                'goodWindow' => 16,
                'startingLives' => 5
            ],
            'hexavoid' => [
                'startingLives' => 3,
                'cellSpawnInterval' => 90,
                'botSpawnInterval' => 580,
                'playerSpeed' => 3.2
            ],
            'railrider' => [
                'baseTrainInterval' => 70,
                'boostSpawnInterval' => 190,
                'scoreTickInterval' => 18
            ],
            'chainreactor' => [
                'roundSeconds' => 30,
                'coreCount' => 34,
                'baseBlastGrowth' => 2.8,
                'baseBlastLife' => 45
            ]
        ]
    ];
}

function ensure_runtime_config_store(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'runtime-config.json';
    if (!is_file($file)) {
        file_put_contents($file, json_encode(runtime_config_defaults(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $file;
}

function read_runtime_config_store(): array
{
    $file = ensure_runtime_config_store();
    $raw = file_get_contents($file);
    $defaults = runtime_config_defaults();
    if ($raw === false || $raw === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    if (!isset($decoded['platform']) || !is_array($decoded['platform'])) {
        $decoded['platform'] = [];
    }

    if (!isset($decoded['games']) || !is_array($decoded['games'])) {
        $decoded['games'] = [];
    }

    return [
        'platform' => array_merge($defaults['platform'], $decoded['platform']),
        'games' => array_replace_recursive($defaults['games'], $decoded['games'])
    ];
}

function write_runtime_config_store(array $config): void
{
    $file = ensure_runtime_config_store();
    file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
