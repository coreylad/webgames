<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/leaderboard-advanced.php';
require_once __DIR__ . '/achievements.php';

// Admin API for analytics, moderation, and platform insights

require_admin_token();

$action = (string)($_GET['action'] ?? '');
$output = [];

switch ($action) {
    case 'payment-processors-config':
        $output = [
            'status' => 'ok',
            'config' => [
                'activeProcessor' => active_payment_processor(),
                'stripe' => [
                    'secretKey' => env_value('STRIPE_SECRET_KEY', ''),
                    'publishableKey' => env_value('STRIPE_PUBLISHABLE_KEY', ''),
                    'webhookSecret' => env_value('STRIPE_WEBHOOK_SECRET', ''),
                    'tierProductIds' => env_value('STRIPE_TIER_PRODUCT_IDS', ''),
                    'tierPriceIds' => env_value('STRIPE_TIER_PRICE_IDS', '')
                ],
                'paypal' => [
                    'environment' => env_value('PAYPAL_ENV', 'sandbox'),
                    'clientId' => env_value('PAYPAL_CLIENT_ID', ''),
                    'clientSecret' => env_value('PAYPAL_CLIENT_SECRET', ''),
                    'webhookId' => env_value('PAYPAL_WEBHOOK_ID', ''),
                    'currency' => env_value('PAYPAL_CURRENCY', 'USD'),
                    'tipAmounts' => env_value('PAYPAL_TIP_AMOUNTS', '5,10,20'),
                    'checkoutUrl' => env_value('PAYPAL_CHECKOUT_URL', '')
                ]
            ]
        ];
        break;

    case 'update-payment-processors-config':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'Method not allowed'], 405);
        }

        $body = read_json_input();
        $activeProcessor = strtolower(trim((string)($body['activeProcessor'] ?? 'stripe')));
        if (!in_array($activeProcessor, ['stripe', 'paypal'], true)) {
            json_response(['error' => 'Active processor must be stripe or paypal'], 400);
        }

        $stripe = is_array($body['stripe'] ?? null) ? $body['stripe'] : [];
        $paypal = is_array($body['paypal'] ?? null) ? $body['paypal'] : [];

        $stripeSecretKey = trim((string)($stripe['secretKey'] ?? env_value('STRIPE_SECRET_KEY', '')));
        $stripePublishableKey = trim((string)($stripe['publishableKey'] ?? env_value('STRIPE_PUBLISHABLE_KEY', '')));
        $stripeWebhookSecret = trim((string)($stripe['webhookSecret'] ?? env_value('STRIPE_WEBHOOK_SECRET', '')));
        $stripeTierProductIds = trim((string)($stripe['tierProductIds'] ?? env_value('STRIPE_TIER_PRODUCT_IDS', '')));
        $stripeTierPriceIds = trim((string)($stripe['tierPriceIds'] ?? env_value('STRIPE_TIER_PRICE_IDS', '')));

        $paypalEnvironment = strtolower(trim((string)($paypal['environment'] ?? env_value('PAYPAL_ENV', 'sandbox'))));
        if (!in_array($paypalEnvironment, ['sandbox', 'live'], true)) {
            json_response(['error' => 'PayPal environment must be sandbox or live'], 400);
        }

        $paypalCurrency = strtoupper(trim((string)($paypal['currency'] ?? env_value('PAYPAL_CURRENCY', 'USD'))));
        if (!preg_match('/^[A-Z]{3}$/', $paypalCurrency)) {
            json_response(['error' => 'PayPal currency must be a 3-letter ISO code'], 400);
        }

        $paypalTipAmounts = trim((string)($paypal['tipAmounts'] ?? env_value('PAYPAL_TIP_AMOUNTS', '5,10,20')));
        if ($paypalTipAmounts !== '') {
            foreach (explode(',', $paypalTipAmounts) as $rawAmount) {
                $value = trim($rawAmount);
                if ($value === '') {
                    continue;
                }

                if (!is_numeric($value) || (float)$value <= 0) {
                    json_response(['error' => 'PayPal tip amounts must be positive numbers separated by commas'], 400);
                }
            }
        }

        $paypalCheckoutUrl = trim((string)($paypal['checkoutUrl'] ?? env_value('PAYPAL_CHECKOUT_URL', '')));
        if ($paypalCheckoutUrl !== '' && filter_var($paypalCheckoutUrl, FILTER_VALIDATE_URL) === false) {
            json_response(['error' => 'PayPal checkout URL must be a valid URL'], 400);
        }

        $paypalClientId = trim((string)($paypal['clientId'] ?? env_value('PAYPAL_CLIENT_ID', '')));
        $paypalClientSecret = trim((string)($paypal['clientSecret'] ?? env_value('PAYPAL_CLIENT_SECRET', '')));
        $paypalWebhookId = trim((string)($paypal['webhookId'] ?? env_value('PAYPAL_WEBHOOK_ID', '')));

        $saved = write_env_values([
            'PAYMENT_PROCESSOR' => $activeProcessor,
            'STRIPE_SECRET_KEY' => $stripeSecretKey,
            'STRIPE_PUBLISHABLE_KEY' => $stripePublishableKey,
            'STRIPE_WEBHOOK_SECRET' => $stripeWebhookSecret,
            'STRIPE_TIER_PRODUCT_IDS' => $stripeTierProductIds,
            'STRIPE_TIER_PRICE_IDS' => $stripeTierPriceIds,
            'PAYPAL_ENV' => $paypalEnvironment,
            'PAYPAL_CLIENT_ID' => $paypalClientId,
            'PAYPAL_CLIENT_SECRET' => $paypalClientSecret,
            'PAYPAL_WEBHOOK_ID' => $paypalWebhookId,
            'PAYPAL_CURRENCY' => $paypalCurrency,
            'PAYPAL_TIP_AMOUNTS' => $paypalTipAmounts,
            'PAYPAL_CHECKOUT_URL' => $paypalCheckoutUrl
        ]);

        if (!$saved) {
            json_response(['error' => 'Unable to update payment processor settings. Ensure .env is writable by the web server user (www-data).'], 500);
        }

        $runtime = read_runtime_config_store();
        if (!isset($runtime['platform']) || !is_array($runtime['platform'])) {
            $runtime['platform'] = [];
        }
        $runtime['platform']['PAYMENT_PROCESSOR'] = $activeProcessor;
        write_runtime_config_store($runtime);

        $output = [
            'status' => 'ok',
            'message' => 'Payment processor settings updated',
            'config' => [
                'activeProcessor' => $activeProcessor,
                'stripe' => [
                    'secretKey' => $stripeSecretKey,
                    'publishableKey' => $stripePublishableKey,
                    'webhookSecret' => $stripeWebhookSecret,
                    'tierProductIds' => $stripeTierProductIds,
                    'tierPriceIds' => $stripeTierPriceIds
                ],
                'paypal' => [
                    'environment' => $paypalEnvironment,
                    'clientId' => $paypalClientId,
                    'clientSecret' => $paypalClientSecret,
                    'webhookId' => $paypalWebhookId,
                    'currency' => $paypalCurrency,
                    'tipAmounts' => $paypalTipAmounts,
                    'checkoutUrl' => $paypalCheckoutUrl
                ]
            ]
        ];
        break;

    case 'stripe-reset-account':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'Method not allowed'], 405);
        }

        reset_stripe_configuration();

        $output = [
            'status' => 'ok',
            'message' => 'Stripe configuration reset. You can now connect a different Stripe account.'
        ];
        break;

    case 'webhook-proxy-config':
        $forwardUrl = trim(env_value('WEBHOOK_FORWARD_URL', ''));
        $forwardAuthHeader = trim(env_value('WEBHOOK_FORWARD_AUTH_HEADER', 'x-webgames-proxy-token'));
        $forwardAuthToken = env_value('WEBHOOK_FORWARD_AUTH_TOKEN', '');

        $output = [
            'status' => 'ok',
            'config' => [
                'enabled' => $forwardUrl !== '',
                'forwardUrl' => $forwardUrl,
                'forwardAuthHeader' => $forwardAuthHeader !== '' ? $forwardAuthHeader : 'x-webgames-proxy-token',
                'forwardAuthToken' => $forwardAuthToken
            ]
        ];
        break;

    case 'update-webhook-proxy-config':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'Method not allowed'], 405);
        }

        $body = read_json_input();
        $enabled = (bool)($body['enabled'] ?? false);
        $forwardUrl = trim((string)($body['forwardUrl'] ?? ''));
        $forwardAuthHeader = trim((string)($body['forwardAuthHeader'] ?? 'x-webgames-proxy-token'));
        $forwardAuthToken = trim((string)($body['forwardAuthToken'] ?? ''));

        if (!$enabled) {
            $forwardUrl = '';
        }

        if ($forwardUrl !== '') {
            $isValidUrl = filter_var($forwardUrl, FILTER_VALIDATE_URL) !== false;
            $urlScheme = strtolower((string)parse_url($forwardUrl, PHP_URL_SCHEME));
            if (!$isValidUrl || !in_array($urlScheme, ['http', 'https'], true)) {
                json_response(['error' => 'Forward URL must be a valid http or https URL'], 400);
            }
        }

        if ($forwardAuthHeader !== '' && preg_match('/^[A-Za-z0-9-]{1,64}$/', $forwardAuthHeader) !== 1) {
            json_response(['error' => 'Forward auth header must use only letters, numbers, and dashes'], 400);
        }

        if ($forwardAuthHeader === '') {
            $forwardAuthHeader = 'x-webgames-proxy-token';
        }

        if (strlen($forwardAuthToken) > 512) {
            json_response(['error' => 'Forward auth token is too long'], 400);
        }

        $saved = write_env_values([
            'WEBHOOK_FORWARD_URL' => $forwardUrl,
            'WEBHOOK_FORWARD_AUTH_HEADER' => $forwardAuthHeader,
            'WEBHOOK_FORWARD_AUTH_TOKEN' => $forwardAuthToken
        ]);

        if (!$saved) {
            json_response(['error' => 'Unable to update .env settings'], 500);
        }

        $output = [
            'status' => 'ok',
            'message' => 'Webhook proxy settings updated',
            'config' => [
                'enabled' => $forwardUrl !== '',
                'forwardUrl' => $forwardUrl,
                'forwardAuthHeader' => $forwardAuthHeader,
                'forwardAuthToken' => $forwardAuthToken
            ]
        ];
        break;

    case 'dashboard':
        // Main dashboard with key metrics
        require_once __DIR__ . '/webhook-advanced.php';
        
        $revenue = get_revenue_analytics('month');
        $webhook = get_webhook_health();
        
        $lb = read_leaderboard_store();
        $totalGames = count($lb['games'] ?? []);
        $totalLeaderboardEntries = 0;
        foreach (($lb['games'] ?? []) as $gameEntries) {
            $totalLeaderboardEntries += is_array($gameEntries) ? count($gameEntries) : 0;
        }
        
        $tips = read_tip_store();
        $paidTips = array_filter($tips['tips'], fn($t) => ($t['status'] ?? '') === 'paid');
        
        $analytics = read_analytics_store();
        $uniquePlayers = count(array_unique(array_map(fn($s) => $s['username'], $analytics['sessions'] ?? [])));
        
        $output = [
            'status' => 'ok',
            'metrics' => [
                'revenue' => $revenue,
                'webhookHealth' => $webhook,
                'leaderboards' => [
                    'gameCount' => $totalGames,
                    'totalEntries' => $totalLeaderboardEntries
                ],
                'monetization' => [
                    'totalTips' => count($paidTips),
                    'totalRevenueCents' => array_sum(array_map(fn($t) => $t['amountCents'] ?? 0, $paidTips))
                ],
                'players' => [
                    'uniquePlayers' => $uniquePlayers,
                    'totalSessions' => count($analytics['sessions'] ?? [])
                ]
            ]
        ];
        break;
    
    case 'game-analytics':
        // Analytics for a specific game
        $game = (string)($_GET['game'] ?? '');
        if (!is_valid_game_slug($game)) {
            json_response(['error' => 'Invalid game'], 400);
        }
        
        $output = [
            'status' => 'ok',
            'gameAnalytics' => get_game_analytics($game)
        ];
        break;
    
    case 'player-stats':
        // Statistics for a specific player
        $username = (string)($_GET['username'] ?? '');
        if (!is_valid_username($username)) {
            json_response(['error' => 'Invalid username'], 400);
        }
        
        $stats = get_player_stats($username);
        $achievements = get_player_achievements($username);
        
        $output = [
            'status' => 'ok',
            'playerStats' => $stats,
            'achievements' => $achievements
        ];
        break;
    
    case 'suspicious-scores':
        // Get flagged suspicious scores
        $dir = dirname(__DIR__) . '/data';
        $file = $dir . '/suspicious-scores.json';
        
        $suspicious = [];
        if (is_file($file)) {
            $store = json_decode(file_get_contents($file), true) ?? ['flags' => []];
            $suspicious = array_filter($store['flags'], fn($f) => !($f['reviewed'] ?? false));
        }
        
        usort($suspicious, fn($a, $b) => $b['anomalyScore'] <=> $a['anomalyScore']);
        
        $output = [
            'status' => 'ok',
            'suspiciousScores' => array_slice($suspicious, 0, 50)
        ];
        break;
    
    case 'moderate-score':
        // Moderate a suspicious score
        $body = read_json_input();
        $scoreId = trim((string)($body['scoreId'] ?? ($_POST['scoreId'] ?? '')));
        $action_type = trim((string)($body['action'] ?? ($_POST['action'] ?? ''))); // approve, reject
        
        if (!in_array($action_type, ['approve', 'reject'])) {
            json_response(['error' => 'Invalid action'], 400);
        }
        
        $dir = dirname(__DIR__) . '/data';
        $file = $dir . '/suspicious-scores.json';
        
        if (!is_file($file)) {
            json_response(['error' => 'No suspicious scores found'], 404);
        }
        
        $store = json_decode(file_get_contents($file), true) ?? ['flags' => []];
        
        $found = false;
        foreach ($store['flags'] as &$flag) {
            if ($flag['id'] === $scoreId) {
                $flag['reviewed'] = true;
                $flag['action'] = $action_type;
                $flag['reviewedAt'] = now_iso();
                $flag['reviewedBy'] = get_header_value('x-admin-username');
                
                if ($action_type === 'reject') {
                    // Remove from leaderboard
                    $lb = read_leaderboard_store();
                    foreach ($lb['games'] as &$gameData) {
                        $gameData['entries'] = array_filter(
                            $gameData['entries'],
                            fn($e) => !($e['username'] === $flag['username'] && $e['score'] === $flag['score'])
                        );
                    }
                    write_leaderboard_store($lb);
                }
                
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            json_response(['error' => 'Score not found'], 404);
        }
        
        file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        $output = [
            'status' => 'ok',
            'message' => 'Score moderated',
            'action' => $action_type
        ];
        break;

    case 'runtime-config':
        $output = [
            'status' => 'ok',
            'config' => read_runtime_config_store()
        ];
        break;

    case 'stripe-one-time-config':
        $output = [
            'status' => 'ok',
            'config' => read_stripe_checkout_store()['oneTimeCheckout']
        ];
        break;

    case 'stripe-create-one-time-product':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'Method not allowed'], 405);
        }

        $body = read_json_input();
        $name = trim((string)($body['name'] ?? 'Example Product'));
        $currency = strtolower(trim((string)($body['currency'] ?? 'usd')));
        $amountCents = (int)($body['amountCents'] ?? 2000);

        if ($name === '') {
            json_response(['error' => 'Product name is required'], 400);
        }

        if (!preg_match('/^[a-z]{3}$/', $currency)) {
            json_response(['error' => 'Currency must be a 3-letter ISO code'], 400);
        }

        if ($amountCents < 50 || $amountCents > 100000000) {
            json_response(['error' => 'Amount must be between 50 and 100000000 cents'], 400);
        }

        $response = stripe_request('POST', 'products', [
            'name' => $name,
            'default_price_data[currency]' => $currency,
            'default_price_data[unit_amount]' => (string)$amountCents,
            'metadata[integration]' => 'one_time_checkout'
        ]);

        if (!($response['ok'] ?? false)) {
            json_response(['error' => (string)($response['error'] ?? 'Unable to create product in Stripe')], (int)($response['status'] ?? 500));
        }

        $product = $response['data'];
        $updated = update_one_time_checkout_store([
            'productId' => (string)($product['id'] ?? ''),
            'priceId' => (string)($product['default_price'] ?? ''),
            'productName' => $name,
            'currency' => $currency,
            'amountCents' => $amountCents
        ]);

        $output = [
            'status' => 'ok',
            'productId' => (string)($product['id'] ?? ''),
            'priceId' => (string)($product['default_price'] ?? ''),
            'config' => $updated
        ];
        break;

    case 'stripe-create-one-time-checkout-session':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'Method not allowed'], 405);
        }

        $store = read_stripe_checkout_store();
        $checkoutConfig = $store['oneTimeCheckout'];
        $priceId = trim((string)($checkoutConfig['priceId'] ?? ''));

        $body = read_json_input();
        if (isset($body['priceId']) && is_string($body['priceId']) && trim($body['priceId']) !== '') {
            $priceId = trim($body['priceId']);
        }

        if ($priceId === '') {
            json_response(['error' => 'No Stripe price configured. Create product and price first.'], 400);
        }

        $baseUrl = rtrim(env_value('BASE_URL', detect_base_url()), '/');
        $successUrl = $baseUrl . '/public/success.html?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $baseUrl . '/public/tip.html?tip=cancelled';

        $sessionResponse = stripe_request('POST', 'checkout/sessions', [
            'line_items[0][price]' => $priceId,
            'line_items[0][quantity]' => '1',
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata[integration]' => 'one_time_checkout'
        ]);

        if (!($sessionResponse['ok'] ?? false)) {
            json_response(['error' => (string)($sessionResponse['error'] ?? 'Unable to create Checkout Session')], (int)($sessionResponse['status'] ?? 500));
        }

        $session = $sessionResponse['data'];
        $updated = update_one_time_checkout_store([
            'priceId' => $priceId,
            'lastSessionId' => (string)($session['id'] ?? ''),
            'lastCheckoutUrl' => (string)($session['url'] ?? ''),
            'lastCreatedAt' => now_iso()
        ]);

        $output = [
            'status' => 'ok',
            'sessionId' => (string)($session['id'] ?? ''),
            'url' => (string)($session['url'] ?? ''),
            'config' => $updated
        ];
        break;

    case 'update-runtime-config':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'Method not allowed'], 405);
        }

        $body = read_json_input();
        if (!isset($body['config']) || !is_array($body['config'])) {
            json_response(['error' => 'Invalid config payload'], 400);
        }

        $defaults = runtime_config_defaults();
        $input = $body['config'];
        $platform = is_array($input['platform'] ?? null) ? $input['platform'] : [];
        $games = is_array($input['games'] ?? null) ? $input['games'] : [];

        $normalized = [
            'platform' => [
                'BASE_URL' => trim((string)($platform['BASE_URL'] ?? $defaults['platform']['BASE_URL'])),
                'PAYMENT_PROCESSOR' => in_array(strtolower(trim((string)($platform['PAYMENT_PROCESSOR'] ?? $defaults['platform']['PAYMENT_PROCESSOR']))), ['stripe', 'paypal'], true)
                    ? strtolower(trim((string)($platform['PAYMENT_PROCESSOR'] ?? $defaults['platform']['PAYMENT_PROCESSOR'])))
                    : 'stripe',
                'STRIPE_TIER_PRODUCT_IDS' => trim((string)($platform['STRIPE_TIER_PRODUCT_IDS'] ?? $defaults['platform']['STRIPE_TIER_PRODUCT_IDS'])),
                'STRIPE_TIER_PRICE_IDS' => trim((string)($platform['STRIPE_TIER_PRICE_IDS'] ?? $defaults['platform']['STRIPE_TIER_PRICE_IDS']))
            ],
            'games' => array_replace_recursive($defaults['games'], $games)
        ];

        if ($normalized['platform']['BASE_URL'] !== '' && filter_var($normalized['platform']['BASE_URL'], FILTER_VALIDATE_URL) === false) {
            json_response(['error' => 'BASE_URL must be a valid URL'], 400);
        }

        write_runtime_config_store($normalized);

        $envSaved = write_env_values([
            'BASE_URL' => $normalized['platform']['BASE_URL'],
            'PAYMENT_PROCESSOR' => $normalized['platform']['PAYMENT_PROCESSOR'],
            'STRIPE_TIER_PRODUCT_IDS' => $normalized['platform']['STRIPE_TIER_PRODUCT_IDS'],
            'STRIPE_TIER_PRICE_IDS' => $normalized['platform']['STRIPE_TIER_PRICE_IDS']
        ]);

        if (!$envSaved) {
            json_response(['error' => 'Config saved but failed to write .env values'], 500);
        }

        $output = [
            'status' => 'ok',
            'message' => 'Runtime config updated',
            'config' => $normalized
        ];
        break;
    
    case 'webhook-health':
        // Get webhook processing health
        require_once __DIR__ . '/webhook-advanced.php';
        
        $events = get_webhook_events('', 100);
        $health = get_webhook_health();
        
        $output = [
            'status' => 'ok',
            'health' => $health,
            'recentEvents' => array_slice($events, 0, 20)
        ];
        break;
    
    case 'achievement-leaderboard':
        // Get top achievement earners
        $output = [
            'status' => 'ok',
            'leaderboard' => get_achievement_leaderboard(100)
        ];
        break;
    
    case 'revenue-detailed':
        // Get detailed revenue breakdown
        $period = (string)($_GET['period'] ?? 'month');
        
        $revenue = get_revenue_analytics($period);
        
        $output = [
            'status' => 'ok',
            'revenue' => $revenue
        ];
        break;
    
    case 'player-ranking':
        // Get player's rank for a game
        $game = (string)($_GET['game'] ?? '');
        $username = (string)($_GET['username'] ?? '');
        
        if (!is_valid_game_slug($game) || !is_valid_username($username)) {
            json_response(['error' => 'Invalid parameters'], 400);
        }
        
        $output = [
            'status' => 'ok',
            'ranking' => get_player_ranking($game, $username)
        ];
        break;
    
    default:
        json_response(['error' => 'Unknown action'], 404);
}

json_response($output);
