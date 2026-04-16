<?php
declare(strict_types=1);

// ── Server-side gate ──────────────────────────────────────────────────────
$sessions_file = __DIR__ . '/../data/admin-sessions.json';
$admins_file   = __DIR__ . '/../data/admins.json';

$authed_user = null;
$needs_setup = true;

// Check if any admins are registered
if (is_file($admins_file)) {
    $raw   = (string)file_get_contents($admins_file);
    $store = json_decode($raw !== '' ? $raw : '{}', true);
    $needs_setup = count($store['admins'] ?? []) === 0;
}

// Validate cookie session
$cookie_token = trim((string)($_COOKIE['admin_token'] ?? ''));
if ($cookie_token !== '' && is_file($sessions_file)) {
    $raw   = (string)file_get_contents($sessions_file);
    $store = json_decode($raw !== '' ? $raw : '{}', true);
    foreach (($store['sessions'] ?? []) as $sess) {
        if (
            isset($sess['token'], $sess['expiresAt']) &&
            hash_equals($sess['token'], $cookie_token) &&
            strtotime((string)$sess['expiresAt']) > time()
        ) {
            $authed_user = htmlspecialchars((string)$sess['username'], ENT_QUOTES, 'UTF-8');
            break;
        }
    }
}

$show_dashboard = $authed_user !== null;
$show_setup     = $needs_setup && !$show_dashboard;
$show_login     = !$needs_setup && !$show_dashboard;
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - webgames.lol</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0e27;
            color: #e0e6ed;
            line-height: 1.6;
        }

        /* ── Login ── */
        .login-container {
            display: none;
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .login-container.active { display: flex; }

        .login-card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 3rem;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        .login-card h1 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .login-card .subtitle {
            opacity: 0.6;
            margin-bottom: 2rem;
            font-size: 0.9rem;
        }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label {
            display: block;
            opacity: 0.7;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }
        .form-group input {
            width: 100%;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            color: #e0e6ed;
            font-size: 1rem;
            transition: all 0.3s;
        }
        .form-group input:focus {
            outline: none;
            background: rgba(255,255,255,0.12);
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        .login-btn {
            width: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.85rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s;
        }
        .login-btn:hover:not(:disabled) { opacity: 0.9; transform: translateY(-1px); }
        .login-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .login-error {
            background: rgba(239,68,68,0.1);
            border: 1px solid rgba(239,68,68,0.3);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            color: #ef4444;
            font-size: 0.9rem;
            display: none;
            margin-bottom: 1rem;
        }
        .login-error.active { display: block; }
        .setup-badge {
            display: inline-block;
            background: rgba(251,146,60,0.15);
            border: 1px solid rgba(251,146,60,0.3);
            color: #fb923c;
            border-radius: 20px;
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            margin-bottom: 1rem;
        }

        /* ── Header ── */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-left h1 { font-size: 2rem; margin-bottom: 0.5rem; }
        .header-left .subtitle { opacity: 0.9; font-size: 0.9rem; }
        .header-right { text-align: right; }
        .admin-info { margin-bottom: 0.5rem; opacity: 0.9; }
        .admin-info strong { color: #fff; }
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s;
        }
        .logout-btn:hover { background: rgba(255,255,255,0.3); }

        /* ── Layout ── */
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .metric-card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 1.5rem;
        }
        .metric-card h3 {
            opacity: 0.7;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 1rem;
        }
        .metric-value { font-size: 2rem; font-weight: bold; color: #667eea; margin-bottom: 0.5rem; }
        .metric-subtext { font-size: 0.85rem; opacity: 0.6; }

        .status-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        .status-healthy  { background: rgba(52,211,153,0.2); color: #34d399; }
        .status-degraded { background: rgba(251,146,60,0.2);  color: #fb923c; }
        .status-critical { background: rgba(239,68,68,0.2);   color: #ef4444; }

        .section { margin-bottom: 3rem; }
        .section h2 {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(102,126,234,0.5);
        }
        .table-responsive {
            overflow-x: auto;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
        }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: rgba(102,126,234,0.1);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        td { padding: 1rem; border-bottom: 1px solid rgba(255,255,255,0.05); }
        tr:hover { background: rgba(102,126,234,0.05); }

        .score-high   { color: #34d399; font-weight: 600; }
        .score-medium { color: #fbbf24; }
        .score-low    { color: #ef4444; }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(102,126,234,0.4); }
        .btn-sm     { padding: 0.4rem 0.8rem; font-size: 0.8rem; }
        .btn-approve { background: linear-gradient(135deg, #34d399 0%, #10b981 100%); }
        .btn-reject  { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }

        .settings-panel {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.25rem;
        }
        .settings-note { font-size: 0.78rem; color: #8892a4; margin-top: 0.35rem; }
        .settings-status { margin-top: 0.75rem; font-size: 0.9rem; min-height: 1.2em; }
        .settings-status.success { color: #34d399; }
        .settings-status.error   { color: #ef4444; }
        .callout {
            background: rgba(102,126,234,0.12);
            border-left: 3px solid #667eea;
            border-radius: 6px;
            padding: .9rem 1rem;
            margin-bottom: 1rem;
            font-size: .88rem;
            line-height: 1.65;
        }
        .callout code { background: rgba(255,255,255,0.1); padding: .1em .4em; border-radius: 3px; font-size: .85em; }
        .callout ul   { padding-left: 1.2rem; margin-top: .4rem; }
        .wiz-pane { display: none; }
        .wiz-pane.active { display: block; }
        .wizard-progress { display: flex; align-items: center; margin-bottom: 1.5rem; }
        .wiz-dot {
            width: 28px; height: 28px; border-radius: 50%;
            background: rgba(255,255,255,.12); border: 2px solid rgba(255,255,255,.2);
            display: flex; align-items: center; justify-content: center;
            font-size: .78rem; font-weight: 700; flex-shrink: 0; color: #e0e6ed;
            transition: all .3s;
        }
        .wiz-dot.done    { background: #34d399; border-color: #34d399; color: #111; }
        .wiz-dot.current { background: #667eea; border-color: #667eea; }
        .wiz-line { flex: 1; height: 2px; background: rgba(255,255,255,.15); margin: 0 6px; }
        .wizard-nav  { display: flex; gap: .75rem; margin-top: 1.25rem; flex-wrap: wrap; align-items: center; }
        .wizard-review dl { display: grid; grid-template-columns: 140px 1fr; gap: .4rem 1rem; font-size: .9rem; }
        .wizard-review dt { opacity: .65; font-weight: 600; }
        .wizard-review dd { font-family: monospace; word-break: break-all; }
        .wizard-summary .sum-row {
            display: flex; gap: 1rem; align-items: baseline;
            padding: .35rem 0; border-bottom: 1px solid rgba(255,255,255,.07); font-size: .9rem;
        }
        .wizard-summary .sum-label { opacity: .65; width: 130px; flex-shrink: 0; }
        .wizard-summary .sum-value { font-family: monospace; word-break: break-all; }

        .runtime-groups {
            display: grid;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .runtime-group {
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 0.9rem;
            background: rgba(255,255,255,0.03);
        }
        .runtime-group h3 {
            margin: 0 0 0.7rem;
            font-size: 0.92rem;
            color: #dbe3f7;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .runtime-field-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 0.8rem;
        }
        .runtime-field label {
            display: block;
            font-size: 0.73rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            opacity: 0.75;
            margin-bottom: 0.35rem;
        }
        .runtime-field input,
        .runtime-field select {
            width: 100%;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 8px;
            padding: 0.58rem 0.75rem;
            color: #e0e6ed;
            font-size: 0.9rem;
        }
        .runtime-json-wrap {
            margin-top: 0.9rem;
        }

        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .tab-btn {
            background: transparent;
            border: none;
            color: #e0e6ed;
            padding: 1rem;
            cursor: pointer;
            font-weight: 600;
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
        }
        .tab-btn.active { color: #667eea; border-bottom-color: #667eea; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .loading { opacity: 0.5; }
        .loading::after {
            content: ' ...';
            animation: dots 1.5s steps(4, end) infinite;
        }
        @keyframes dots {
            0%,20%  { content: '.'; }
            40%     { content: '..'; }
            60%,100%{ content: '...'; }
        }

        @media (max-width: 768px) {
            .grid { grid-template-columns: 1fr; }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
            .header-left h1 { font-size: 1.5rem; }
            .tabs { flex-wrap: wrap; }
            .login-card { padding: 2rem; }
        }
    </style>
</head>
<body>

<!-- First-time setup — only shown by PHP when admins.json is empty -->
<div class="login-container<?= $show_setup ? ' active' : '' ?>" id="setupContainer">
    <div class="login-card">
        <div class="setup-badge">First-time setup</div>
        <h1>Create Admin Account</h1>
        <p class="subtitle">No admins exist yet. Create your account to continue.</p>
        <div class="login-error" id="setupError"></div>
        <form onsubmit="handleSetup(event)">
            <div class="form-group">
                <label for="setupUsername">Username</label>
                <input type="text" id="setupUsername" required autocomplete="username"
                       placeholder="Choose a username" minlength="3" maxlength="24">
            </div>
            <div class="form-group">
                <label for="setupPassword">Password</label>
                <input type="password" id="setupPassword" required autocomplete="new-password"
                       placeholder="Choose a password" minlength="8">
            </div>
            <div class="form-group">
                <label for="setupConfirm">Confirm Password</label>
                <input type="password" id="setupConfirm" required autocomplete="new-password"
                       placeholder="Repeat password" minlength="8">
            </div>
            <button class="login-btn" type="submit" id="setupBtn">Create Account</button>
        </form>
    </div>
</div>

<!-- Login Screen — shown by PHP when admins exist but no valid session cookie -->
<div class="login-container<?= $show_login ? ' active' : '' ?>" id="loginContainer">
    <div class="login-card">
        <h1>Admin Portal</h1>
        <p class="subtitle">webgames.lol Dashboard</p>
        <div class="login-error" id="loginError"></div>
        <form onsubmit="handleLogin(event)">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" required autocomplete="username" placeholder="Enter username">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" required autocomplete="current-password" placeholder="Enter password">
            </div>
            <button class="login-btn" type="submit" id="loginBtn">Sign In</button>
        </form>
        <p style="text-align:center;opacity:0.5;margin-top:1.5rem;font-size:0.85rem;">
            Contact administrator for access
        </p>
    </div>
</div>

<!-- Main Dashboard — shown by PHP when session cookie is valid -->
<div id="dashboardContainer"<?= $show_dashboard ? '' : ' style="display:none"' ?>>
    <div class="header">
        <div class="header-left">
            <h1>Advanced Admin Dashboard</h1>
            <p class="subtitle">webgames.lol &mdash; Analytics, Moderation &amp; Insights</p>
        </div>
        <div class="header-right">
            <div class="admin-info">
                Logged in as <strong id="adminUsername"><?= $authed_user ?? '' ?></strong>
            </div>
            <button class="logout-btn" onclick="handleLogout()">Logout</button>
        </div>
    </div>

    <div class="container">
        <div class="grid">
            <div class="metric-card">
                <h3>Monthly Revenue</h3>
                <div class="metric-value" id="revenueValue">$0.00</div>
                <div class="metric-subtext" id="revenueTxn">0 transactions</div>
            </div>
            <div class="metric-card">
                <h3>Active Players</h3>
                <div class="metric-value" id="playersValue">0</div>
                <div class="metric-subtext" id="playSessions">0 sessions</div>
            </div>
            <div class="metric-card">
                <h3>Leaderboard Entries</h3>
                <div class="metric-value" id="leaderboardValue">0</div>
                <div class="metric-subtext" id="gameCount">0 games</div>
            </div>
            <div class="metric-card">
                <h3>Webhook Health</h3>
                <div class="metric-value" id="webhookStatus">&mdash;</div>
                <div id="webhookDetails"></div>
                <div class="metric-subtext" id="webhookHealth">Checking...</div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('overview', event)">Overview</button>
            <button class="tab-btn" onclick="switchTab('moderation', event)">Moderation</button>
            <button class="tab-btn" onclick="switchTab('achievements', event)">Achievements</button>
            <button class="tab-btn" onclick="switchTab('settings', event)">Settings</button>
            <button class="tab-btn" onclick="switchTab('webhooks', event)">Webhooks</button>
        </div>

        <!-- Overview -->
        <div class="tab-content active" id="overview">
            <div class="section">
                <h2>Revenue Breakdown</h2>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Type</th><th>Amount</th><th>Count</th></tr></thead>
                        <tbody id="revenueTbody"><tr><td colspan="3" class="loading">Loading data</td></tr></tbody>
                    </table>
                </div>
            </div>
            <div class="section">
                <h2>Recent Transactions</h2>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Date</th><th>Player</th><th>Amount</th><th>Type</th></tr></thead>
                        <tbody id="transactionsTbody"><tr><td colspan="4" class="loading">Loading data</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Moderation -->
        <div class="tab-content" id="moderation">
            <div class="section">
                <h2>Suspicious Scores</h2>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Player</th><th>Game</th><th>Score</th><th>Anomaly</th><th>IP</th><th>Actions</th></tr></thead>
                        <tbody id="suspiciousTbody"><tr><td colspan="6" class="loading">Loading suspicious scores</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Achievements -->
        <div class="tab-content" id="achievements">
            <div class="section">
                <h2>Top Achievement Earners</h2>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Rank</th><th>Player</th><th>Achievements</th><th>Points</th></tr></thead>
                        <tbody id="achievementLbTbody"><tr><td colspan="4" class="loading">Loading achievement data</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Settings -->
        <div class="tab-content" id="settings">
            <div class="section">
                <h2>Payment Processors</h2>
                <div class="settings-panel">
                    <p class="settings-note" style="margin-bottom:0.85rem;">
                        Choose the active checkout provider, rotate Stripe credentials, and configure optional PayPal fallback settings.
                    </p>

                    <div class="settings-grid">
                        <div class="form-group">
                            <label for="activePaymentProcessor">Active Processor</label>
                            <select id="activePaymentProcessor">
                                <option value="stripe">Stripe</option>
                                <option value="paypal">PayPal</option>
                            </select>
                            <div class="settings-note">This controls which processor is used by the public tip flow.</div>
                        </div>
                    </div>

                    <h3 style="margin-top:1rem;margin-bottom:0.65rem;">Stripe Account Settings</h3>
                    <div class="settings-grid">
                        <div class="form-group">
                            <label for="stripeSecretKeyInput">Stripe Secret Key</label>
                            <input type="password" id="stripeSecretKeyInput" placeholder="sk_live_..." />
                        </div>
                        <div class="form-group">
                            <label for="stripePublishableKeyInput">Stripe Publishable Key</label>
                            <input type="text" id="stripePublishableKeyInput" placeholder="pk_live_..." />
                        </div>
                        <div class="form-group">
                            <label for="stripeWebhookSecretInput">Stripe Webhook Secret</label>
                            <input type="password" id="stripeWebhookSecretInput" placeholder="whsec_..." />
                        </div>
                        <div class="form-group">
                            <label for="stripeTierProductIdsInput">Stripe Tier Product IDs</label>
                            <input type="text" id="stripeTierProductIdsInput" placeholder="prod_abc,prod_xyz" />
                        </div>
                        <div class="form-group">
                            <label for="stripeTierPriceIdsInput">Stripe Tier Price IDs</label>
                            <input type="text" id="stripeTierPriceIdsInput" placeholder="price_abc,price_xyz" />
                        </div>
                    </div>
                    <div class="wizard-nav" style="margin-top:0.2rem;">
                        <button class="btn" type="button" id="savePaymentProcessorsBtn" onclick="savePaymentProcessorsConfig()">Save Payment Settings</button>
                        <button class="btn btn-reject" type="button" id="resetStripeAccountBtn" onclick="resetStripeAccountConfig()">Reset Stripe Account</button>
                    </div>

                    <h3 style="margin-top:1.1rem;margin-bottom:0.65rem;">PayPal Advanced Settings</h3>
                    <div class="settings-grid">
                        <div class="form-group">
                            <label for="paypalEnvironmentInput">PayPal Environment</label>
                            <select id="paypalEnvironmentInput">
                                <option value="sandbox">sandbox</option>
                                <option value="live">live</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="paypalClientIdInput">PayPal Client ID</label>
                            <input type="text" id="paypalClientIdInput" placeholder="PayPal app client id" />
                        </div>
                        <div class="form-group">
                            <label for="paypalClientSecretInput">PayPal Client Secret</label>
                            <input type="password" id="paypalClientSecretInput" placeholder="PayPal app client secret" />
                        </div>
                        <div class="form-group">
                            <label for="paypalWebhookIdInput">PayPal Webhook ID</label>
                            <input type="text" id="paypalWebhookIdInput" placeholder="Webhook ID (optional)" />
                        </div>
                        <div class="form-group">
                            <label for="paypalCurrencyInput">PayPal Currency</label>
                            <input type="text" id="paypalCurrencyInput" placeholder="USD" maxlength="3" />
                        </div>
                        <div class="form-group">
                            <label for="paypalTipAmountsInput">PayPal Tip Amounts</label>
                            <input type="text" id="paypalTipAmountsInput" placeholder="5,10,20" />
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="paypalCheckoutUrlInput">PayPal Checkout URL</label>
                            <input type="url" id="paypalCheckoutUrlInput" placeholder="https://your-paypal-checkout-endpoint.example/checkout" />
                            <div class="settings-note">When active processor is PayPal, tip flow redirects to this URL.</div>
                        </div>
                    </div>

                    <div class="settings-status" id="paymentProcessorsStatus"></div>
                </div>

                <h2>Stripe One-Time Checkout</h2>
                <div class="settings-panel">
                    <p class="settings-note" style="margin-bottom:0.85rem;">
                        Configure a one-time Stripe product+price, then generate a Checkout Session URL and verify completion via webhook events.
                    </p>
                    <p class="settings-note" style="margin-bottom:0.85rem;">
                        Requires STRIPE_SECRET_KEY and STRIPE_PUBLISHABLE_KEY in your server environment. Get these from the Stripe Dashboard.
                    </p>

                    <div class="settings-grid">
                        <div class="form-group">
                            <label for="stripeOneTimeName">Product Name</label>
                            <input type="text" id="stripeOneTimeName" value="Example Product" />
                        </div>
                        <div class="form-group">
                            <label for="stripeOneTimeCurrency">Currency</label>
                            <input type="text" id="stripeOneTimeCurrency" value="usd" maxlength="3" />
                        </div>
                        <div class="form-group">
                            <label for="stripeOneTimeAmount">Unit Amount (cents)</label>
                            <input type="number" id="stripeOneTimeAmount" value="2000" min="50" step="1" />
                        </div>
                        <div class="form-group">
                            <label for="stripeOneTimeProductId">Product ID</label>
                            <input type="text" id="stripeOneTimeProductId" readonly />
                        </div>
                        <div class="form-group">
                            <label for="stripeOneTimePriceId">Price ID</label>
                            <input type="text" id="stripeOneTimePriceId" readonly />
                        </div>
                        <div class="form-group">
                            <label for="stripeOneTimeLastSession">Last Session ID</label>
                            <input type="text" id="stripeOneTimeLastSession" readonly />
                        </div>
                    </div>

                    <div class="wizard-nav">
                        <button class="btn" type="button" id="stripeOneTimeReloadBtn" onclick="loadStripeOneTimeConfig()">Reload Stripe Config</button>
                        <button class="btn" type="button" id="stripeOneTimeCreateProductBtn" onclick="createStripeOneTimeProduct()">Create Product + Price</button>
                        <button class="btn" type="button" id="stripeOneTimeCreateSessionBtn" onclick="createStripeOneTimeSession()">Create Checkout Session</button>
                    </div>

                    <div class="form-group" style="margin-top:0.9rem;">
                        <label for="stripeOneTimeCheckoutUrl">Last Checkout URL</label>
                        <input type="text" id="stripeOneTimeCheckoutUrl" readonly />
                    </div>
                    <div class="wizard-nav" style="margin-top:0.2rem;">
                        <button class="btn" type="button" id="stripeOneTimeOpenCheckoutBtn" onclick="openStripeCheckoutUrl()">Open Checkout</button>
                    </div>

                    <div class="settings-status" id="stripeOneTimeStatus"></div>

                    <h3 style="margin-top:1.2rem;margin-bottom:0.6rem;">Recent Completed Sessions</h3>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Completed At</th>
                                    <th>Session ID</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Event ID</th>
                                </tr>
                            </thead>
                            <tbody id="stripeOneTimeCompletedTbody">
                                <tr><td colspan="5">No completed sessions yet</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <h2>Runtime Variables</h2>
                <div class="settings-panel">
                    <p class="settings-note" style="margin-bottom:0.85rem;">
                        Edit platform and game tuning variables. Values are persisted to data/runtime-config.json and platform keys also sync to .env.
                    </p>
                    <div id="runtimeConfigFields" class="runtime-groups"></div>
                    <div class="wizard-nav" style="margin-top:0.2rem;">
                        <button class="btn" type="button" id="reloadRuntimeConfigBtn" onclick="loadRuntimeConfig()">Reload</button>
                        <button class="btn" type="button" id="applyJsonToFieldsBtn" onclick="applyJsonToFields()">Apply JSON To Fields</button>
                        <button class="btn" type="button" id="saveRuntimeConfigBtn" onclick="saveRuntimeConfig()">Save Runtime Variables</button>
                    </div>
                    <div class="runtime-json-wrap form-group" style="margin-bottom:0.9rem;">
                        <label for="runtimeConfigEditor">Runtime Config JSON</label>
                        <textarea id="runtimeConfigEditor" rows="22" spellcheck="false" style="width:100%;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:8px;padding:0.8rem 0.9rem;color:#e0e6ed;font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;font-size:0.84rem;line-height:1.45;"></textarea>
                    </div>
                    <div class="settings-status" id="runtimeConfigStatus"></div>
                </div>
            </div>
        </div>

        <!-- Webhooks -->
        <div class="tab-content" id="webhooks">
            <div class="section">
                <h2>Webhook Proxy</h2>
                <div class="settings-panel">

                    <!-- Step progress bar -->
                    <div class="wizard-progress" id="wizProgress" style="display:none;">
                        <div class="wiz-dot" id="wizDot1">1</div>
                        <div class="wiz-line"></div>
                        <div class="wiz-dot" id="wizDot2">2</div>
                        <div class="wiz-line"></div>
                        <div class="wiz-dot" id="wizDot3">3</div>
                    </div>

                    <!-- Pane 0: Configured summary -->
                    <div class="wiz-pane" id="wizPane0">
                        <div class="wizard-summary" id="wizSummary"></div>
                        <div class="wizard-nav">
                            <button class="btn" onclick="wizGoto(2)">Edit Settings</button>
                            <button class="btn btn-reject" onclick="wizardDisable()">Disable Proxy</button>
                        </div>
                    </div>

                    <!-- Pane 1: Intro -->
                    <div class="wiz-pane" id="wizPane1">
                        <h3 style="margin-bottom:.75rem;">What is Webhook Proxy Forwarding?</h3>
                        <div class="callout">
                            <strong>Stripe sends webhooks to one URL.</strong> Proxy forwarding makes this server relay every incoming Stripe event to a second server immediately after verifying the signature.
                            <ul>
                                <li>Stripe &rarr; <em>this server</em> (signature verified here)</li>
                                <li><em>This server</em> &rarr; your target URL (forwarded instantly)</li>
                                <li>A loop guard prevents infinite forwarding chains</li>
                            </ul>
                        </div>
                        <p style="opacity:.7;font-size:.85rem;margin-bottom:1rem;">Use this to mirror events to a staging environment, a second site, or any service that needs to react to Stripe payments.</p>
                        <div class="wizard-nav">
                            <button class="btn" onclick="wizGoto(2)">Set Up Proxy &rarr;</button>
                        </div>
                    </div>

                    <!-- Pane 2: Target URL -->
                    <div class="wiz-pane" id="wizPane2">
                        <h3 style="margin-bottom:.25rem;">Step 1 of 3 &mdash; Target URL</h3>
                        <p style="opacity:.75;font-size:.9rem;margin-bottom:1rem;">Enter the webhook endpoint on the <em>other</em> server that should receive the forwarded payloads.</p>
                        <div class="form-group">
                            <label for="wizUrl">Forward URL</label>
                            <input type="url" id="wizUrl" placeholder="https://other-site.example/api/stripe-webhook.php" />
                            <div class="settings-note">Must start with https://. This is the same path you would register with Stripe on the target server.</div>
                        </div>
                        <div class="wizard-nav">
                            <button class="btn" style="background:rgba(255,255,255,.12);" onclick="wizGoto(1)">&larr; Back</button>
                            <button class="btn" onclick="wizStep2Next()">Next &rarr;</button>
                        </div>
                    </div>

                    <!-- Pane 3: Authentication -->
                    <div class="wiz-pane" id="wizPane3">
                        <h3 style="margin-bottom:.25rem;">Step 2 of 3 &mdash; Authentication</h3>
                        <p style="opacity:.75;font-size:.9rem;margin-bottom:.75rem;">Set a shared secret so the target server can verify that forwarded requests come from this proxy.</p>
                        <div class="callout" style="font-size:.85rem;">
                            On the <strong>target site</strong>, go to <em>Admin &rarr; Webhooks</em> and paste the same token in <em>Auth Token</em> &mdash; or add <code>WEBHOOK_FORWARD_AUTH_TOKEN=your-secret</code> to its <code>.env</code>.
                        </div>
                        <div class="settings-grid">
                            <div class="form-group">
                                <label for="wizAuthHeader">Auth Header Name</label>
                                <input type="text" id="wizAuthHeader" placeholder="x-webgames-proxy-token" />
                                <div class="settings-note">HTTP header sent to the target with every forwarded request. Keep the default unless you need a custom name.</div>
                            </div>
                            <div class="form-group">
                                <label for="wizAuthToken">Shared Secret</label>
                                <input type="text" id="wizAuthToken" placeholder="leave blank to skip auth" />
                                <div class="settings-note">Leave blank to disable authentication. Use a long random string for production.</div>
                            </div>
                        </div>
                        <div class="wizard-nav">
                            <button class="btn" style="background:rgba(255,255,255,.12);" onclick="wizGoto(2)">&larr; Back</button>
                            <button class="btn" onclick="wizStep3Next()">Review &rarr;</button>
                        </div>
                    </div>

                    <!-- Pane 4: Review & Save -->
                    <div class="wiz-pane" id="wizPane4">
                        <h3 style="margin-bottom:1rem;">Step 3 of 3 &mdash; Review &amp; Save</h3>
                        <div class="wizard-review" id="wizReview"></div>
                        <div class="wizard-nav" style="margin-top:1.25rem;">
                            <button class="btn" style="background:rgba(255,255,255,.12);" onclick="wizGoto(3)">&larr; Back</button>
                            <button class="btn" id="saveWebhookProxyBtn" onclick="wizardSave()">Save &amp; Enable Proxy</button>
                        </div>
                    </div>

                    <div class="settings-status" id="webhookProxyStatus"></div>
                </div>

                <h2>Webhook Events</h2>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Time</th><th>Type</th><th>Status</th><th>Retries</th><th>Event ID / Proxy</th></tr></thead>
                        <tbody id="webhookEventsTbody"><tr><td colspan="5" class="loading">Loading webhook events</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Token comes from cookie (set on login) — PHP already validated it server-side.
    // We still need it client-side to pass to the API endpoints.
    let sessionToken = getCookie('admin_token');
    let sessionUsername = <?= json_encode($authed_user) ?>;
    let sessionForcedLogout = false;

    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function setCookie(name, value, days) {
        const expires = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie = name + '=' + encodeURIComponent(value) +
            '; expires=' + expires + '; path=/; SameSite=Strict';
    }

    function deleteCookie(name) {
        document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Strict';
    }

    // ── First-time setup ───────────────────────────────────────────────────
    async function handleSetup(event) {
        event.preventDefault();

        const username  = document.getElementById('setupUsername').value.trim();
        const password  = document.getElementById('setupPassword').value;
        const confirm   = document.getElementById('setupConfirm').value;
        const setupBtn  = document.getElementById('setupBtn');
        const setupError = document.getElementById('setupError');

        setupError.classList.remove('active');

        if (password !== confirm) {
            setupError.textContent = 'Passwords do not match.';
            setupError.classList.add('active');
            return;
        }

        setupBtn.disabled = true;
        setupBtn.textContent = 'Creating account...';

        try {
            const res  = await fetch('/api/admin-setup.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            });
            const data = await res.json();

            if (data.status === 'ok' && data.sessionToken) {
                setCookie('admin_token', data.sessionToken, 7);
                location.reload();
            } else {
                setupError.textContent = data.error || 'Setup failed.';
                setupError.classList.add('active');
            }
        } catch (err) {
            setupError.textContent = 'Connection error: ' + err.message;
            setupError.classList.add('active');
        } finally {
            setupBtn.disabled = false;
            setupBtn.textContent = 'Create Account';
        }
    }

    // ── Login ──────────────────────────────────────────────────────────────
    async function handleLogin(event) {
        event.preventDefault();

        const username  = document.getElementById('username').value.trim();
        const password  = document.getElementById('password').value;
        const loginBtn  = document.getElementById('loginBtn');
        const loginError = document.getElementById('loginError');

        loginBtn.disabled = true;
        loginBtn.textContent = 'Signing in...';
        loginError.classList.remove('active');

        try {
            const res  = await fetch('/api/admin-login.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            });
            const data = await res.json();

            if (data.status === 'ok' && data.sessionToken) {
                sessionToken    = data.sessionToken;
                sessionUsername = data.admin.username;
                setCookie('admin_token', sessionToken, 7);
                // Reload so PHP renders the dashboard server-side
                location.reload();
            } else {
                loginError.textContent = data.error || 'Login failed';
                loginError.classList.add('active');
            }
        } catch (err) {
            loginError.textContent = 'Connection error: ' + err.message;
            loginError.classList.add('active');
        } finally {
            loginBtn.disabled = false;
            loginBtn.textContent = 'Sign In';
        }
    }

    // ── Logout ─────────────────────────────────────────────────────────────
    async function performLogout(confirmFirst) {
        if (confirmFirst && !confirm('Are you sure you want to logout?')) {
            return;
        }

        try {
            await fetch('/api/admin-login.php?action=logout', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: sessionToken })
            });
        } catch (err) {
            console.error('Logout error:', err);
        }

        deleteCookie('admin_token');
        location.reload();
    }

    async function handleLogout() {
        await performLogout(true);
    }

    async function handleSessionExpired() {
        if (sessionForcedLogout) {
            return;
        }

        sessionForcedLogout = true;
        await performLogout(false);
    }

    // ── Tabs ───────────────────────────────────────────────────────────────
    function switchTab(tabName, event) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById(tabName).classList.add('active');
        if (event) event.target.classList.add('active');
    }

    // ── API helper ─────────────────────────────────────────────────────────
    async function fetch_admin_api(action, params = {}) {
        const url = new URL('/api/admin-analytics.php', location.origin);
        url.searchParams.set('action', action);
        url.searchParams.set('token', sessionToken);
        Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

        const res = await fetch(url);
        if (res.status === 401) { await handleSessionExpired(); return {}; }
        return res.json();
    }

    let paymentProcessorConfigState = null;

    function renderPaymentProcessorsConfig(config) {
        paymentProcessorConfigState = config || null;

        document.getElementById('activePaymentProcessor').value = config?.activeProcessor || 'stripe';

        document.getElementById('stripeSecretKeyInput').value = config?.stripe?.secretKey || '';
        document.getElementById('stripePublishableKeyInput').value = config?.stripe?.publishableKey || '';
        document.getElementById('stripeWebhookSecretInput').value = config?.stripe?.webhookSecret || '';
        document.getElementById('stripeTierProductIdsInput').value = config?.stripe?.tierProductIds || '';
        document.getElementById('stripeTierPriceIdsInput').value = config?.stripe?.tierPriceIds || '';

        document.getElementById('paypalEnvironmentInput').value = config?.paypal?.environment || 'sandbox';
        document.getElementById('paypalClientIdInput').value = config?.paypal?.clientId || '';
        document.getElementById('paypalClientSecretInput').value = config?.paypal?.clientSecret || '';
        document.getElementById('paypalWebhookIdInput').value = config?.paypal?.webhookId || '';
        document.getElementById('paypalCurrencyInput').value = config?.paypal?.currency || 'USD';
        document.getElementById('paypalTipAmountsInput').value = config?.paypal?.tipAmounts || '5,10,20';
        document.getElementById('paypalCheckoutUrlInput').value = config?.paypal?.checkoutUrl || '';
    }

    async function loadPaymentProcessorsConfig() {
        const statusEl = document.getElementById('paymentProcessorsStatus');
        statusEl.className = 'settings-status';
        statusEl.textContent = 'Loading payment settings...';

        try {
            const data = await fetch_admin_api('payment-processors-config');
            const config = data.config || {};
            renderPaymentProcessorsConfig(config);
            statusEl.className = 'settings-status success';
            statusEl.textContent = 'Payment settings loaded.';
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = 'Unable to load payment settings.';
            console.error('Payment settings load error:', err);
        }
    }

    async function savePaymentProcessorsConfig() {
        const statusEl = document.getElementById('paymentProcessorsStatus');
        const saveBtn = document.getElementById('savePaymentProcessorsBtn');

        const payload = {
            activeProcessor: String(document.getElementById('activePaymentProcessor').value || 'stripe').toLowerCase(),
            stripe: {
                secretKey: document.getElementById('stripeSecretKeyInput').value.trim(),
                publishableKey: document.getElementById('stripePublishableKeyInput').value.trim(),
                webhookSecret: document.getElementById('stripeWebhookSecretInput').value.trim(),
                tierProductIds: document.getElementById('stripeTierProductIdsInput').value.trim(),
                tierPriceIds: document.getElementById('stripeTierPriceIdsInput').value.trim()
            },
            paypal: {
                environment: String(document.getElementById('paypalEnvironmentInput').value || 'sandbox').toLowerCase(),
                clientId: document.getElementById('paypalClientIdInput').value.trim(),
                clientSecret: document.getElementById('paypalClientSecretInput').value.trim(),
                webhookId: document.getElementById('paypalWebhookIdInput').value.trim(),
                currency: String(document.getElementById('paypalCurrencyInput').value || 'USD').toUpperCase(),
                tipAmounts: document.getElementById('paypalTipAmountsInput').value.trim(),
                checkoutUrl: document.getElementById('paypalCheckoutUrlInput').value.trim()
            }
        };

        statusEl.className = 'settings-status';
        statusEl.textContent = 'Saving payment settings...';
        saveBtn.disabled = true;

        try {
            const res = await fetch(`/api/admin-analytics.php?action=update-payment-processors-config&token=${encodeURIComponent(sessionToken)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'ok') {
                throw new Error(data.error || 'Unable to save payment settings');
            }

            renderPaymentProcessorsConfig(data.config || payload);
            statusEl.className = 'settings-status success';
            statusEl.textContent = 'Payment settings saved.';
            await loadRuntimeConfig();
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message;
        } finally {
            saveBtn.disabled = false;
        }
    }

    async function resetStripeAccountConfig() {
        const statusEl = document.getElementById('paymentProcessorsStatus');
        const resetBtn = document.getElementById('resetStripeAccountBtn');

        if (!confirm('Reset Stripe keys, tier IDs, and one-time checkout metadata so you can connect a new Stripe account?')) {
            return;
        }

        statusEl.className = 'settings-status';
        statusEl.textContent = 'Resetting Stripe account configuration...';
        resetBtn.disabled = true;

        try {
            const res = await fetch(`/api/admin-analytics.php?action=stripe-reset-account&token=${encodeURIComponent(sessionToken)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            });

            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'ok') {
                throw new Error(data.error || 'Unable to reset Stripe configuration');
            }

            statusEl.className = 'settings-status success';
            statusEl.textContent = data.message || 'Stripe configuration reset.';
            await loadPaymentProcessorsConfig();
            await loadStripeOneTimeConfig();
            await loadRuntimeConfig();
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message;
        } finally {
            resetBtn.disabled = false;
        }
    }

    // ── Dashboard data ─────────────────────────────────────────────────────
    async function loadDashboard() {
        try {
            const data = await fetch_admin_api('dashboard');
            if (!data.metrics) return;

            const m = data.metrics;
            document.getElementById('revenueValue').textContent =
                '$' + ((m.monetization?.totalRevenueCents ?? 0) / 100).toFixed(2);
            document.getElementById('revenueTxn').textContent =
                (m.monetization?.totalTips ?? 0) + ' transactions';

            document.getElementById('playersValue').textContent  = m.players?.uniquePlayers ?? 0;
            document.getElementById('playSessions').textContent  = (m.players?.totalSessions ?? 0) + ' sessions';
            document.getElementById('leaderboardValue').textContent = m.leaderboards?.totalEntries ?? 0;
            document.getElementById('gameCount').textContent     = (m.leaderboards?.gameCount ?? 0) + ' games';

            const wh = m.webhookHealth ?? {};
            const statusClass = 'metric-value status-' + (wh.status ?? 'degraded');
            document.getElementById('webhookStatus').textContent = (wh.status ?? '—').toUpperCase();
            document.getElementById('webhookStatus').className   = statusClass;
            document.getElementById('webhookDetails').innerHTML  =
                `<span class="status-badge status-${wh.status ?? 'degraded'}">${wh.successRate ?? 0}% success</span>`;
            document.getElementById('webhookHealth').textContent =
                `${wh.totalProcessed ?? 0} processed, ${wh.pendingRetries ?? 0} retries`;

            // Revenue table
            const revTbody = document.getElementById('revenueTbody');
            revTbody.innerHTML = '';
            const rev = m.monetization?.breakdown ?? [];
            if (!rev.length) {
                revTbody.innerHTML = '<tr><td colspan="3">No revenue data yet</td></tr>';
            } else {
                rev.forEach(r => {
                    const tr = revTbody.insertRow();
                    tr.innerHTML = `<td>${r.type}</td><td class="score-high">$${(r.amountCents/100).toFixed(2)}</td><td>${r.count}</td>`;
                });
            }

            // Transactions table
            const txTbody = document.getElementById('transactionsTbody');
            txTbody.innerHTML = '';
            const txs = m.recentTransactions ?? [];
            if (!txs.length) {
                txTbody.innerHTML = '<tr><td colspan="4">No transactions yet</td></tr>';
            } else {
                txs.forEach(t => {
                    const tr = txTbody.insertRow();
                    tr.innerHTML = `
                        <td>${new Date(t.createdAt).toLocaleString()}</td>
                        <td>${t.username ?? 'anonymous'}</td>
                        <td class="score-high">$${((t.amountCents ?? 0)/100).toFixed(2)}</td>
                        <td>${t.type ?? '—'}</td>`;
                });
            }
        } catch (err) {
            console.error('Dashboard load error:', err);
        }
    }

    async function loadSuspiciousScores() {
        try {
            const data  = await fetch_admin_api('suspicious-scores');
            const tbody = document.getElementById('suspiciousTbody');
            tbody.innerHTML = '';
            const scores = data.suspiciousScores ?? [];
            if (!scores.length) {
                tbody.innerHTML = '<tr><td colspan="6">No suspicious scores flagged</td></tr>';
                return;
            }
            scores.forEach(s => {
                const tr = tbody.insertRow();
                tr.innerHTML = `
                    <td>${s.username}</td>
                    <td>${s.game}</td>
                    <td><span class="score-high">${s.score.toLocaleString()}</span></td>
                    <td>${(s.anomalyScore * 100).toFixed(1)}%</td>
                    <td><code style="font-size:.75rem">${s.ip}</code></td>
                    <td>
                        <button class="btn btn-sm btn-approve" onclick="moderateScore('${s.id}','approve')">✓</button>
                        <button class="btn btn-sm btn-reject"  onclick="moderateScore('${s.id}','reject')">✗</button>
                    </td>`;
            });
        } catch (err) { console.error('Suspicious scores error:', err); }
    }

    async function moderateScore(scoreId, action) {
        try {
            const res = await fetch('/api/admin-analytics.php?action=moderate-score', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Admin-Token': sessionToken },
                body: JSON.stringify({ scoreId, action, token: sessionToken })
            });
            const data = await res.json();
            if (data.status === 'ok') { alert('Score ' + action + 'ed'); loadSuspiciousScores(); }
        } catch (err) { console.error('Moderation error:', err); }
    }

    async function loadAchievementLeaderboard() {
        try {
            const data  = await fetch_admin_api('achievement-leaderboard');
            const tbody = document.getElementById('achievementLbTbody');
            tbody.innerHTML = '';
            (data.leaderboard ?? []).forEach((entry, idx) => {
                const tr = tbody.insertRow();
                tr.innerHTML = `<td>#${idx+1}</td><td>${entry.username}</td><td>${entry.achievementCount}</td><td class="score-high">${entry.totalPoints}</td>`;
            });
        } catch (err) { console.error('Achievement leaderboard error:', err); }
    }

    async function loadWebhookEvents() {
        try {
            const data  = await fetch_admin_api('webhook-health');
            const tbody = document.getElementById('webhookEventsTbody');
            tbody.innerHTML = '';
            (data.recentEvents ?? []).forEach(ev => {
                const tr     = tbody.insertRow();
                const time   = new Date(ev.receivedAt).toLocaleString();
                const status = ev.processed
                    ? (ev.error ? '<span class="status-badge status-critical">Failed</span>'
                                : '<span class="status-badge status-healthy">OK</span>')
                    : '<span class="status-badge status-degraded">Pending</span>';
                const fwd = ev.forward || {};
                const fwdText = fwd.attempted
                    ? (fwd.success ? 'Proxy ✓' : 'Proxy failed')
                    : (fwd.enabled ? 'Proxy skipped' : 'Proxy off');
                tr.innerHTML = `
                    <td>${time}</td>
                    <td>${ev.eventType}</td>
                    <td>${status}</td>
                    <td>${ev.retries}</td>
                    <td><code style="font-size:.7rem">${ev.eventId.substring(0,12)}</code><br><span style="opacity:0.65;font-size:.75rem;">${fwdText}</span></td>`;
            });
        } catch (err) { console.error('Webhook events error:', err); }
    }

    // ── Proxy Wizard ──────────────────────────────────────────────────────
    function wizGoto(pane) {
        document.querySelectorAll('.wiz-pane').forEach(p => p.classList.remove('active'));
        const target = document.getElementById('wizPane' + pane);
        if (target) target.classList.add('active');
        const prog = document.getElementById('wizProgress');
        if (pane <= 1) {
            prog.style.display = 'none';
        } else {
            prog.style.display = 'flex';
            [1, 2, 3].forEach(n => {
                const dot = document.getElementById('wizDot' + n);
                dot.className = 'wiz-dot' + (n < pane - 1 ? ' done' : n === pane - 1 ? ' current' : '');
            });
        }
    }

    function wizStep2Next() {
        const url = (document.getElementById('wizUrl').value || '').trim();
        if (!url) { alert('Please enter a Forward URL.'); return; }
        if (!/^https?:\/\/.+/.test(url)) { alert('URL must start with http:// or https://'); return; }
        wizGoto(3);
    }

    function wizStep3Next() {
        const url    = document.getElementById('wizUrl').value.trim();
        const header = document.getElementById('wizAuthHeader').value.trim() || 'x-webgames-proxy-token';
        const token  = document.getElementById('wizAuthToken').value.trim();
        document.getElementById('wizReview').innerHTML = `<dl>
            <dt>Forward URL</dt><dd>${url}</dd>
            <dt>Auth Header</dt><dd>${header}</dd>
            <dt>Shared Secret</dt><dd>${token ? '········ (set)' : '<em style="opacity:.6">none &mdash; auth disabled</em>'}</dd>
        </dl>`;
        wizGoto(4);
    }

    async function wizardSave() {
        const statusEl = document.getElementById('webhookProxyStatus');
        const saveBtn  = document.getElementById('saveWebhookProxyBtn');
        statusEl.className   = 'settings-status';
        statusEl.textContent = 'Saving…';
        saveBtn.disabled = true;
        const url    = document.getElementById('wizUrl').value.trim();
        const header = document.getElementById('wizAuthHeader').value.trim() || 'x-webgames-proxy-token';
        const token  = document.getElementById('wizAuthToken').value.trim();
        try {
            const res  = await fetch(
                `/api/admin-analytics.php?action=update-webhook-proxy-config&token=${encodeURIComponent(sessionToken)}`,
                { method: 'POST', headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ enabled: true, forwardUrl: url, forwardAuthHeader: header, forwardAuthToken: token }) }
            );
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'ok') throw new Error(data.error || 'Save failed');
            statusEl.className   = 'settings-status success';
            statusEl.textContent = 'Proxy enabled and settings saved.';
            await loadWebhookProxyConfig();
        } catch (err) {
            statusEl.className   = 'settings-status error';
            statusEl.textContent = err.message;
        } finally {
            saveBtn.disabled = false;
        }
    }

    async function wizardDisable() {
        if (!confirm('Disable webhook proxy forwarding?')) return;
        const statusEl = document.getElementById('webhookProxyStatus');
        statusEl.className   = 'settings-status';
        statusEl.textContent = 'Disabling…';
        try {
            const res  = await fetch(
                `/api/admin-analytics.php?action=update-webhook-proxy-config&token=${encodeURIComponent(sessionToken)}`,
                { method: 'POST', headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ enabled: false, forwardUrl: '', forwardAuthHeader: 'x-webgames-proxy-token', forwardAuthToken: '' }) }
            );
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'ok') throw new Error(data.error || 'Failed to disable');
            await loadWebhookProxyConfig();
        } catch (err) {
            statusEl.className   = 'settings-status error';
            statusEl.textContent = err.message;
        }
    }

    async function loadWebhookProxyConfig() {
        try {
            const data   = await fetch_admin_api('webhook-proxy-config');
            const config = data.config || {};
            document.getElementById('wizUrl').value        = config.forwardUrl || '';
            document.getElementById('wizAuthHeader').value = config.forwardAuthHeader || 'x-webgames-proxy-token';
            document.getElementById('wizAuthToken').value  = config.forwardAuthToken || '';
            if (config.enabled && config.forwardUrl) {
                document.getElementById('wizSummary').innerHTML = `
                    <div class="sum-row"><span class="sum-label">Status</span><span class="sum-value" style="color:#34d399;font-weight:700;">Enabled</span></div>
                    <div class="sum-row"><span class="sum-label">Forward URL</span><span class="sum-value">${config.forwardUrl}</span></div>
                    <div class="sum-row"><span class="sum-label">Auth Header</span><span class="sum-value">${config.forwardAuthHeader || 'x-webgames-proxy-token'}</span></div>
                    <div class="sum-row"><span class="sum-label">Auth Token</span><span class="sum-value">${config.forwardAuthToken ? '········ (set)' : '<em style="opacity:.6">not set</em>'}</span></div>`;
                wizGoto(0);
            } else {
                wizGoto(1);
            }
        } catch (err) { console.error('Webhook proxy config error:', err); }
    }

    let stripeOneTimeConfigState = null;

    function renderStripeOneTimeConfig(config) {
        stripeOneTimeConfigState = config || null;
        document.getElementById('stripeOneTimeName').value = config?.productName || 'Example Product';
        document.getElementById('stripeOneTimeCurrency').value = (config?.currency || 'usd');
        document.getElementById('stripeOneTimeAmount').value = Number(config?.amountCents || 2000);
        document.getElementById('stripeOneTimeProductId').value = config?.productId || '';
        document.getElementById('stripeOneTimePriceId').value = config?.priceId || '';
        document.getElementById('stripeOneTimeLastSession').value = config?.lastSessionId || '';
        document.getElementById('stripeOneTimeCheckoutUrl').value = config?.lastCheckoutUrl || '';

        const tbody = document.getElementById('stripeOneTimeCompletedTbody');
        const history = Array.isArray(config?.completedSessions) ? config.completedSessions : [];
        tbody.innerHTML = '';
        if (!history.length) {
            tbody.innerHTML = '<tr><td colspan="5">No completed sessions yet</td></tr>';
            return;
        }

        history.slice().reverse().forEach((entry) => {
            const tr = document.createElement('tr');
            const amount = `$${(Number(entry.amountCents || 0) / 100).toFixed(2)} ${String(entry.currency || 'usd').toUpperCase()}`;
            tr.innerHTML = `
                <td>${entry.completedAt ? new Date(entry.completedAt).toLocaleString() : '—'}</td>
                <td><code style="font-size:.75rem">${entry.sessionId || '—'}</code></td>
                <td>${amount}</td>
                <td>${entry.paymentStatus || '—'}</td>
                <td><code style="font-size:.75rem">${entry.eventId || '—'}</code></td>
            `;
            tbody.appendChild(tr);
        });
    }

    async function loadStripeOneTimeConfig() {
        const statusEl = document.getElementById('stripeOneTimeStatus');
        statusEl.className = 'settings-status';
        statusEl.textContent = 'Loading Stripe one-time config...';

        try {
            const data = await fetch_admin_api('stripe-one-time-config');
            renderStripeOneTimeConfig(data.config || {});
            statusEl.className = 'settings-status success';
            statusEl.textContent = 'Stripe one-time config loaded.';
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = 'Unable to load Stripe one-time config.';
            console.error('Stripe one-time config error:', err);
        }
    }

    async function createStripeOneTimeProduct() {
        const statusEl = document.getElementById('stripeOneTimeStatus');
        const createBtn = document.getElementById('stripeOneTimeCreateProductBtn');

        const payload = {
            name: document.getElementById('stripeOneTimeName').value.trim(),
            currency: document.getElementById('stripeOneTimeCurrency').value.trim().toLowerCase(),
            amountCents: Number(document.getElementById('stripeOneTimeAmount').value || 0)
        };

        statusEl.className = 'settings-status';
        statusEl.textContent = 'Creating Stripe product and default price...';
        createBtn.disabled = true;

        try {
            const res = await fetch(`/api/admin-analytics.php?action=stripe-create-one-time-product&token=${encodeURIComponent(sessionToken)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'ok') {
                throw new Error(data.error || 'Failed to create Stripe product');
            }

            renderStripeOneTimeConfig(data.config || {});
            statusEl.className = 'settings-status success';
            statusEl.textContent = 'Stripe product and price created.';
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message;
        } finally {
            createBtn.disabled = false;
        }
    }

    async function createStripeOneTimeSession() {
        const statusEl = document.getElementById('stripeOneTimeStatus');
        const createBtn = document.getElementById('stripeOneTimeCreateSessionBtn');

        statusEl.className = 'settings-status';
        statusEl.textContent = 'Creating Stripe Checkout Session...';
        createBtn.disabled = true;

        try {
            const res = await fetch(`/api/admin-analytics.php?action=stripe-create-one-time-checkout-session&token=${encodeURIComponent(sessionToken)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ priceId: stripeOneTimeConfigState?.priceId || '' })
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'ok') {
                throw new Error(data.error || 'Failed to create Checkout Session');
            }

            renderStripeOneTimeConfig(data.config || stripeOneTimeConfigState || {});
            statusEl.className = 'settings-status success';
            statusEl.textContent = 'Checkout Session created. Use Open Checkout to complete payment.';
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message;
        } finally {
            createBtn.disabled = false;
        }
    }

    function openStripeCheckoutUrl() {
        const url = document.getElementById('stripeOneTimeCheckoutUrl').value.trim();
        const statusEl = document.getElementById('stripeOneTimeStatus');

        if (!url) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = 'No checkout URL yet. Create a Checkout Session first.';
            return;
        }

        window.open(url, '_blank', 'noopener');
    }

    let runtimeConfigState = {};

    function deepClone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function readPath(obj, path) {
        return path.reduce((curr, key) => (curr && Object.prototype.hasOwnProperty.call(curr, key) ? curr[key] : undefined), obj);
    }

    function writePath(obj, path, value) {
        let curr = obj;
        for (let i = 0; i < path.length - 1; i += 1) {
            const key = path[i];
            if (!curr[key] || typeof curr[key] !== 'object') {
                curr[key] = {};
            }
            curr = curr[key];
        }
        curr[path[path.length - 1]] = value;
    }

    function collectScalarEntries(basePath, value, out) {
        if (value === null || value === undefined) {
            return;
        }

        if (typeof value === 'object' && !Array.isArray(value)) {
            Object.keys(value).forEach((key) => {
                collectScalarEntries(basePath.concat(key), value[key], out);
            });
            return;
        }

        out.push({
            path: basePath,
            value,
            type: typeof value
        });
    }

    function createRuntimeField(entry) {
        const wrap = document.createElement('div');
        wrap.className = 'runtime-field';

        const label = document.createElement('label');
        label.textContent = entry.path.slice(2).join(' · ');
        wrap.appendChild(label);

        const dataPath = entry.path.join('.');
        let input;

        if (entry.type === 'boolean') {
            input = document.createElement('select');
            input.innerHTML = '<option value="true">true</option><option value="false">false</option>';
            input.value = entry.value ? 'true' : 'false';
            input.dataset.runtimeType = 'boolean';
        } else {
            input = document.createElement('input');
            input.type = entry.type === 'number' ? 'number' : 'text';
            if (entry.type === 'number') {
                input.step = 'any';
            }
            input.value = String(entry.value);
            input.dataset.runtimeType = entry.type === 'number' ? 'number' : 'string';
        }

        input.dataset.runtimePath = dataPath;
        input.addEventListener('change', updateRuntimeStateFromFields);
        wrap.appendChild(input);
        return wrap;
    }

    function renderRuntimeFields(config) {
        const host = document.getElementById('runtimeConfigFields');
        if (!host) return;

        host.innerHTML = '';
        const groups = [
            { key: 'platform', title: 'Platform Variables' }
        ];

        const gameKeys = Object.keys(config.games || {}).sort();
        gameKeys.forEach((gameKey) => {
            groups.push({ key: gameKey, title: `Game: ${gameKey}`, isGame: true });
        });

        groups.forEach((group) => {
            const entries = [];
            const basePath = group.isGame ? ['games', group.key] : ['platform'];
            const source = group.isGame ? (config.games?.[group.key] || {}) : (config.platform || {});
            collectScalarEntries(basePath, source, entries);
            if (!entries.length) {
                return;
            }

            const card = document.createElement('div');
            card.className = 'runtime-group';

            const title = document.createElement('h3');
            title.textContent = group.title;
            card.appendChild(title);

            const grid = document.createElement('div');
            grid.className = 'runtime-field-grid';
            entries.sort((a, b) => a.path.join('.').localeCompare(b.path.join('.')));
            entries.forEach((entry) => {
                grid.appendChild(createRuntimeField(entry));
            });

            card.appendChild(grid);
            host.appendChild(card);
        });
    }

    function updateRuntimeStateFromFields() {
        const statusEl = document.getElementById('runtimeConfigStatus');
        const editor = document.getElementById('runtimeConfigEditor');
        if (!editor) return;

        const next = deepClone(runtimeConfigState);
        const fields = document.querySelectorAll('#runtimeConfigFields [data-runtime-path]');
        fields.forEach((field) => {
            const path = String(field.dataset.runtimePath || '').split('.');
            const type = String(field.dataset.runtimeType || 'string');
            let value = field.value;

            if (type === 'number') {
                value = Number(value);
            } else if (type === 'boolean') {
                value = value === 'true';
            }

            writePath(next, path, value);
        });

        runtimeConfigState = next;
        editor.value = JSON.stringify(runtimeConfigState, null, 2);
        if (statusEl && !statusEl.classList.contains('error')) {
            statusEl.className = 'settings-status';
            statusEl.textContent = 'Field changes staged. Save to apply.';
        }
    }

    function applyJsonToFields() {
        const statusEl = document.getElementById('runtimeConfigStatus');
        const editor = document.getElementById('runtimeConfigEditor');
        if (!editor || !statusEl) return;

        try {
            const parsed = JSON.parse(editor.value || '{}');
            runtimeConfigState = parsed;
            renderRuntimeFields(runtimeConfigState);
            editor.value = JSON.stringify(runtimeConfigState, null, 2);
            statusEl.className = 'settings-status success';
            statusEl.textContent = 'JSON applied to field editor.';
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = 'Invalid JSON: ' + err.message;
        }
    }

    async function loadRuntimeConfig() {
        const statusEl = document.getElementById('runtimeConfigStatus');
        const editor = document.getElementById('runtimeConfigEditor');
        if (!statusEl || !editor) return;

        statusEl.className = 'settings-status';
        statusEl.textContent = 'Loading runtime variables...';

        try {
            const data = await fetch_admin_api('runtime-config');
            const config = data.config || {};
            runtimeConfigState = deepClone(config);
            renderRuntimeFields(runtimeConfigState);
            editor.value = JSON.stringify(runtimeConfigState, null, 2);
            statusEl.className = 'settings-status success';
            statusEl.textContent = 'Runtime variables loaded.';
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = 'Failed to load runtime variables.';
            console.error('Runtime config load error:', err);
        }
    }

    async function saveRuntimeConfig() {
        const statusEl = document.getElementById('runtimeConfigStatus');
        const editor = document.getElementById('runtimeConfigEditor');
        const saveBtn = document.getElementById('saveRuntimeConfigBtn');
        if (!statusEl || !editor || !saveBtn) return;

        updateRuntimeStateFromFields();

        let parsed;
        try {
            parsed = JSON.parse(editor.value || '{}');
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = 'Invalid JSON: ' + err.message;
            return;
        }

        statusEl.className = 'settings-status';
        statusEl.textContent = 'Saving runtime variables...';
        saveBtn.disabled = true;

        try {
            const res = await fetch(`/api/admin-analytics.php?action=update-runtime-config&token=${encodeURIComponent(sessionToken)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ config: parsed })
            });

            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'ok') {
                throw new Error(data.error || 'Unable to save runtime variables');
            }

            runtimeConfigState = deepClone(data.config || parsed);
            renderRuntimeFields(runtimeConfigState);
            editor.value = JSON.stringify(runtimeConfigState, null, 2);
            statusEl.className = 'settings-status success';
            statusEl.textContent = 'Runtime variables saved.';
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message;
        } finally {
            saveBtn.disabled = false;
        }
    }

    // ── Boot ───────────────────────────────────────────────────────────────
    // PHP already decided what to show, just load data if dashboard is visible.
    <?php if ($show_dashboard): ?>
    loadDashboard();
    loadSuspiciousScores();
    loadAchievementLeaderboard();
    loadWebhookEvents();
    loadWebhookProxyConfig();
    loadPaymentProcessorsConfig();
    loadStripeOneTimeConfig();
    loadRuntimeConfig();
    setInterval(() => { loadDashboard(); loadSuspiciousScores(); loadWebhookEvents(); }, 30000);
    <?php endif; ?>
</script>
</body>
</html>
