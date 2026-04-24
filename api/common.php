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
        'PAYMENT_PROCESSOR' => 'stripe',
        'STRIPE_SECRET_KEY' => '',
        'STRIPE_PUBLISHABLE_KEY' => '',
        'STRIPE_WEBHOOK_SECRET' => '',
        'BTCPAY_SERVER_URL' => '',
        'BTCPAY_API_KEY' => '',
        'BTCPAY_STORE_ID' => '',
        'BTCPAY_WEBHOOK_SECRET' => '',
        'COINBASE_COMMERCE_API_KEY' => '',
        'COINBASE_COMMERCE_WEBHOOK_SECRET' => '',
        'COINBASE_TIP_AMOUNTS' => '5,10,20',
        'COINBASE_CURRENCY' => 'GBP',
        'COINBASE_SUPPORTED_COINS' => 'BTC,ETH,LTC,BCH,DOGE,USDC,USDT,XRP',
        'CRYPTO_RECEIVE_ADDRESSES_JSON' => '{}',
        'COINBASE_DESTINATION_ADDRESSES_JSON' => '{}',
        'CRYPTO_DERIVATION_ENABLED' => '0',
        'CRYPTO_DERIVATION_URL' => 'http://127.0.0.1:8787/api/derive-addresses',
        'CRYPTO_DERIVATION_AUTH_HEADER' => 'x-webgames-wallet-token',
        'CRYPTO_DERIVATION_AUTH_TOKEN' => '',
        'WALLET_SERVICE_PORT' => '8787',
        'WALLET_BASE_ADDRESSES_JSON' => '{}',
        'WALLET_TAGGED_COINS' => 'XRP',
        'WALLET_DERIVATION_SECRET' => '',
        'CRYPTO_AUTO_VERIFY_ENABLED' => '0',
        'CRYPTO_AUTO_VERIFY_PROVIDER_URL' => 'http://127.0.0.1:8787/api/verify-tx',
        'CRYPTO_AUTO_VERIFY_AUTH_HEADER' => 'x-webgames-verify-token',
        'CRYPTO_AUTO_VERIFY_AUTH_TOKEN' => '',
        'CRYPTO_AUTO_VERIFY_MIN_CONFIRMATIONS' => '1',
        'WALLET_APP_INTERNAL_BASE_URL' => 'http://127.0.0.1',
        'CRYPTO_ASSET' => 'USDC',
        'CRYPTO_RECEIVE_ADDRESS' => '',
        'COINBASE_DESTINATION_ACCOUNT' => '',
        'COINBASE_TRANSFER_REQUEST_URL' => '',
        'COINBASE_TRANSFER_AUTH_HEADER' => 'x-coinbase-transfer-token',
        'COINBASE_TRANSFER_AUTH_TOKEN' => '',
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

function ensure_admin_signal_store(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'admin-signals.json';
    if (!is_file($file)) {
        file_put_contents($file, json_encode([
            'lastEventId' => 0,
            'latestEvent' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $file;
}

function read_admin_signal_store(): array
{
    $file = ensure_admin_signal_store();
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return ['lastEventId' => 0, 'latestEvent' => null];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['lastEventId' => 0, 'latestEvent' => null];
    }

    return [
        'lastEventId' => (int)($decoded['lastEventId'] ?? 0),
        'latestEvent' => is_array($decoded['latestEvent'] ?? null) ? $decoded['latestEvent'] : null,
    ];
}

function write_admin_signal_store(array $store): void
{
    $file = ensure_admin_signal_store();
    file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function emit_admin_signal(string $type, array $payload = []): array
{
    $store = read_admin_signal_store();
    $nextId = (int)($store['lastEventId'] ?? 0) + 1;

    $event = [
        'id' => $nextId,
        'type' => $type,
        'payload' => $payload,
        'createdAt' => now_iso(),
    ];

    $store['lastEventId'] = $nextId;
    $store['latestEvent'] = $event;
    write_admin_signal_store($store);

    return $event;
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
        'chainreactor',
        'vaultjump',
        'targetstorm',
        'orbitaldash',
        'signalfall'
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

function active_payment_processor(): string
{
    $processor = strtolower(trim(env_value('PAYMENT_PROCESSOR', 'stripe')));
    if ($processor === 'coinbase') {
        return 'btcpay';
    }

    if (!in_array($processor, ['stripe', 'btcpay'], true)) {
        return 'stripe';
    }

    return $processor;
}

function btcpay_tip_tiers(): array
{
    $amountTokens = parse_csv_env('COINBASE_TIP_AMOUNTS');
    $currency = strtoupper(trim(env_value('COINBASE_CURRENCY', 'GBP')));

    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $currency = 'GBP';
    }

    $tiers = [];
    $seen = [];

    foreach ($amountTokens as $token) {
        if (!is_numeric($token)) {
            continue;
        }

        $amount = (float)$token;
        if ($amount <= 0) {
            continue;
        }

        $amount = round($amount, 2);
        $tierKey = number_format($amount, 2, '.', '');
        if (isset($seen[$tierKey])) {
            continue;
        }

        $seen[$tierKey] = true;
        $amountCents = (int)round($amount * 100);
        $tiers[] = [
            'id' => 'btcpay_' . str_replace('.', '_', $tierKey),
            'provider' => 'btcpay',
            'amount' => $amount,
            'amountCents' => $amountCents,
            'currency' => $currency,
            'productName' => 'Crypto Tip',
            'label' => format_money($amountCents, $currency)
        ];
    }

    usort($tiers, static fn(array $a, array $b): int => ($a['amountCents'] ?? 0) <=> ($b['amountCents'] ?? 0));

    return [
        'tiers' => $tiers,
        'currency' => $currency
    ];
}

function coinbase_tip_tiers(): array
{
    return btcpay_tip_tiers();
}

function crypto_supported_coins(): array
{
    $defaults = ['BTC', 'ETH', 'LTC', 'BCH', 'DOGE', 'USDC', 'USDT', 'XRP'];
    $raw = parse_csv_env('COINBASE_SUPPORTED_COINS');
    $tokens = !empty($raw) ? $raw : $defaults;

    $coins = [];
    foreach ($tokens as $token) {
        $symbol = strtoupper(trim((string)$token));
        if ($symbol === '' || preg_match('/^[A-Z0-9]{2,12}$/', $symbol) !== 1) {
            continue;
        }
        if (!in_array($symbol, $coins, true)) {
            $coins[] = $symbol;
        }
    }

    if (empty($coins)) {
        return $defaults;
    }

    return $coins;
}

function crypto_receive_addresses(): array
{
    $coins = crypto_supported_coins();
    $legacyAddress = trim(env_value('CRYPTO_RECEIVE_ADDRESS', ''));
    $walletBaseRawJson = trim(env_value('WALLET_BASE_ADDRESSES_JSON', '{}'));
    $walletBaseDecoded = json_decode($walletBaseRawJson, true);
    $walletBaseByCoin = is_array($walletBaseDecoded) ? $walletBaseDecoded : [];

    $rawJson = trim(env_value('CRYPTO_RECEIVE_ADDRESSES_JSON', '{}'));
    $decoded = json_decode($rawJson, true);
    $byCoin = is_array($decoded) ? $decoded : [];

    $addresses = [];
    foreach ($coins as $coin) {
        $value = '';
        if (isset($byCoin[$coin]) && is_string($byCoin[$coin])) {
            $value = trim($byCoin[$coin]);
        }

        if ($value === '' && isset($byCoin[strtolower($coin)]) && is_string($byCoin[strtolower($coin)])) {
            $value = trim($byCoin[strtolower($coin)]);
        }

        // Local wallet-service base addresses are the primary fallback on launch.
        if ($value === '' && isset($walletBaseByCoin[$coin]) && is_string($walletBaseByCoin[$coin])) {
            $value = trim($walletBaseByCoin[$coin]);
        }

        if ($value === '' && isset($walletBaseByCoin[strtolower($coin)]) && is_string($walletBaseByCoin[strtolower($coin)])) {
            $value = trim($walletBaseByCoin[strtolower($coin)]);
        }

        if ($value === '' && $legacyAddress !== '') {
            $value = $legacyAddress;
        }

        $addresses[$coin] = $value;
    }

    return $addresses;
}

function crypto_coinbase_destinations(): array
{
    $coins = crypto_supported_coins();

    $rawJson = trim(env_value('COINBASE_DESTINATION_ADDRESSES_JSON', '{}'));
    $decoded = json_decode($rawJson, true);
    $byCoin = is_array($decoded) ? $decoded : [];

    // Legacy fallback: single destination account
    $legacyDestination = trim(env_value('COINBASE_DESTINATION_ACCOUNT', ''));

    $destinations = [];
    foreach ($coins as $coin) {
        $value = '';
        if (isset($byCoin[$coin]) && is_string($byCoin[$coin])) {
            $value = trim($byCoin[$coin]);
        }
        if ($value === '' && isset($byCoin[strtolower($coin)]) && is_string($byCoin[strtolower($coin)])) {
            $value = trim($byCoin[strtolower($coin)]);
        }
        if ($value === '' && $legacyDestination !== '') {
            $value = $legacyDestination;
        }
        $destinations[$coin] = $value;
    }

    return $destinations;
}

function crypto_address_derivation_enabled(): bool
{
    $raw = strtolower(trim(env_value('CRYPTO_DERIVATION_ENABLED', '0')));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function derive_crypto_receive_addresses(string $tipId, string $username, array $coins, int $amountCents, string $currency): array
{
    if (!crypto_address_derivation_enabled()) {
        return [
            'ok' => false,
            'addresses' => [],
            'meta' => [],
            'reference' => '',
            'error' => 'Address derivation is disabled'
        ];
    }

    $url = trim(env_value('CRYPTO_DERIVATION_URL', ''));
    if ($url === '') {
        return [
            'ok' => false,
            'addresses' => [],
            'meta' => [],
            'reference' => '',
            'error' => 'CRYPTO_DERIVATION_URL is not configured'
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'addresses' => [],
            'meta' => [],
            'reference' => '',
            'error' => 'PHP curl extension is required for address derivation'
        ];
    }

    $sanitizedCoins = [];
    foreach ($coins as $coin) {
        $symbol = strtoupper(trim((string)$coin));
        if ($symbol === '' || preg_match('/^[A-Z0-9]{2,12}$/', $symbol) !== 1) {
            continue;
        }
        if (!in_array($symbol, $sanitizedCoins, true)) {
            $sanitizedCoins[] = $symbol;
        }
    }

    if (empty($sanitizedCoins)) {
        return [
            'ok' => false,
            'addresses' => [],
            'meta' => [],
            'reference' => '',
            'error' => 'No valid coins provided for derivation'
        ];
    }

    $payload = [
        'tipId' => $tipId,
        'username' => $username,
        'coins' => $sanitizedCoins,
        'amountCents' => $amountCents,
        'currency' => strtoupper($currency),
        'requestedAt' => now_iso()
    ];

    $authHeader = trim(env_value('CRYPTO_DERIVATION_AUTH_HEADER', 'x-webgames-wallet-token'));
    if ($authHeader === '') {
        $authHeader = 'x-webgames-wallet-token';
    }
    $authToken = trim(env_value('CRYPTO_DERIVATION_AUTH_TOKEN', ''));

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: webgames-crypto-derivation/1.0'
    ];
    if ($authToken !== '') {
        $headers[] = $authHeader . ': ' . $authToken;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 12
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $statusCode < 200 || $statusCode >= 300) {
        $details = [];
        if ($statusCode > 0) {
            $details[] = 'status=' . $statusCode;
        }
        if ($curlError !== '') {
            $details[] = 'curl=' . $curlError;
        }
        if (is_string($body) && trim($body) !== '') {
            $snippet = trim($body);
            if (strlen($snippet) > 220) {
                $snippet = substr($snippet, 0, 220) . '...';
            }
            $details[] = 'body=' . $snippet;
        }

        $message = 'Derivation service returned non-2xx status';
        if (!empty($details)) {
            $message .= ' (' . implode('; ', $details) . ')';
        }

        return [
            'ok' => false,
            'addresses' => [],
            'meta' => [],
            'reference' => '',
            'error' => $message
        ];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'addresses' => [],
            'meta' => [],
            'reference' => '',
            'error' => 'Derivation service returned invalid JSON'
        ];
    }

    $rawAddresses = $decoded['addresses'] ?? null;
    if (!is_array($rawAddresses)) {
        return [
            'ok' => false,
            'addresses' => [],
            'meta' => [],
            'reference' => '',
            'error' => 'Derivation response missing addresses object'
        ];
    }

    $rawMeta = is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];

    $addresses = [];
    $meta = [];
    foreach ($sanitizedCoins as $coin) {
        $value = '';
        if (isset($rawAddresses[$coin]) && is_string($rawAddresses[$coin])) {
            $value = trim($rawAddresses[$coin]);
        } elseif (isset($rawAddresses[strtolower($coin)]) && is_string($rawAddresses[strtolower($coin)])) {
            $value = trim($rawAddresses[strtolower($coin)]);
        }

        if ($value !== '') {
            $addresses[$coin] = $value;
        }

        if (isset($rawMeta[$coin]) && is_array($rawMeta[$coin])) {
            $meta[$coin] = $rawMeta[$coin];
        } elseif (isset($rawMeta[strtolower($coin)]) && is_array($rawMeta[strtolower($coin)])) {
            $meta[$coin] = $rawMeta[strtolower($coin)];
        }
    }

    if (empty($addresses)) {
        return [
            'ok' => false,
            'addresses' => [],
            'meta' => [],
            'reference' => '',
            'error' => 'Derivation response did not contain usable addresses'
        ];
    }

    return [
        'ok' => true,
        'addresses' => $addresses,
        'meta' => $meta,
        'reference' => trim((string)($decoded['reference'] ?? '')),
        'error' => ''
    ];
}

function reset_stripe_configuration(): void
{
    write_env_values([
        'STRIPE_SECRET_KEY' => '',
        'STRIPE_PUBLISHABLE_KEY' => '',
        'STRIPE_WEBHOOK_SECRET' => '',
        'STRIPE_TIER_PRODUCT_IDS' => '',
        'STRIPE_TIER_PRICE_IDS' => ''
    ]);

    write_stripe_checkout_store(stripe_checkout_defaults());

    $runtimeConfig = read_runtime_config_store();
    if (!isset($runtimeConfig['platform']) || !is_array($runtimeConfig['platform'])) {
        $runtimeConfig['platform'] = [];
    }

    $runtimeConfig['platform']['STRIPE_TIER_PRODUCT_IDS'] = '';
    $runtimeConfig['platform']['STRIPE_TIER_PRICE_IDS'] = '';
    write_runtime_config_store($runtimeConfig);
}

function format_money(int $amountCents, string $currency): string
{
    $value = number_format($amountCents / 100, 2);
    if (strtoupper($currency) === 'USD') {
        return '$' . $value;
    }

    if (strtoupper($currency) === 'GBP') {
        return '£' . $value;
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

function coinbase_request(string $method, string $path, array $payload = []): array
{
    $apiKey = trim(env_value('COINBASE_COMMERCE_API_KEY', ''));
    if ($apiKey === '' || str_contains($apiKey, '...')) {
        return ['ok' => false, 'status' => 500, 'error' => 'Coinbase Commerce is not configured'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 500, 'error' => 'PHP curl extension is required for Coinbase API calls'];
    }

    $url = 'https://api.commerce.coinbase.com/' . ltrim($path, '/');
    $method = strtoupper($method);

    if ($method === 'GET' && !empty($payload)) {
        $url .= '?' . http_build_query($payload);
    }

    $ch = curl_init($url);
    $headers = [
        'X-CC-Api-Key: ' . $apiKey,
        'X-CC-Version: 2018-03-22',
        'Accept: application/json'
    ];

    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method
    ];

    if ($method !== 'GET') {
        $headers[] = 'Content-Type: application/json';
        $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        $curlOptions[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $curlOptions);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        return ['ok' => false, 'status' => 500, 'error' => $curlError !== '' ? $curlError : 'Coinbase request failed'];
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => 500, 'error' => 'Invalid response from Coinbase'];
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = (string)($decoded['error']['message'] ?? $decoded['error']['type'] ?? 'Coinbase API request failed');
        return ['ok' => false, 'status' => $statusCode, 'error' => $message, 'data' => $decoded];
    }

    $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
    return ['ok' => true, 'status' => $statusCode, 'data' => $data];
}

function stripe_tip_status_from_session(array $session): string
{
    $status = strtolower((string)($session['status'] ?? ''));
    $paymentStatus = strtolower((string)($session['payment_status'] ?? ''));

    if ($paymentStatus === 'paid') {
        return 'paid';
    }

    if ($status === 'expired') {
        return 'expired';
    }

    if (in_array($paymentStatus, ['unpaid', 'no_payment_required'], true)) {
        return 'checkout_pending';
    }

    if ($status === 'complete') {
        return 'completed';
    }

    return 'checkout_pending';
}

function upsert_tip_record_from_stripe_session(array $session): array
{
    $sessionId = trim((string)($session['id'] ?? ''));
    if ($sessionId === '') {
        return [
            'ok' => false,
            'created' => false,
            'updated' => false,
            'status' => 'unknown',
            'error' => 'Missing Stripe session id'
        ];
    }

    $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
    $tipRecordId = trim((string)($metadata['tipRecordId'] ?? ''));
    $username = trim((string)($metadata['username'] ?? 'anonymous'));
    if ($username === '') {
        $username = 'anonymous';
    }

    $status = stripe_tip_status_from_session($session);
    $createdAt = now_iso();
    if (isset($session['created'])) {
        $createdRaw = (string)$session['created'];
        if (ctype_digit($createdRaw)) {
            $createdAt = gmdate('c', (int)$createdRaw);
        }
    }

    $paymentIntentId = trim((string)($session['payment_intent'] ?? ''));
    $customerEmail = trim((string)($session['customer_details']['email'] ?? ''));
    $tierName = trim((string)($metadata['tierName'] ?? 'Tip Tier'));
    if ($tierName === '') {
        $tierName = 'Tip Tier';
    }

    $updates = [
        'username' => $username,
        'processor' => 'stripe',
        'priceId' => (string)($metadata['priceId'] ?? ''),
        'tierName' => $tierName,
        'amountCents' => (int)($session['amount_total'] ?? 0),
        'currency' => strtolower((string)($session['currency'] ?? 'usd')),
        'status' => $status,
        'sessionId' => $sessionId,
        'paymentIntentId' => $paymentIntentId,
        'customerEmail' => $customerEmail
    ];

    if (in_array($status, ['paid', 'completed'], true)) {
        $updates['paidAt'] = $createdAt;
    }

    $matcherByTipId = static fn(array $tip): bool => ($tip['id'] ?? '') === $tipRecordId;
    $matcherBySession = static fn(array $tip): bool => ($tip['sessionId'] ?? '') === $sessionId;
    $matcherByIntent = static fn(array $tip): bool => $paymentIntentId !== '' && ($tip['paymentIntentId'] ?? '') === $paymentIntentId;

    $existing = null;
    if ($tipRecordId !== '') {
        $existing = find_tip_record($matcherByTipId);
    }
    if ($existing === null) {
        $existing = find_tip_record($matcherBySession);
    }
    if ($existing === null && $paymentIntentId !== '') {
        $existing = find_tip_record($matcherByIntent);
    }

    if ($existing !== null) {
        if ($tipRecordId !== '' && ($existing['id'] ?? '') === $tipRecordId) {
            update_tip_record($matcherByTipId, $updates);
        } elseif (($existing['sessionId'] ?? '') === $sessionId) {
            update_tip_record($matcherBySession, $updates);
        } else {
            update_tip_record($matcherByIntent, $updates);
        }

        return [
            'ok' => true,
            'created' => false,
            'updated' => true,
            'status' => $status,
            'sessionId' => $sessionId,
            'tipId' => (string)($existing['id'] ?? '')
        ];
    }

    $newTip = [
        'id' => $tipRecordId !== '' ? $tipRecordId : generate_id(),
        'username' => $username,
        'processor' => 'stripe',
        'priceId' => (string)($metadata['priceId'] ?? ''),
        'tierName' => $tierName,
        'amountCents' => (int)($session['amount_total'] ?? 0),
        'currency' => strtolower((string)($session['currency'] ?? 'usd')),
        'status' => $status,
        'sessionId' => $sessionId,
        'paymentIntentId' => $paymentIntentId,
        'customerEmail' => $customerEmail,
        'createdAt' => $createdAt,
        'updatedAt' => now_iso()
    ];

    if (in_array($status, ['paid', 'completed'], true)) {
        $newTip['paidAt'] = $createdAt;
    }

    add_tip_record($newTip);

    return [
        'ok' => true,
        'created' => true,
        'updated' => false,
        'status' => $status,
        'sessionId' => $sessionId,
        'tipId' => (string)$newTip['id']
    ];
}

function stripe_backfill_checkout_sessions(int $createdGte = 0, int $maxPages = 200): array
{
    if ($maxPages < 1) {
        $maxPages = 1;
    }
    if ($maxPages > 1000) {
        $maxPages = 1000;
    }

    $startingAfter = '';
    $pagesFetched = 0;
    $sessionsFetched = 0;
    $createdCount = 0;
    $updatedCount = 0;
    $paidCount = 0;
    $pendingCount = 0;
    $failedCount = 0;
    $errors = [];
    $reachedPageLimit = false;

    while (true) {
        $params = [
            'limit' => '100'
        ];

        if ($startingAfter !== '') {
            $params['starting_after'] = $startingAfter;
        }

        if ($createdGte > 0) {
            $params['created[gte]'] = (string)$createdGte;
        }

        $response = stripe_request('GET', 'checkout/sessions', $params);
        if (!($response['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string)($response['error'] ?? 'Unable to fetch checkout sessions from Stripe'),
                'status' => (int)($response['status'] ?? 500),
                'pagesFetched' => $pagesFetched,
                'sessionsFetched' => $sessionsFetched,
                'created' => $createdCount,
                'updated' => $updatedCount,
                'paid' => $paidCount,
                'pending' => $pendingCount,
                'failed' => $failedCount,
                'errors' => $errors
            ];
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $sessions = is_array($data['data'] ?? null) ? $data['data'] : [];
        $hasMore = (bool)($data['has_more'] ?? false);
        $pagesFetched++;

        if (empty($sessions)) {
            break;
        }

        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }

            $sessionsFetched++;
            $result = upsert_tip_record_from_stripe_session($session);
            if (!($result['ok'] ?? false)) {
                $errors[] = (string)($result['error'] ?? 'Unknown upsert error');
                continue;
            }

            if (($result['created'] ?? false) === true) {
                $createdCount++;
            }

            if (($result['updated'] ?? false) === true) {
                $updatedCount++;
            }

            $tipStatus = (string)($result['status'] ?? '');
            if (in_array($tipStatus, ['paid', 'completed'], true)) {
                $paidCount++;
            } elseif ($tipStatus === 'expired' || $tipStatus === 'checkout_failed') {
                $failedCount++;
            } else {
                $pendingCount++;
            }
        }

        $lastSession = end($sessions);
        $startingAfter = is_array($lastSession) ? (string)($lastSession['id'] ?? '') : '';

        if (!$hasMore || $startingAfter === '') {
            break;
        }

        if ($pagesFetched >= $maxPages) {
            $reachedPageLimit = true;
            break;
        }
    }

    return [
        'ok' => true,
        'pagesFetched' => $pagesFetched,
        'sessionsFetched' => $sessionsFetched,
        'created' => $createdCount,
        'updated' => $updatedCount,
        'paid' => $paidCount,
        'pending' => $pendingCount,
        'failed' => $failedCount,
        'reachedPageLimit' => $reachedPageLimit,
        'errors' => array_slice($errors, 0, 25)
    ];
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

function create_admin_session(string $adminId, string $username, string $ip, string $role = 'admin'): array
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
        'role'           => $role,
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

    foreach ($store['sessions'] as $session) {
        if (!hash_equals((string)($session['token'] ?? ''), $token)) {
            continue;
        }

        if (strtotime((string)($session['expiresAt'] ?? '0')) < $now) {
            continue;
        }

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
            'PAYMENT_PROCESSOR' => active_payment_processor(),
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
                'spawnInterval' => 44,
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
                'baseTrainInterval' => 88,
                'boostSpawnInterval' => 210,
                'scoreTickInterval' => 22
            ],
            'chainreactor' => [
                'roundSeconds' => 30,
                'coreCount' => 34,
                'baseBlastGrowth' => 2.8,
                'baseBlastLife' => 45
            ],
            'vaultjump' => [
                'gravity' => 0.46,
                'jumpVelocity' => -9.8,
                'baseSpeed' => 3.8,
                'spawnInterval' => 104
            ],
            'targetstorm' => [
                'startingLives' => 5,
                'spawnInterval' => 70,
                'targetLifetime' => 170,
                'baseTargetSpeed' => 0.85
            ],
            'orbitaldash' => [
                'orbitalSpeed' => 0.034,
                'dashSpeed' => 6.1,
                'dashCooldown' => 30,
                'mineSpawnInterval' => 190
            ],
            'signalfall' => [
                'startingLives' => 5,
                'fallSpeed' => 2.5,
                'spawnInterval' => 54,
                'laneCount' => 5
            ],
            'racer' => [
                'startingShield' => 4,
                'baseSpeed' => 3.2,
                'spawnBaseline' => 68,
                'nitroDrain' => 0.65
            ],
            'meteor' => [
                'startingHull' => 120,
                'baseSpeed' => 1.7,
                'spawnBaseline' => 44,
                'shipSpeed' => 5.3
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
