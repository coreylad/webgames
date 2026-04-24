<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/leaderboard-advanced.php';
require_once __DIR__ . '/achievements.php';

function forward_coinbase_transfer_request(array $tip): array
{
    $relayUrl = trim(env_value('COINBASE_TRANSFER_REQUEST_URL', ''));
    if ($relayUrl === '') {
        return [
            'attempted' => false,
            'success' => false,
            'status' => null,
            'error' => null
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'attempted' => true,
            'success' => false,
            'status' => 500,
            'error' => 'PHP curl extension is required for transfer relay calls'
        ];
    }

    $payload = [
        'tipId' => (string)($tip['id'] ?? ''),
        'username' => (string)($tip['username'] ?? 'anonymous'),
        'amountCents' => (int)($tip['amountCents'] ?? 0),
        'currency' => strtoupper((string)($tip['currency'] ?? 'USD')),
        'cryptoAsset' => (string)($tip['cryptoAsset'] ?? env_value('CRYPTO_ASSET', 'USDC')),
        'receiveAddress' => (string)($tip['receiveAddress'] ?? env_value('CRYPTO_RECEIVE_ADDRESS', '')),
        'txHash' => (string)($tip['txHash'] ?? ''),
        'coinbaseDestination' => trim(env_value('COINBASE_DESTINATION_ACCOUNT', '')),
        'requestedAt' => now_iso()
    ];

    $authHeader = trim(env_value('COINBASE_TRANSFER_AUTH_HEADER', 'x-coinbase-transfer-token'));
    if ($authHeader === '') {
        $authHeader = 'x-coinbase-transfer-token';
    }
    $authToken = trim(env_value('COINBASE_TRANSFER_AUTH_TOKEN', ''));

    $headers = [
        'Content-Type: application/json',
        'User-Agent: webgames-coinbase-transfer-relay/1.0'
    ];

    if ($authToken !== '') {
        $headers[] = $authHeader . ': ' . $authToken;
    }

    $ch = curl_init($relayUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false) {
        return [
            'attempted' => true,
            'success' => false,
            'status' => 500,
            'error' => $curlError !== '' ? $curlError : 'Transfer relay request failed'
        ];
    }

    $ok = $statusCode >= 200 && $statusCode < 300;
    return [
        'attempted' => true,
        'success' => $ok,
        'status' => $statusCode,
        'error' => $ok ? null : 'Transfer relay returned non-2xx status'
    ];
}

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
                'coinbase' => [
                    'apiKey' => env_value('COINBASE_COMMERCE_API_KEY', ''),
                    'webhookSecret' => env_value('COINBASE_COMMERCE_WEBHOOK_SECRET', ''),
                    'tipAmounts' => env_value('COINBASE_TIP_AMOUNTS', '5,10,20'),
                    'currency' => env_value('COINBASE_CURRENCY', 'GBP'),
                    'supportedCoins' => env_value('COINBASE_SUPPORTED_COINS', 'BTC,ETH,LTC,BCH,DOGE,USDC,USDT,XRP'),
                    'receiveAddressesJson' => env_value('CRYPTO_RECEIVE_ADDRESSES_JSON', '{}'),
                    'receiveAddresses' => crypto_receive_addresses(),
                    'destinationAddressesJson' => env_value('COINBASE_DESTINATION_ADDRESSES_JSON', '{}'),
                    'cryptoAsset' => env_value('CRYPTO_ASSET', 'USDC'),
                    'receiveAddress' => env_value('CRYPTO_RECEIVE_ADDRESS', ''),
                    'destinationAccount' => env_value('COINBASE_DESTINATION_ACCOUNT', ''),
                    'destinationAddresses' => crypto_coinbase_destinations(),
                    'transferRequestUrl' => env_value('COINBASE_TRANSFER_REQUEST_URL', ''),
                    'transferAuthHeader' => env_value('COINBASE_TRANSFER_AUTH_HEADER', 'x-coinbase-transfer-token'),
                    'transferAuthToken' => env_value('COINBASE_TRANSFER_AUTH_TOKEN', ''),
                    'addressDerivationEnabled' => crypto_address_derivation_enabled(),
                    'addressDerivationUrl' => env_value('CRYPTO_DERIVATION_URL', ''),
                    'addressDerivationAuthHeader' => env_value('CRYPTO_DERIVATION_AUTH_HEADER', 'x-webgames-wallet-token'),
                    'addressDerivationAuthToken' => env_value('CRYPTO_DERIVATION_AUTH_TOKEN', ''),
                    'walletServicePort' => env_value('WALLET_SERVICE_PORT', '8787'),
                    'walletBaseAddressesJson' => env_value('WALLET_BASE_ADDRESSES_JSON', '{}'),
                    'walletTaggedCoins' => env_value('WALLET_TAGGED_COINS', 'XRP'),
                    'walletDerivationSecret' => env_value('WALLET_DERIVATION_SECRET', ''),
                    'autoVerifyEnabled' => in_array(strtolower(trim(env_value('CRYPTO_AUTO_VERIFY_ENABLED', '0'))), ['1', 'true', 'yes', 'on'], true),
                    'autoVerifyProviderUrl' => env_value('CRYPTO_AUTO_VERIFY_PROVIDER_URL', ''),
                    'autoVerifyAuthHeader' => env_value('CRYPTO_AUTO_VERIFY_AUTH_HEADER', 'x-webgames-verify-token'),
                    'autoVerifyAuthToken' => env_value('CRYPTO_AUTO_VERIFY_AUTH_TOKEN', ''),
                    'autoVerifyMinConfirmations' => env_value('CRYPTO_AUTO_VERIFY_MIN_CONFIRMATIONS', '1'),
                    'walletAppInternalBaseUrl' => env_value('WALLET_APP_INTERNAL_BASE_URL', 'http://127.0.0.1')
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
        if (!in_array($activeProcessor, ['stripe', 'coinbase'], true)) {
            json_response(['error' => 'Active processor must be stripe or coinbase'], 400);
        }

        $stripe = is_array($body['stripe'] ?? null) ? $body['stripe'] : [];
        $coinbase = is_array($body['coinbase'] ?? null) ? $body['coinbase'] : [];

        $stripeSecretKey = trim((string)($stripe['secretKey'] ?? env_value('STRIPE_SECRET_KEY', '')));
        $stripePublishableKey = trim((string)($stripe['publishableKey'] ?? env_value('STRIPE_PUBLISHABLE_KEY', '')));
        $stripeWebhookSecret = trim((string)($stripe['webhookSecret'] ?? env_value('STRIPE_WEBHOOK_SECRET', '')));
        $stripeTierProductIds = trim((string)($stripe['tierProductIds'] ?? env_value('STRIPE_TIER_PRODUCT_IDS', '')));
        $stripeTierPriceIds = trim((string)($stripe['tierPriceIds'] ?? env_value('STRIPE_TIER_PRICE_IDS', '')));

        $coinbaseCurrency = strtoupper(trim((string)($coinbase['currency'] ?? env_value('COINBASE_CURRENCY', 'GBP'))));
        if (!preg_match('/^[A-Z]{3}$/', $coinbaseCurrency)) {
            json_response(['error' => 'Coinbase local currency must be a 3-letter ISO code'], 400);
        }

        $coinbaseTipAmounts = trim((string)($coinbase['tipAmounts'] ?? env_value('COINBASE_TIP_AMOUNTS', '5,10,20')));
        if ($coinbaseTipAmounts !== '') {
            foreach (explode(',', $coinbaseTipAmounts) as $rawAmount) {
                $value = trim($rawAmount);
                if ($value === '') {
                    continue;
                }

                if (!is_numeric($value) || (float)$value <= 0) {
                    json_response(['error' => 'Coinbase tip amounts must be positive numbers separated by commas'], 400);
                }
            }
        }

        $cryptoAsset = strtoupper(trim((string)($coinbase['cryptoAsset'] ?? env_value('CRYPTO_ASSET', 'USDC'))));
        if (!preg_match('/^[A-Z0-9]{2,12}$/', $cryptoAsset)) {
            json_response(['error' => 'Crypto asset symbol must be 2-12 letters or digits'], 400);
        }

        $cryptoReceiveAddress = trim((string)($coinbase['receiveAddress'] ?? env_value('CRYPTO_RECEIVE_ADDRESS', '')));
        $coinbaseDestinationAccount = trim((string)($coinbase['destinationAccount'] ?? env_value('COINBASE_DESTINATION_ACCOUNT', '')));
        $coinbaseTransferRequestUrl = trim((string)($coinbase['transferRequestUrl'] ?? env_value('COINBASE_TRANSFER_REQUEST_URL', '')));
        if ($coinbaseTransferRequestUrl !== '' && filter_var($coinbaseTransferRequestUrl, FILTER_VALIDATE_URL) === false) {
            json_response(['error' => 'Coinbase transfer request URL must be a valid URL'], 400);
        }

        $coinbaseTransferAuthHeader = trim((string)($coinbase['transferAuthHeader'] ?? env_value('COINBASE_TRANSFER_AUTH_HEADER', 'x-coinbase-transfer-token')));
        if ($coinbaseTransferAuthHeader !== '' && preg_match('/^[A-Za-z0-9-]{1,64}$/', $coinbaseTransferAuthHeader) !== 1) {
            json_response(['error' => 'Coinbase transfer auth header must use only letters, numbers, and dashes'], 400);
        }
        if ($coinbaseTransferAuthHeader === '') {
            $coinbaseTransferAuthHeader = 'x-coinbase-transfer-token';
        }

        $coinbaseTransferAuthToken = trim((string)($coinbase['transferAuthToken'] ?? env_value('COINBASE_TRANSFER_AUTH_TOKEN', '')));
        $coinbaseApiKey = trim((string)($coinbase['apiKey'] ?? env_value('COINBASE_COMMERCE_API_KEY', '')));
        $coinbaseWebhookSecret = trim((string)($coinbase['webhookSecret'] ?? env_value('COINBASE_COMMERCE_WEBHOOK_SECRET', '')));
        $addressDerivationEnabledRaw = strtolower(trim((string)($coinbase['addressDerivationEnabled'] ?? env_value('CRYPTO_DERIVATION_ENABLED', '0'))));
        $addressDerivationEnabled = in_array($addressDerivationEnabledRaw, ['1', 'true', 'yes', 'on'], true);
        $addressDerivationUrl = trim((string)($coinbase['addressDerivationUrl'] ?? env_value('CRYPTO_DERIVATION_URL', '')));
        $addressDerivationAuthHeader = trim((string)($coinbase['addressDerivationAuthHeader'] ?? env_value('CRYPTO_DERIVATION_AUTH_HEADER', 'x-webgames-wallet-token')));
        $addressDerivationAuthToken = trim((string)($coinbase['addressDerivationAuthToken'] ?? env_value('CRYPTO_DERIVATION_AUTH_TOKEN', '')));
        $walletServicePort = trim((string)($coinbase['walletServicePort'] ?? env_value('WALLET_SERVICE_PORT', '8787')));
        $walletBaseAddressesJson = trim((string)($coinbase['walletBaseAddressesJson'] ?? env_value('WALLET_BASE_ADDRESSES_JSON', '{}')));
        $walletTaggedCoins = strtoupper(trim((string)($coinbase['walletTaggedCoins'] ?? env_value('WALLET_TAGGED_COINS', 'XRP'))));
        $walletDerivationSecret = trim((string)($coinbase['walletDerivationSecret'] ?? env_value('WALLET_DERIVATION_SECRET', '')));
        $autoVerifyEnabledRaw = strtolower(trim((string)($coinbase['autoVerifyEnabled'] ?? env_value('CRYPTO_AUTO_VERIFY_ENABLED', '0'))));
        $autoVerifyEnabled = in_array($autoVerifyEnabledRaw, ['1', 'true', 'yes', 'on'], true);
        $autoVerifyProviderUrl = trim((string)($coinbase['autoVerifyProviderUrl'] ?? env_value('CRYPTO_AUTO_VERIFY_PROVIDER_URL', '')));
        $autoVerifyAuthHeader = trim((string)($coinbase['autoVerifyAuthHeader'] ?? env_value('CRYPTO_AUTO_VERIFY_AUTH_HEADER', 'x-webgames-verify-token')));
        $autoVerifyAuthToken = trim((string)($coinbase['autoVerifyAuthToken'] ?? env_value('CRYPTO_AUTO_VERIFY_AUTH_TOKEN', '')));
        $autoVerifyMinConfirmationsRaw = trim((string)($coinbase['autoVerifyMinConfirmations'] ?? env_value('CRYPTO_AUTO_VERIFY_MIN_CONFIRMATIONS', '1')));
        $walletAppInternalBaseUrl = trim((string)($coinbase['walletAppInternalBaseUrl'] ?? env_value('WALLET_APP_INTERNAL_BASE_URL', 'http://127.0.0.1')));
        $coinbaseSupportedCoins = strtoupper(trim((string)($coinbase['supportedCoins'] ?? env_value('COINBASE_SUPPORTED_COINS', 'BTC,ETH,LTC,BCH,DOGE,USDC,USDT,XRP'))));
        $coinbaseReceiveAddressesJson = trim((string)($coinbase['receiveAddressesJson'] ?? env_value('CRYPTO_RECEIVE_ADDRESSES_JSON', '{}')));
        $coinbaseDestinationAddressesJson = trim((string)($coinbase['destinationAddressesJson'] ?? env_value('COINBASE_DESTINATION_ADDRESSES_JSON', '{}')));

        if ($addressDerivationEnabled && ($addressDerivationUrl === '' || filter_var($addressDerivationUrl, FILTER_VALIDATE_URL) === false)) {
            json_response(['error' => 'Address derivation URL must be a valid URL when derivation is enabled'], 400);
        }

        if ($addressDerivationAuthHeader !== '' && preg_match('/^[A-Za-z0-9-]{1,64}$/', $addressDerivationAuthHeader) !== 1) {
            json_response(['error' => 'Address derivation auth header must use only letters, numbers, and dashes'], 400);
        }

        if ($addressDerivationAuthHeader === '') {
            $addressDerivationAuthHeader = 'x-webgames-wallet-token';
        }

        if ($walletServicePort !== '' && preg_match('/^[0-9]{2,5}$/', $walletServicePort) !== 1) {
            json_response(['error' => 'Wallet service port must be a valid numeric port'], 400);
        }

        $autoVerifyMinConfirmations = (int)$autoVerifyMinConfirmationsRaw;
        if ($autoVerifyMinConfirmations < 1) {
            $autoVerifyMinConfirmations = 1;
        }
        if ($autoVerifyMinConfirmations > 1000) {
            $autoVerifyMinConfirmations = 1000;
        }

        if ($autoVerifyEnabled && ($autoVerifyProviderUrl === '' || filter_var($autoVerifyProviderUrl, FILTER_VALIDATE_URL) === false)) {
            json_response(['error' => 'Auto verify provider URL must be a valid URL when auto verification is enabled'], 400);
        }

        if ($autoVerifyAuthHeader !== '' && preg_match('/^[A-Za-z0-9-]{1,64}$/', $autoVerifyAuthHeader) !== 1) {
            json_response(['error' => 'Auto verify auth header must use only letters, numbers, and dashes'], 400);
        }

        if ($autoVerifyAuthHeader === '') {
            $autoVerifyAuthHeader = 'x-webgames-verify-token';
        }

        if ($walletAppInternalBaseUrl !== '' && filter_var($walletAppInternalBaseUrl, FILTER_VALIDATE_URL) === false) {
            json_response(['error' => 'Wallet app internal base URL must be a valid URL'], 400);
        }

        $walletAddressMap = json_decode($walletBaseAddressesJson, true);
        if (!is_array($walletAddressMap)) {
            json_response(['error' => 'Wallet base addresses JSON must be a valid object'], 400);
        }

        $coinTokens = array_filter(array_map('trim', explode(',', $coinbaseSupportedCoins)), static fn(string $t): bool => $t !== '');
        if (empty($coinTokens)) {
            json_response(['error' => 'Supported coins must include at least one symbol'], 400);
        }
        foreach ($coinTokens as $symbol) {
            if (preg_match('/^[A-Z0-9]{2,12}$/', $symbol) !== 1) {
                json_response(['error' => 'Supported coin symbols must be 2-12 uppercase letters or digits'], 400);
            }
        }

        $addressesDecoded = json_decode($coinbaseReceiveAddressesJson, true);
        if (!is_array($addressesDecoded)) {
            json_response(['error' => 'Receive addresses JSON must be a valid object'], 400);
        }

        $destinationsDecoded = json_decode($coinbaseDestinationAddressesJson, true);
        if (!is_array($destinationsDecoded)) {
            json_response(['error' => 'Destination addresses JSON must be a valid object'], 400);
        }

        $saved = write_env_values([
            'PAYMENT_PROCESSOR' => $activeProcessor,
            'STRIPE_SECRET_KEY' => $stripeSecretKey,
            'STRIPE_PUBLISHABLE_KEY' => $stripePublishableKey,
            'STRIPE_WEBHOOK_SECRET' => $stripeWebhookSecret,
            'STRIPE_TIER_PRODUCT_IDS' => $stripeTierProductIds,
            'STRIPE_TIER_PRICE_IDS' => $stripeTierPriceIds,
            'COINBASE_COMMERCE_API_KEY' => $coinbaseApiKey,
            'COINBASE_COMMERCE_WEBHOOK_SECRET' => $coinbaseWebhookSecret,
            'COINBASE_TIP_AMOUNTS' => $coinbaseTipAmounts,
            'COINBASE_CURRENCY' => $coinbaseCurrency,
            'COINBASE_SUPPORTED_COINS' => $coinbaseSupportedCoins,
            'CRYPTO_RECEIVE_ADDRESSES_JSON' => $coinbaseReceiveAddressesJson,
            'COINBASE_DESTINATION_ADDRESSES_JSON' => $coinbaseDestinationAddressesJson,
            'CRYPTO_ASSET' => $cryptoAsset,
            'CRYPTO_RECEIVE_ADDRESS' => $cryptoReceiveAddress,
            'COINBASE_DESTINATION_ACCOUNT' => $coinbaseDestinationAccount,
            'COINBASE_TRANSFER_REQUEST_URL' => $coinbaseTransferRequestUrl,
            'COINBASE_TRANSFER_AUTH_HEADER' => $coinbaseTransferAuthHeader,
            'COINBASE_TRANSFER_AUTH_TOKEN' => $coinbaseTransferAuthToken,
            'CRYPTO_DERIVATION_ENABLED' => $addressDerivationEnabled ? '1' : '0',
            'CRYPTO_DERIVATION_URL' => $addressDerivationUrl,
            'CRYPTO_DERIVATION_AUTH_HEADER' => $addressDerivationAuthHeader,
            'CRYPTO_DERIVATION_AUTH_TOKEN' => $addressDerivationAuthToken,
            'WALLET_SERVICE_PORT' => $walletServicePort,
            'WALLET_BASE_ADDRESSES_JSON' => $walletBaseAddressesJson,
            'WALLET_TAGGED_COINS' => $walletTaggedCoins,
            'WALLET_DERIVATION_SECRET' => $walletDerivationSecret,
            'CRYPTO_AUTO_VERIFY_ENABLED' => $autoVerifyEnabled ? '1' : '0',
            'CRYPTO_AUTO_VERIFY_PROVIDER_URL' => $autoVerifyProviderUrl,
            'CRYPTO_AUTO_VERIFY_AUTH_HEADER' => $autoVerifyAuthHeader,
            'CRYPTO_AUTO_VERIFY_AUTH_TOKEN' => $autoVerifyAuthToken,
            'CRYPTO_AUTO_VERIFY_MIN_CONFIRMATIONS' => (string)$autoVerifyMinConfirmations,
            'WALLET_APP_INTERNAL_BASE_URL' => $walletAppInternalBaseUrl
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
                'coinbase' => [
                    'apiKey' => $coinbaseApiKey,
                    'webhookSecret' => $coinbaseWebhookSecret,
                    'tipAmounts' => $coinbaseTipAmounts,
                    'currency' => $coinbaseCurrency,
                    'supportedCoins' => $coinbaseSupportedCoins,
                    'receiveAddressesJson' => $coinbaseReceiveAddressesJson,
                    'receiveAddresses' => crypto_receive_addresses(),
                    'destinationAddressesJson' => $coinbaseDestinationAddressesJson,
                    'cryptoAsset' => $cryptoAsset,
                    'receiveAddress' => $cryptoReceiveAddress,
                    'destinationAccount' => $coinbaseDestinationAccount,
                    'destinationAddresses' => crypto_coinbase_destinations(),
                    'transferRequestUrl' => $coinbaseTransferRequestUrl,
                    'transferAuthHeader' => $coinbaseTransferAuthHeader,
                    'transferAuthToken' => $coinbaseTransferAuthToken,
                    'addressDerivationEnabled' => $addressDerivationEnabled,
                    'addressDerivationUrl' => $addressDerivationUrl,
                    'addressDerivationAuthHeader' => $addressDerivationAuthHeader,
                    'addressDerivationAuthToken' => $addressDerivationAuthToken,
                    'walletServicePort' => $walletServicePort,
                    'walletBaseAddressesJson' => $walletBaseAddressesJson,
                    'walletTaggedCoins' => $walletTaggedCoins,
                    'walletDerivationSecret' => $walletDerivationSecret,
                    'autoVerifyEnabled' => $autoVerifyEnabled,
                    'autoVerifyProviderUrl' => $autoVerifyProviderUrl,
                    'autoVerifyAuthHeader' => $autoVerifyAuthHeader,
                    'autoVerifyAuthToken' => $autoVerifyAuthToken,
                    'autoVerifyMinConfirmations' => (string)$autoVerifyMinConfirmations,
                    'walletAppInternalBaseUrl' => $walletAppInternalBaseUrl
                ]
            ]
        ];
        break;

    case 'crypto-transfer-queue':
        $store = read_tip_store();
        $tips = array_values(array_filter($store['tips'], static function (array $tip): bool {
            return ($tip['processor'] ?? '') === 'coinbase'
                && in_array((string)($tip['status'] ?? ''), ['awaiting_crypto_payment', 'payment_submitted', 'paid', 'coinbase_transfer_requested'], true);
        }));

        usort($tips, static function (array $a, array $b): int {
            return strtotime((string)($b['createdAt'] ?? '')) <=> strtotime((string)($a['createdAt'] ?? ''));
        });

        $output = [
            'status' => 'ok',
            'tips' => $tips
        ];
        break;

    case 'wallet-overview':
        $coins = crypto_supported_coins();
        $receiveAddresses = crypto_receive_addresses();
        $coinbaseDestinations = crypto_coinbase_destinations();

        $store = read_tip_store();
        $allTips = $store['tips'] ?? [];

        $wallets = [];
        foreach ($coins as $coin) {
            $receiveAddr = (string)($receiveAddresses[$coin] ?? '');
            $destAddr = (string)($coinbaseDestinations[$coin] ?? '');

            // Sum confirmed received tips for this coin
            $confirmedCents = 0;
            $confirmedCount = 0;
            $transferredCents = 0;
            $transferredCount = 0;
            $pendingCents = 0;
            $pendingCount = 0;

            foreach ($allTips as $tip) {
                if (($tip['processor'] ?? '') !== 'coinbase') {
                    continue;
                }
                if (strtoupper((string)($tip['cryptoAsset'] ?? '')) !== $coin) {
                    continue;
                }
                $amtCents = (int)($tip['amountCents'] ?? 0);
                $tipStatus = (string)($tip['status'] ?? '');

                if ($tipStatus === 'paid') {
                    $confirmedCents += $amtCents;
                    $confirmedCount++;
                } elseif ($tipStatus === 'coinbase_transfer_requested') {
                    $transferredCents += $amtCents;
                    $transferredCount++;
                } elseif (in_array($tipStatus, ['awaiting_crypto_payment', 'payment_submitted'], true)) {
                    $pendingCents += $amtCents;
                    $pendingCount++;
                }
            }

            $wallets[] = [
                'coin' => $coin,
                'receiveAddress' => $receiveAddr,
                'coinbaseDestination' => $destAddr,
                'confirmedReceived' => [
                    'amountCents' => $confirmedCents,
                    'count' => $confirmedCount
                ],
                'transferred' => [
                    'amountCents' => $transferredCents,
                    'count' => $transferredCount
                ],
                'pending' => [
                    'amountCents' => $pendingCents,
                    'count' => $pendingCount
                ],
                'hasReceiveAddress' => $receiveAddr !== '',
                'hasDestination' => $destAddr !== ''
            ];
        }

        $output = [
            'status' => 'ok',
            'wallets' => $wallets
        ];
        break;

    case 'withdraw-to-coinbase':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'Method not allowed'], 405);
        }

        $body = read_json_input();
        $withdrawCoin = strtoupper(trim((string)($body['coin'] ?? '')));
        $withdrawNote = trim((string)($body['note'] ?? 'Webgames tip jar withdrawal'));

        if ($withdrawCoin === '') {
            json_response(['error' => 'coin is required'], 400);
        }

        $supportedCoins = crypto_supported_coins();
        if (!in_array($withdrawCoin, $supportedCoins, true)) {
            json_response(['error' => 'Unsupported coin: ' . $withdrawCoin], 400);
        }

        $receiveAddresses = crypto_receive_addresses();
        $coinbaseDestinations = crypto_coinbase_destinations();
        $destAddr = trim((string)($coinbaseDestinations[$withdrawCoin] ?? ''));
        $srcAddr = trim((string)($receiveAddresses[$withdrawCoin] ?? ''));

        if ($destAddr === '') {
            json_response(['error' => 'No Coinbase destination address configured for ' . $withdrawCoin . '. Set it in payment settings.'], 400);
        }

        // Collect confirmed-but-not-yet-transferred tip IDs for this coin
        $store = read_tip_store();
        $eligibleTips = array_values(array_filter($store['tips'], static function (array $tip) use ($withdrawCoin): bool {
            return ($tip['processor'] ?? '') === 'coinbase'
                && strtoupper((string)($tip['cryptoAsset'] ?? '')) === $withdrawCoin
                && ($tip['status'] ?? '') === 'paid';
        }));

        if (empty($eligibleTips)) {
            json_response(['error' => 'No confirmed, untransferred ' . $withdrawCoin . ' tips to withdraw'], 400);
        }

        $totalCents = array_sum(array_map(static fn(array $tip): int => (int)($tip['amountCents'] ?? 0), $eligibleTips));
        $fiatCurrency = strtoupper((string)($eligibleTips[0]['currency'] ?? 'USD'));
        $tipIds = array_map(static fn(array $tip): string => (string)($tip['id'] ?? ''), $eligibleTips);

        // Attempt relay if configured
        $relayUrl = trim(env_value('COINBASE_TRANSFER_REQUEST_URL', ''));
        $relayAttempted = false;
        $relaySuccess = false;
        $relayError = '';

        if ($relayUrl !== '' && function_exists('curl_init')) {
            $authHeader = trim(env_value('COINBASE_TRANSFER_AUTH_HEADER', 'x-coinbase-transfer-token'));
            if ($authHeader === '') {
                $authHeader = 'x-coinbase-transfer-token';
            }
            $authToken = trim(env_value('COINBASE_TRANSFER_AUTH_TOKEN', ''));

            $headers = [
                'Content-Type: application/json',
                'User-Agent: webgames-coinbase-wallet-withdraw/1.0'
            ];
            if ($authToken !== '') {
                $headers[] = $authHeader . ': ' . $authToken;
            }

            $payload = [
                'action' => 'withdraw',
                'coin' => $withdrawCoin,
                'sourceAddress' => $srcAddr,
                'destinationAddress' => $destAddr,
                'amountCents' => $totalCents,
                'fiatCurrency' => $fiatCurrency,
                'tipIds' => $tipIds,
                'note' => $withdrawNote,
                'requestedAt' => now_iso()
            ];

            $ch = curl_init($relayUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 15
            ]);
            $relayBody = curl_exec($ch);
            $relayStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            $relayAttempted = true;
            $relaySuccess = ($relayBody !== false && $relayStatus >= 200 && $relayStatus < 300);
            if (!$relaySuccess) {
                $relayError = $relayBody !== false ? substr($relayBody, 0, 256) : 'curl error';
            }
        }

        $withdrawStatus = $relayAttempted
            ? ($relaySuccess ? 'relay_accepted' : 'relay_failed')
            : 'manual_pending';

        // Mark all eligible tips as transfer_requested
        foreach ($tipIds as $tipId) {
            update_tip_record(
                static fn(array $item): bool => ($item['id'] ?? '') === $tipId,
                [
                    'status' => 'coinbase_transfer_requested',
                    'coinbaseTransferStatus' => $withdrawStatus,
                    'coinbaseTransferRequestedAt' => now_iso(),
                    'coinbaseTransferDestination' => $destAddr,
                    'coinbaseTransferError' => $relayError
                ]
            );
        }

        $output = [
            'status' => 'ok',
            'coin' => $withdrawCoin,
            'destinationAddress' => $destAddr,
            'amountCents' => $totalCents,
            'fiatCurrency' => $fiatCurrency,
            'tipsProcessed' => count($tipIds),
            'withdrawStatus' => $withdrawStatus,
            'relayAttempted' => $relayAttempted,
            'relaySuccess' => $relaySuccess,
            'message' => $relayAttempted
                ? ($relaySuccess ? 'Withdrawal request sent to relay.' : 'Relay failed; tips marked as pending manual transfer.')
                : 'No relay configured. Tips marked as pending manual transfer to Coinbase.'
        ];
        break;
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'Method not allowed'], 405);
        }

        $body = read_json_input();
        $tipId = trim((string)($body['tipId'] ?? ''));
        $txHash = trim((string)($body['txHash'] ?? ''));

        if ($tipId === '') {
            json_response(['error' => 'tipId is required'], 400);
        }

        $tip = find_tip_record(static fn(array $item): bool => ($item['id'] ?? '') === $tipId);
        if ($tip === null || ($tip['processor'] ?? '') !== 'coinbase') {
            json_response(['error' => 'Crypto tip not found'], 404);
        }

        update_tip_record(
            static fn(array $item): bool => ($item['id'] ?? '') === $tipId,
            [
                'status' => 'paid',
                'txHash' => $txHash,
                'paidAt' => now_iso()
            ]
        );

        $output = [
            'status' => 'ok',
            'message' => 'Crypto payment confirmed'
        ];
        break;

    case 'request-coinbase-transfer':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'Method not allowed'], 405);
        }

        $body = read_json_input();
        $tipId = trim((string)($body['tipId'] ?? ''));

        if ($tipId === '') {
            json_response(['error' => 'tipId is required'], 400);
        }

        $tip = find_tip_record(static fn(array $item): bool => ($item['id'] ?? '') === $tipId);
        if ($tip === null || ($tip['processor'] ?? '') !== 'coinbase') {
            json_response(['error' => 'Crypto tip not found'], 404);
        }

        if (!in_array((string)($tip['status'] ?? ''), ['paid', 'payment_submitted', 'coinbase_transfer_requested'], true)) {
            json_response(['error' => 'Tip is not ready for transfer request'], 400);
        }

        $relay = forward_coinbase_transfer_request($tip);
        $transferStatus = ($relay['attempted'] ?? false) === true
            ? (($relay['success'] ?? false) === true ? 'relay_accepted' : 'relay_failed')
            : 'manual_pending';

        update_tip_record(
            static fn(array $item): bool => ($item['id'] ?? '') === $tipId,
            [
                'status' => 'coinbase_transfer_requested',
                'coinbaseTransferStatus' => $transferStatus,
                'coinbaseTransferRequestedAt' => now_iso(),
                'coinbaseTransferError' => (string)($relay['error'] ?? '')
            ]
        );

        $output = [
            'status' => 'ok',
            'message' => ($relay['attempted'] ?? false) ? 'Transfer request sent to relay.' : 'Transfer request marked as pending manual Coinbase send.',
            'relay' => $relay
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

    case 'stripe-backfill':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'Method not allowed'], 405);
        }

        $body = read_json_input();
        $mode = strtolower(trim((string)($body['mode'] ?? 'full')));
        $days = (int)($body['days'] ?? 3650);
        $maxPages = (int)($body['maxPages'] ?? 500);

        if (!in_array($mode, ['full', 'days'], true)) {
            json_response(['error' => 'mode must be full or days'], 400);
        }

        if ($days < 1) {
            $days = 1;
        }
        if ($days > 3650) {
            $days = 3650;
        }

        $createdGte = 0;
        if ($mode === 'days') {
            $createdGte = time() - ($days * 86400);
        }

        $backfill = stripe_backfill_checkout_sessions($createdGte, $maxPages);
        if (!($backfill['ok'] ?? false)) {
            json_response([
                'error' => (string)($backfill['error'] ?? 'Stripe backfill failed'),
                'details' => $backfill
            ], (int)($backfill['status'] ?? 500));
        }

        $output = [
            'status' => 'ok',
            'mode' => $mode,
            'days' => $mode === 'days' ? $days : null,
            'backfill' => $backfill
        ];
        break;

    case 'dashboard':
        // Main dashboard with key metrics
        require_once __DIR__ . '/webhook-advanced.php';
        
        $webhook = get_webhook_health();
        
        $lb = read_leaderboard_store();
        $totalGames = count($lb['games'] ?? []);
        $totalLeaderboardEntries = 0;
        foreach (($lb['games'] ?? []) as $gameEntries) {
            $totalLeaderboardEntries += is_array($gameEntries) ? count($gameEntries) : 0;
        }
        
        $tips = read_tip_store();
        $paidTips = array_filter($tips['tips'], fn($t) => ($t['status'] ?? '') === 'paid');

        $ukTz = new DateTimeZone('Europe/London');
        $monthStart = new DateTimeImmutable('first day of this month 00:00:00', $ukTz);
        $monthStartTs = $monthStart->getTimestamp();
        $monthlyPaidTips = array_values(array_filter($paidTips, static function (array $tip) use ($monthStartTs): bool {
            $paidAtRaw = (string)($tip['paidAt'] ?? $tip['createdAt'] ?? '');
            $paidAtTs = strtotime($paidAtRaw);
            if ($paidAtTs === false) {
                return false;
            }

            return $paidAtTs >= $monthStartTs;
        }));
        
        $analytics = read_analytics_store();
        $uniquePlayers = count(array_unique(array_map(fn($s) => $s['username'], $analytics['sessions'] ?? [])));
        
        // Build revenue breakdown from paid tips by type
        $byType = [];
        foreach ($monthlyPaidTips as $tip) {
            $type = $tip['type'] ?? 'tip';
            $amountCents = $tip['amountCents'] ?? 0;
            if (!isset($byType[$type])) {
                $byType[$type] = 0;
            }
            $byType[$type] += $amountCents;
        }
        
        // Format revenue breakdown
        $breakdown = [];
        foreach ($byType as $type => $amountCents) {
            $breakdown[] = [
                'type' => $type,
                'amountCents' => $amountCents,
                'count' => count(array_filter($monthlyPaidTips, fn($t) => ($t['type'] ?? 'tip') === $type))
            ];
        }
        
        // Sort paid tips by date descending for recent transactions
        usort($paidTips, fn($a, $b) => strcmp($b['paidAt'] ?? '', $a['paidAt'] ?? ''));
        
        // Format recent transactions (last 50)
        $recentTxs = [];
        foreach (array_slice($paidTips, 0, 50) as $tip) {
            $recentTxs[] = [
                'createdAt' => $tip['paidAt'] ?? now_iso(),
                'username' => $tip['username'] ?? 'anonymous',
                'amountCents' => $tip['amountCents'] ?? 0,
                'type' => $tip['type'] ?? 'tip'
            ];
        }
        
        $totalRevenue = array_sum(array_map(fn($t) => $t['amountCents'] ?? 0, $monthlyPaidTips));
        
        $output = [
            'status' => 'ok',
            'metrics' => [
                'webhookHealth' => $webhook,
                'leaderboards' => [
                    'gameCount' => $totalGames,
                    'totalEntries' => $totalLeaderboardEntries
                ],
                'monetization' => [
                    'totalTips' => count($monthlyPaidTips),
                    'totalRevenueCents' => $totalRevenue,
                    'breakdown' => $breakdown
                ],
                'players' => [
                    'uniquePlayers' => $uniquePlayers,
                    'totalSessions' => count($analytics['sessions'] ?? [])
                ],
                'recentTransactions' => $recentTxs
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
        $currency = strtolower(trim((string)($body['currency'] ?? 'gbp')));
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
                'PAYMENT_PROCESSOR' => in_array(strtolower(trim((string)($platform['PAYMENT_PROCESSOR'] ?? $defaults['platform']['PAYMENT_PROCESSOR']))), ['stripe', 'coinbase'], true)
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

    case 'list-staff':
        // List all staff members (admins and mods)
        ensure_bootstrap_admin();
        $store = read_admin_store();
        
        // Get current user's ID from session
        $sessions_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'admin-sessions.json';
        $current_admin_id = null;
        if (is_file($sessions_file)) {
            $raw = file_get_contents($sessions_file);
            $store_sessions = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];
            foreach (($store_sessions['sessions'] ?? []) as $sess) {
                if (($sess['token'] ?? '') === $token) {
                    $current_admin_id = $sess['adminId'] ?? null;
                    break;
                }
            }
        }
        
        $staff = [];
        foreach ($store['admins'] ?? [] as $admin) {
            // Skip the current user
            if (($admin['id'] ?? '') === $current_admin_id) {
                continue;
            }
            $staff[] = [
                'username' => $admin['username'] ?? '',
                'role' => $admin['role'] ?? 'admin',
                'createdAt' => $admin['createdAt'] ?? now_iso()
            ];
        }
        
        $output = [
            'status' => 'ok',
            'staff' => $staff
        ];
        break;

    case 'add-staff':
        // Add a new staff member (admin or mod)
        // Only admins can add staff
                // Extract token and verify it's an admin
                $token = trim(get_header_value('x-admin-token'));
                if ($token === '' && isset($_GET['token']) && is_string($_GET['token'])) {
                    $token = trim($_GET['token']);
                }
        
                $current_user_role = 'admin'; // Default to admin for backwards compatibility
                $sessions_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'admin-sessions.json';
                if (is_file($sessions_file)) {
                    $raw = file_get_contents($sessions_file);
                    $store_sessions = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];
                    foreach (($store_sessions['sessions'] ?? []) as $sess) {
                        if (($sess['token'] ?? '') === $token) {
                            $current_user_role = $sess['role'] ?? 'admin';
                            break;
                        }
                    }
                }
        
                // Only admins can add staff
                if ($current_user_role !== 'admin') {
                    json_response(['error' => 'Only admins can add staff members'], 403);
                }
        $data = read_json_input();
        $username = strtolower(trim((string)($data['username'] ?? '')));
        $password = (string)($data['password'] ?? '');
        $role = strtolower(trim((string)($data['role'] ?? 'admin')));
        
        if ($username === '' || $password === '') {
            json_response(['error' => 'Username and password required'], 400);
        }
        
        if (!preg_match('/^[a-z0-9_-]{3,24}$/', $username)) {
            json_response(['error' => 'Invalid username format'], 400);
        }
        
        if (strlen($password) < 8) {
            json_response(['error' => 'Password must be at least 8 characters'], 400);
        }
        
        if (!in_array($role, ['admin', 'mod'], true)) {
            json_response(['error' => 'Role must be admin or mod'], 400);
        }
        
        ensure_bootstrap_admin();
        $store = read_admin_store();
        
        // Check for duplicate username
        foreach ($store['admins'] ?? [] as $admin) {
            if (strtolower($admin['username'] ?? '') === $username) {
                json_response(['error' => 'Username already exists'], 409);
            }
        }
        
        // Create new admin record
        $new_admin = [
            'id' => bin2hex(random_bytes(16)),
            'username' => $username,
            'role' => $role,
            'tokenHash' => password_hash($password, PASSWORD_DEFAULT),
            'createdAt' => now_iso()
        ];
        
        $store['admins'][] = $new_admin;
        write_admin_store($store);
        
        track_event('admin_created', $username, 'system', ['role' => $role]);
        
        $output = [
            'status' => 'ok',
            'message' => "Staff member '$username' created successfully"
        ];
        break;
    
    default:
        json_response(['error' => 'Unknown action'], 404);
}

json_response($output);
