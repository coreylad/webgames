<?php
declare(strict_types=1);

// ── Server-side gate ──────────────────────────────────────────────────────
$sessions_file = __DIR__ . '/../data/admin-sessions.json';
$admins_file   = __DIR__ . '/../data/admins.json';

$authed_user = null;
$user_role   = 'admin';
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
            $user_role   = (string)($sess['role'] ?? 'admin');
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
        .api-log-shell {
            background: #05080f;
            border: 1px solid rgba(96, 165, 250, 0.25);
            border-radius: 10px;
            padding: 0.75rem;
            margin-top: 0.75rem;
        }
        .api-log-window {
            margin: 0;
            min-height: 140px;
            max-height: 260px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.78rem;
            line-height: 1.5;
            color: #93c5fd;
            background: #03060b;
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 8px;
            padding: 0.75rem;
        }
        .save-toast {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 12000;
            background: rgba(16, 185, 129, 0.95);
            color: #ffffff;
            border: 1px solid rgba(134, 239, 172, 0.6);
            border-radius: 10px;
            padding: 0.7rem 1rem;
            font-size: 0.9rem;
            font-weight: 600;
            box-shadow: 0 10px 24px rgba(0,0,0,0.25);
            transform: translateY(-8px);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .save-toast.show {
            opacity: 1;
            transform: translateY(0);
        }
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
            <?php if ($user_role === 'admin'): ?>
            <button class="tab-btn" onclick="switchTab('moderation', event)">Moderation</button>
            <button class="tab-btn" onclick="switchTab('achievements', event)">Achievements</button>
            <button class="tab-btn" onclick="switchTab('payment-settings', event)">Payment Settings</button>
            <button class="tab-btn" onclick="switchTab('games', event)">Games</button>
            <button class="tab-btn" onclick="switchTab('webhooks', event)">Webhooks</button>
            <button class="tab-btn" onclick="switchTab('staff', event)">Staff</button>
            <?php endif; ?>
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
        <?php if ($user_role === 'admin'): ?>
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
        <?php endif; ?>

        <!-- Achievements -->
        <?php if ($user_role === 'admin'): ?>
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
        <?php endif; ?>

        <!-- Payment Settings -->
        <?php if ($user_role === 'admin'): ?>
        <div class="tab-content" id="payment-settings">
            <div class="section">
                <h2>Payment Processors</h2>
                <div class="settings-panel">
                    <p class="settings-note" style="margin-bottom:0.85rem;">
                        Choose the active checkout provider and configure Stripe credentials.
                    </p>
                    <p class="settings-note" style="margin-bottom:0.85rem;">
                        Stripe API keys are managed at Developers &rarr; API keys: publishable keys start with <strong>pk_</strong>, server keys start with <strong>sk_</strong> or <strong>rk_</strong>.
                        Webhook signing secrets start with <strong>whsec_</strong> and are managed at Developers &rarr; Webhooks for each endpoint.
                    </p>

                    <div class="settings-grid">
                        <div class="form-group">
                            <label for="activePaymentProcessor">Default Processor Fallback</label>
                            <select id="activePaymentProcessor">
                                <option value="stripe">Stripe</option>
                            </select>
                            <div class="settings-note">Stripe is the active checkout provider for tip payments.</div>
                        </div>
                    </div>

                    <h3 style="margin-top:1rem;margin-bottom:0.65rem;">Stripe Account Settings</h3>
                    <div class="settings-grid">
                        <div class="form-group">
                            <label for="stripeSecretKeyInput">Stripe Server API Key</label>
                            <input type="password" id="stripeSecretKeyInput" placeholder="sk_live_... or rk_live_..." />
                            <div class="settings-note">Use a Secret key (sk_) or Restricted key (rk_) from Stripe API keys.</div>
                        </div>
                        <div class="form-group">
                            <label for="stripePublishableKeyInput">Stripe Publishable Key</label>
                            <input type="text" id="stripePublishableKeyInput" placeholder="pk_live_..." />
                            <div class="settings-note">Client-side key from Stripe API keys (pk_).</div>
                        </div>
                        <div class="form-group">
                            <label for="stripeWebhookSecretInput">Stripe Webhook Signing Secret</label>
                            <input type="password" id="stripeWebhookSecretInput" placeholder="whsec_..." />
                            <div class="settings-note">Not an API key. Copy from Stripe Dashboard &rarr; Developers &rarr; Webhooks endpoint details.</div>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="stripeWebhookEndpointUrlInput">Stripe Webhook Endpoint URL (use this in Stripe)</label>
                            <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                                <input type="text" id="stripeWebhookEndpointUrlInput" readonly style="flex:1 1 460px;" />
                                <button class="btn" type="button" onclick="copyStripeWebhookEndpointUrl()">Copy URL</button>
                            </div>
                            <div class="settings-note">Stripe Dashboard &rarr; Developers &rarr; Webhooks &rarr; Add endpoint: paste this full URL.</div>
                            <div style="display:flex; gap:0.5rem; margin-top:0.45rem; flex-wrap:wrap; align-items:center;">
                                <input type="text" id="stripeWebhookEndpointPathInput" readonly style="flex:1 1 460px;" />
                                <button class="btn" type="button" onclick="copyStripeWebhookEndpointPath()">Copy Path</button>
                            </div>
                            <div class="settings-note">Path only for reverse proxies: <strong>/api/stripe-webhook.php</strong></div>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1; border: 1px solid rgba(102,126,234,0.35); border-radius: 10px; padding: 0.9rem; background: rgba(102,126,234,0.08);">
                            <label style="margin-bottom:0.5rem;">How To Add This Endpoint In Stripe</label>
                            <ol style="margin:0 0 0.9rem 1.1rem; padding:0; display:grid; gap:0.4rem;">
                                <li>Open Stripe Dashboard &rarr; <strong>Developers</strong> &rarr; <strong>Webhooks</strong>.</li>
                                <li>Click <strong>Add endpoint</strong> and paste the URL from <strong>Stripe Webhook Endpoint URL</strong> above.</li>
                                <li>Click <strong>Select events</strong> and include <strong>checkout.session.completed</strong>, <strong>checkout.session.expired</strong>, and <strong>charge.refunded</strong>.</li>
                                <li>Save endpoint, open it, then click <strong>Reveal</strong> on <strong>Signing secret</strong>.</li>
                                <li>Paste that signing secret into <strong>Stripe Webhook Signing Secret</strong> on this page, then click <strong>Save Payment Settings</strong>.</li>
                            </ol>
                            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                                <a class="btn" href="https://dashboard.stripe.com/webhooks" target="_blank" rel="noopener noreferrer">Open Stripe Webhooks</a>
                                <a class="btn" href="https://docs.stripe.com/webhooks" target="_blank" rel="noopener noreferrer">Stripe Webhooks Docs</a>
                            </div>
                            <div class="settings-note" style="margin-top:0.55rem;">Tip: configure both test mode and live mode endpoints in Stripe when you switch environments.</div>
                        </div>
                        <div class="form-group">
                            <label for="stripeTierProductIdsInput">Stripe Tier Product IDs</label>
                            <input type="text" id="stripeTierProductIdsInput" placeholder="prod_abc,prod_xyz" />
                            <div class="settings-note">Product IDs are optional and come from Stripe Product Catalog, not API keys.</div>
                        </div>
                        <div class="form-group">
                            <label for="stripeTierPriceIdsInput">Stripe Tier Price IDs</label>
                            <input type="text" id="stripeTierPriceIdsInput" placeholder="price_abc,price_xyz" />
                            <div class="settings-note">Price IDs are optional and come from Stripe Product Catalog, not API keys.</div>
                        </div>
                    </div>
                    <div class="wizard-nav" style="margin-top:0.2rem;">
                        <button class="btn" type="button" id="savePaymentProcessorsBtn" onclick="savePaymentProcessorsConfig()">Save Payment Settings</button>
                        <button class="btn btn-reject" type="button" id="resetStripeAccountBtn" onclick="resetStripeAccountConfig()">Reset Stripe Account</button>
                    </div>

                    <div class="settings-status" id="paymentProcessorsStatus"></div>
                </div>

                <h2>Stripe Backfill</h2>
                <div class="settings-panel">
                    <p class="settings-note" style="margin-bottom:0.85rem;">
                        Pull historical Stripe Checkout sessions and upsert them into local transaction data. Use this if webhook events were missed or old transactions are missing.
                    </p>

                    <div class="settings-grid">
                        <div class="form-group">
                            <label>Backfill Mode</label>
                            <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
                                <label style="display:flex;gap:0.35rem;align-items:center;">
                                    <input type="radio" name="stripeBackfillMode" value="full" checked />
                                    Full history
                                </label>
                                <label style="display:flex;gap:0.35rem;align-items:center;">
                                    <input type="radio" name="stripeBackfillMode" value="days" />
                                    Last
                                </label>
                                <input id="stripeBackfillDaysInput" type="number" min="1" max="3650" value="365" style="width:100px;" />
                                <span style="opacity:0.85;">days</span>
                            </div>
                        </div>
                    </div>

                    <div class="wizard-nav" style="margin-top:0.2rem;">
                        <button class="btn" type="button" id="stripeBackfillRunBtn" onclick="runStripeBackfillFromSettings()">Run Stripe Backfill</button>
                    </div>

                    <div class="settings-status" id="stripeBackfillStatus"></div>
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
                            <input type="text" id="stripeOneTimeCurrency" value="gbp" maxlength="3" />
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

                <h2>Payment API Log</h2>
                <div class="settings-panel">
                    <p class="settings-note" style="margin-bottom:0.7rem;">Live API error output for Payment Settings actions.</p>
                    <div class="wizard-nav" style="margin-top:0.2rem;">
                        <button class="btn" type="button" onclick="clearPaymentApiLog()">Clear Log</button>
                    </div>
                    <div class="api-log-shell">
                        <pre id="paymentApiLog" class="api-log-window">No API errors yet.</pre>
                    </div>
                </div>

            </div>
        </div>
        <?php endif; ?>

        <!-- Crypto Settings -->
        <?php if ($user_role === 'admin'): ?>
        <div class="tab-content" id="crypto-settings">
            <div class="section">
                <h2>Crypto Checkout Settings</h2>
                <div class="settings-panel">
                    <p class="settings-note" style="margin-bottom:0.85rem;">
                        Simple setup: choose coins, add addresses per coin, save, then test derivation.
                    </p>
                    <div class="settings-note" style="margin-bottom:0.85rem;">
                        No JSON editing required in this tab.
                    </div>
                    <div class="settings-note" style="margin-bottom:0.85rem;color:#9fd7ff;">
                        BTCPay hosted checkout is now the primary crypto flow. Configure BTCPay first, then save.
                    </div>

                    <div class="settings-grid">
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="btcpayServerUrlInput">BTCPay Server URL</label>
                            <input type="url" id="btcpayServerUrlInput" placeholder="https://btcpay.example.com" />
                        </div>
                        <div class="form-group">
                            <label for="btcpayStoreIdInput">BTCPay Store ID</label>
                            <input type="text" id="btcpayStoreIdInput" placeholder="store id" />
                        </div>
                        <div class="form-group">
                            <label for="btcpayApiKeyInput">BTCPay API Key</label>
                            <input type="password" id="btcpayApiKeyInput" placeholder="token generated in BTCPay" />
                        </div>
                        <div class="form-group">
                            <label for="btcpayWebhookSecretInput">BTCPay Webhook Secret (optional)</label>
                            <input type="password" id="btcpayWebhookSecretInput" placeholder="webhook secret" />
                        </div>
                        <div class="form-group">
                            <label for="coinbaseTipAmountsInput">Local Tip Amounts</label>
                            <input type="text" id="coinbaseTipAmountsInput" placeholder="5,10,20" />
                        </div>
                        <div class="form-group">
                            <label for="coinbaseCurrencyInput">Local Currency</label>
                            <input type="text" id="coinbaseCurrencyInput" placeholder="GBP" maxlength="3" />
                        </div>
                        <div class="form-group">
                            <label for="coinbaseSupportedCoinsInput">Supported Coins</label>
                            <input type="text" id="coinbaseSupportedCoinsInput" placeholder="BTC,ETH,LTC,BCH,DOGE,USDC,USDT,XRP" onblur="rebuildCoinAddressRows()" />
                            <div class="settings-note">Comma-separated symbols. Changing this rebuilds the address fields below.</div>
                        </div>
                        <div class="form-group">
                            <label for="cryptoAssetInput">Default Coin</label>
                            <input type="text" id="cryptoAssetInput" placeholder="USDC" maxlength="12" />
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.6rem;">
                                <strong style="color:#2ae8c7;">Receive Addresses</strong>
                                <span style="font-size:0.78rem;opacity:0.65;">Where users send their tips — your on-site wallets</span>
                            </div>
                            <div style="display:flex;justify-content:flex-end;margin-bottom:0.45rem;">
                                <button class="btn btn-sm" type="button" onclick="autofillReceiveAddressRows()">Autofill Empty Receive Rows</button>
                            </div>
                            <div id="cryptoReceiveAddressRows" style="display:grid;gap:0.45rem;"></div>
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="cryptoReceiveAddressInput">Fallback Receive Address</label>
                            <input type="text" id="cryptoReceiveAddressInput" placeholder="Used for any coin not listed above" />
                            <div class="settings-note">Optional. Used when a coin has no specific address set above.</div>
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.6rem;">
                                <strong style="color:#4b7fff;">Coinbase Destination Addresses</strong>
                                <span style="font-size:0.78rem;opacity:0.65;">Your Coinbase deposit addresses — where you withdraw to</span>
                            </div>
                            <div style="display:flex;justify-content:flex-end;margin-bottom:0.45rem;">
                                <button class="btn btn-sm" type="button" onclick="autofillDestinationAddressRows()">Autofill Empty Destination Rows</button>
                            </div>
                            <div id="cryptoDestinationAddressRows" style="display:grid;gap:0.45rem;"></div>
                        </div>
                        <div class="form-group">
                            <label for="coinbaseDestinationAccountInput">Coinbase Destination (legacy fallback)</label>
                            <input type="text" id="coinbaseDestinationAccountInput" placeholder="Coinbase account email or deposit address" />
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="coinbaseTransferRequestUrlInput">Transfer Relay URL (optional)</label>
                            <input type="url" id="coinbaseTransferRequestUrlInput" placeholder="https://relay.example/coinbase-transfer" />
                            <div class="settings-note">When set, the Request Transfer action posts to this URL so your relay can execute the Coinbase transfer.</div>
                        </div>
                        <div class="form-group">
                            <label for="coinbaseTransferAuthHeaderInput">Transfer Relay Auth Header</label>
                            <input type="text" id="coinbaseTransferAuthHeaderInput" placeholder="x-coinbase-transfer-token" />
                        </div>
                        <div class="form-group">
                            <label for="coinbaseTransferAuthTokenInput">Transfer Relay Auth Token</label>
                            <input type="password" id="coinbaseTransferAuthTokenInput" placeholder="shared-secret" />
                        </div>
                        <div class="form-group">
                            <label for="coinbaseApiKeyInput">Legacy Coinbase API Key (optional)</label>
                            <input type="password" id="coinbaseApiKeyInput" placeholder="Legacy only" />
                        </div>
                        <div class="form-group">
                            <label for="coinbaseWebhookSecretInput">Legacy Coinbase Webhook Secret (optional)</label>
                            <input type="password" id="coinbaseWebhookSecretInput" placeholder="Legacy only" />
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1; border: 1px solid rgba(42,232,199,0.35); border-radius: 10px; padding: 0.8rem; background: rgba(42,232,199,0.06);">
                            <label style="display:flex;gap:0.45rem;align-items:center;cursor:pointer;">
                                <input type="checkbox" id="addressDerivationEnabledInput" />
                                Enable per-tip auto-generated wallet addresses
                            </label>
                            <div class="settings-note" style="margin-top:0.35rem;">When enabled, this site requests unique receive addresses from your wallet service for each new crypto tip session.</div>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="addressDerivationUrlInput">Address Derivation Service URL</label>
                            <input type="url" id="addressDerivationUrlInput" placeholder="https://wallet-service.example/api/derive-addresses" />
                        </div>
                        <div class="form-group">
                            <label for="addressDerivationAuthHeaderInput">Derivation Auth Header</label>
                            <input type="text" id="addressDerivationAuthHeaderInput" placeholder="x-webgames-wallet-token" />
                        </div>
                        <div class="form-group">
                            <label for="addressDerivationAuthTokenInput">Derivation Auth Token</label>
                            <input type="password" id="addressDerivationAuthTokenInput" placeholder="shared secret token" />
                        </div>
                        <div class="form-group">
                            <label for="walletServicePortInput">Wallet Service Port</label>
                            <input type="text" id="walletServicePortInput" placeholder="8787" />
                        </div>
                        <div class="form-group">
                            <label for="walletTaggedCoinsInput">Coins Using Destination Tag/Memo</label>
                            <input type="text" id="walletTaggedCoinsInput" placeholder="XRP" />
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.6rem;">
                                <strong style="color:#67e8f9;">Wallet Base Addresses (required for derivation)</strong>
                                <span style="font-size:0.78rem;opacity:0.65;">Used by wallet-service for per-tip address/tag derivation</span>
                            </div>
                            <div style="display:flex;justify-content:flex-end;margin-bottom:0.45rem;">
                                <button class="btn btn-sm" type="button" onclick="autofillWalletBaseAddressRows()">Autofill Empty Base Rows</button>
                            </div>
                            <div id="walletBaseAddressRows" style="display:grid;gap:0.45rem;"></div>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="walletDerivationSecretInput">Wallet Derivation Secret</label>
                            <input type="password" id="walletDerivationSecretInput" placeholder="high entropy secret used to derive unique tags/refs" />
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1; border: 1px solid rgba(75,127,255,0.35); border-radius: 10px; padding: 0.8rem; background: rgba(75,127,255,0.08);">
                            <label style="display:flex;gap:0.45rem;align-items:center;cursor:pointer;">
                                <input type="checkbox" id="autoVerifyEnabledInput" />
                                Enable automatic on-chain verification worker
                            </label>
                            <div class="settings-note" style="margin-top:0.35rem;">When enabled, wallet-service polls pending submitted tx hashes, calls your verifier provider, and auto-confirms paid tips.</div>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="autoVerifyProviderUrlInput">Auto Verify Provider URL</label>
                            <input type="url" id="autoVerifyProviderUrlInput" placeholder="https://your-verifier.example/api/verify" />
                        </div>
                        <div class="form-group">
                            <label for="autoVerifyAuthHeaderInput">Auto Verify Auth Header</label>
                            <input type="text" id="autoVerifyAuthHeaderInput" placeholder="x-webgames-verify-token" />
                        </div>
                        <div class="form-group">
                            <label for="autoVerifyAuthTokenInput">Auto Verify Auth Token</label>
                            <input type="password" id="autoVerifyAuthTokenInput" placeholder="shared secret for verifier" />
                        </div>
                        <div class="form-group">
                            <label for="autoVerifyMinConfirmationsInput">Min Confirmations</label>
                            <input type="number" id="autoVerifyMinConfirmationsInput" min="1" max="1000" value="1" />
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="walletAppInternalBaseUrlInput">Internal App Base URL (wallet-service)</label>
                            <input type="url" id="walletAppInternalBaseUrlInput" placeholder="http://127.0.0.1" />
                        </div>
                    </div>

                    <div class="wizard-nav" style="margin-top:0.2rem;">
                        <button class="btn" type="button" onclick="savePaymentProcessorsConfig()">Save Crypto Settings</button>
                        <button class="btn" type="button" onclick="testCryptoDerivation()">Test Wallet Derivation</button>
                        <button class="btn" type="button" onclick="loadCryptoServiceHealth()">Refresh Service Status</button>
                    </div>
                    <div class="settings-status" id="cryptoSettingsStatus"></div>

                    <div class="settings-panel" style="margin-top:0.9rem;padding:0.95rem;border:1px solid rgba(103,232,249,0.35);background:rgba(103,232,249,0.07);">
                        <h3 style="margin-bottom:0.6rem;">Crypto Service Health</h3>
                        <div class="settings-grid" style="margin-bottom:0.35rem;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label>Derivation Service</label>
                                <div id="cryptoDerivationHealthBadge" class="status-badge status-degraded">Checking...</div>
                                <div class="settings-note" id="cryptoDerivationHealthMeta">No data yet.</div>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label>Auto Verification Worker</label>
                                <div id="cryptoAutoVerifyHealthBadge" class="status-badge status-degraded">Checking...</div>
                                <div class="settings-note" id="cryptoAutoVerifyHealthMeta">No data yet.</div>
                            </div>
                        </div>
                        <div class="settings-note" id="cryptoServiceHealthInfo">Health URL: pending...</div>
                    </div>
                </div>

                <h2>Crypto Wallets</h2>
                <div class="settings-panel">
                    <p class="settings-note" style="margin-bottom:0.85rem;">
                        Overview of each configured coin wallet. Receive address is where users send tips. Coinbase destination is where you withdraw to. Confirmed balance is based on on-site confirmed tips.
                    </p>
                    <div class="wizard-nav" style="margin-top:0.2rem;margin-bottom:0.65rem;">
                        <button class="btn" type="button" onclick="loadWalletOverview()">Reload Wallets</button>
                    </div>
                    <div id="walletOverviewGrid" style="display:grid;gap:0.85rem;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));margin-top:0.5rem;">
                        <p style="opacity:0.7">No wallet data loaded yet.</p>
                    </div>
                    <div class="settings-status" id="walletOverviewStatus"></div>
                </div>

                <h2>Crypto Transfer Queue</h2>
                <div class="settings-panel">
                    <p class="settings-note" style="margin-bottom:0.85rem;">
                        Local crypto tips are received on-site. Confirm incoming hashes, then request transfer to Coinbase from here.
                    </p>
                    <div class="wizard-nav" style="margin-top:0.2rem;margin-bottom:0.65rem;">
                        <button class="btn" type="button" onclick="loadCryptoTransferQueue()">Reload Queue</button>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Created</th>
                                    <th>User</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Tx Hash</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="cryptoTransferTbody">
                                <tr><td colspan="6">No crypto records loaded yet.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="settings-status" id="cryptoTransferStatus"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Games -->
        <?php if ($user_role === 'admin'): ?>
        <div class="tab-content" id="games">
            <div class="section">
                <h2>Games</h2>
                <div class="settings-panel">
                    <p class="settings-note" style="margin-bottom:0.85rem;">
                        Edit all game variables here. Values are persisted to data/runtime-config.json.
                    </p>
                    <div id="runtimeConfigFields" class="runtime-groups"></div>
                    <div class="wizard-nav" style="margin-top:0.2rem;">
                        <button class="btn" type="button" id="reloadRuntimeConfigBtn" onclick="loadRuntimeConfig()">Reload</button>
                        <button class="btn" type="button" id="applyJsonToFieldsBtn" onclick="applyJsonToFields()">Apply JSON To Fields</button>
                        <button class="btn" type="button" id="saveRuntimeConfigBtn" onclick="saveRuntimeConfig()">Save Game Variables</button>
                    </div>
                    <div class="runtime-json-wrap form-group" style="margin-bottom:0.9rem;">
                        <label for="runtimeConfigEditor">Game Variables JSON</label>
                        <textarea id="runtimeConfigEditor" rows="22" spellcheck="false" style="width:100%;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:8px;padding:0.8rem 0.9rem;color:#e0e6ed;font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;font-size:0.84rem;line-height:1.45;"></textarea>
                    </div>
                    <div class="settings-status" id="runtimeConfigStatus"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Webhooks -->
        <?php if ($user_role === 'admin'): ?>
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

        <!-- Staff -->
        <div class="tab-content" id="staff">
            <div class="section">
                <h2>Staff Management</h2>
                <div class="settings-panel">
                    <h3 style="margin-bottom:1rem;">Add New Staff Member</h3>
                    <div style="display:grid; gap:1rem; margin-bottom:1.5rem;">
                        <div>
                            <label for="newStaffUsername" style="display:block; margin-bottom:0.5rem; opacity:0.7; font-size:0.85rem; text-transform:uppercase; letter-spacing:0.5px;">Username</label>
                            <input type="text" id="newStaffUsername" placeholder="3-24 chars (lowercase, numbers, _, -)" style="width:100%; padding:0.75rem; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); border-radius:8px; color:#e0e6ed; font-size:0.9rem;">
                        </div>
                        <div>
                            <label for="newStaffPassword" style="display:block; margin-bottom:0.5rem; opacity:0.7; font-size:0.85rem; text-transform:uppercase; letter-spacing:0.5px;">Password</label>
                            <input type="password" id="newStaffPassword" placeholder="Min 8 characters" style="width:100%; padding:0.75rem; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); border-radius:8px; color:#e0e6ed; font-size:0.9rem;">
                        </div>
                        <div>
                            <label for="newStaffRole" style="display:block; margin-bottom:0.5rem; opacity:0.7; font-size:0.85rem; text-transform:uppercase; letter-spacing:0.5px;">Role</label>
                            <select id="newStaffRole" style="width:100%; padding:0.75rem; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); border-radius:8px; color:#e0e6ed; font-size:0.9rem;">
                                <option value="admin">Admin (Full Access)</option>
                                <option value="mod" selected>Mod (Overview Only)</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" id="addStaffBtn" onclick="handleAddStaffMember()" style="width:100%;">Add Staff Member</button>
                    </div>

                    <div id="staffStatusMessage" style="margin-bottom:1rem; padding:0.75rem; border-radius:8px; display:none;"></div>

                    <h3 style="margin-bottom:1rem; margin-top:2rem;">Current Staff</h3>
                    <div class="table-responsive">
                        <table>
                            <thead><tr><th>Username</th><th>Role</th><th>Created</th></tr></thead>
                            <tbody id="staffListTbody"><tr><td colspan="3" class="loading">Loading staff list...</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Token comes from cookie (set on login) — PHP already validated it server-side.
    // We still need it client-side to pass to the API endpoints.
    let sessionToken = getCookie('admin_token');
    let sessionUsername = <?= json_encode($authed_user) ?>;
    let userRole = <?= json_encode($user_role) ?>;
    let sessionForcedLogout = false;
    let sessionValidationInFlight = false;
    let saveToastTimeoutId = null;
    const OVERVIEW_POLL_ACTIVE_MS = 300000;
    let overviewPollingTimerId = null;
    let overviewRequestInFlight = false;
    let adminEventsSource = null;
    let lastAdminSignalId = 0;

    function showSaveToast(message, isError = false) {
        let toast = document.getElementById('saveToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'saveToast';
            toast.className = 'save-toast';
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.style.background = isError ? 'rgba(239, 68, 68, 0.95)' : 'rgba(16, 185, 129, 0.95)';
        toast.style.borderColor = isError ? 'rgba(252, 165, 165, 0.6)' : 'rgba(134, 239, 172, 0.6)';
        toast.classList.add('show');

        if (saveToastTimeoutId) {
            clearTimeout(saveToastTimeoutId);
        }

        saveToastTimeoutId = setTimeout(() => {
            toast.classList.remove('show');
        }, 2600);
    }

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

    async function validateCurrentSession() {
        if (!sessionToken) {
            return false;
        }

        if (sessionValidationInFlight) {
            return true;
        }

        sessionValidationInFlight = true;
        try {
            const response = await fetch('/api/admin-login.php?action=validate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: sessionToken })
            });

            return response.status === 200;
        } catch (err) {
            console.error('Session validation failed:', err);
            return false;
        } finally {
            sessionValidationInFlight = false;
        }
    }

    // ── Tabs ───────────────────────────────────────────────────────────────
    function switchTab(tabName, event) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById(tabName).classList.add('active');
        if (event) event.target.classList.add('active');

        if (tabName === 'overview') {
            refreshOverviewTick();
        }
    }

    function isOverviewActive() {
        const overviewTab = document.getElementById('overview');
        return !!overviewTab && overviewTab.classList.contains('active');
    }

    async function refreshOverviewTick(force = false) {
        if (document.hidden || overviewRequestInFlight) {
            return;
        }

        if (!force && !isOverviewActive()) {
            return;
        }

        overviewRequestInFlight = true;
        try {
            await loadDashboard();
        } finally {
            overviewRequestInFlight = false;
        }
    }

    function startOverviewPolling() {
        if (overviewPollingTimerId !== null) {
            return;
        }

        overviewPollingTimerId = setInterval(() => {
            refreshOverviewTick();
        }, OVERVIEW_POLL_ACTIVE_MS);
    }

    function stopAdminEventsStream() {
        if (adminEventsSource !== null) {
            adminEventsSource.close();
            adminEventsSource = null;
        }
    }

    function startAdminEventsStream() {
        if (!window.EventSource || adminEventsSource !== null || !sessionToken) {
            return;
        }

        const url = new URL('/api/admin-events.php', location.origin);
        url.searchParams.set('token', sessionToken);
        if (lastAdminSignalId > 0) {
            url.searchParams.set('since', String(lastAdminSignalId));
        }

        adminEventsSource = new EventSource(url.toString());

        adminEventsSource.addEventListener('payment_received', async (evt) => {
            try {
                const payload = JSON.parse(evt.data || '{}');
                const eventId = Number(payload.id ?? 0);
                if (eventId > 0) {
                    lastAdminSignalId = eventId;
                }
            } catch (err) {
                console.warn('Unable to parse payment_received event payload', err);
            }

            await refreshOverviewTick(true);
        });

        adminEventsSource.addEventListener('heartbeat', () => {
            // no-op; keeps connection healthy and reconnect loop moving
        });

        adminEventsSource.onerror = () => {
            stopAdminEventsStream();
            if (!document.hidden) {
                setTimeout(() => {
                    startAdminEventsStream();
                }, 3000);
            }
        };
    }

    // ── API helper ─────────────────────────────────────────────────────────
    async function fetch_admin_api(action, params = {}) {
        const url = new URL('/api/admin-analytics.php', location.origin);
        url.searchParams.set('action', action);
        url.searchParams.set('token', sessionToken);
        Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

        const res = await fetch(url);
        if (res.status === 401) {
            const stillValid = await validateCurrentSession();
            if (!stillValid) {
                await handleSessionExpired();
            }
            return {};
        }
        return res.json();
    }

    function appendPaymentApiLog(level, source, message, payload = null) {
        const logEl = document.getElementById('paymentApiLog');
        if (!logEl) {
            return;
        }

        const ts = new Date().toLocaleTimeString();
        const lines = [];
        lines.push(`[${ts}] ${String(level).toUpperCase()} ${source}: ${message}`);
        if (payload !== null && payload !== undefined) {
            try {
                lines.push(JSON.stringify(payload, null, 2));
            } catch (err) {
                lines.push(String(payload));
            }
        }

        const block = lines.join('\n');
        const current = (logEl.textContent || '').trim();
        const base = current === 'No API errors yet.' ? '' : current;
        const next = (base ? `${base}\n\n${block}` : block)
            .split('\n')
            .slice(-260)
            .join('\n');

        logEl.textContent = next;
        logEl.scrollTop = logEl.scrollHeight;
    }

    function logPaymentApiError(source, err, payload = null) {
        const msg = err instanceof Error ? err.message : String(err || 'Unknown error');
        appendPaymentApiLog('error', source, msg, payload);
    }

    function clearPaymentApiLog() {
        const logEl = document.getElementById('paymentApiLog');
        if (!logEl) {
            return;
        }
        logEl.textContent = 'No API errors yet.';
    }

    function populateStripeWebhookEndpointHelper() {
        const endpointPath = '/api/stripe-webhook.php';
        const endpointUrl = `${location.origin}${endpointPath}`;

        const endpointUrlInput = document.getElementById('stripeWebhookEndpointUrlInput');
        const endpointPathInput = document.getElementById('stripeWebhookEndpointPathInput');

        if (endpointUrlInput) {
            endpointUrlInput.value = endpointUrl;
        }
        if (endpointPathInput) {
            endpointPathInput.value = endpointPath;
        }
    }

    async function copyEndpointValue(inputId, successMessage) {
        const inputEl = document.getElementById(inputId);
        const value = inputEl ? String(inputEl.value || '').trim() : '';
        if (!value) {
            return;
        }

        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(value);
                showSaveToast(successMessage);
                return;
            }
        } catch (err) {
            // Fall back to selecting text for manual copy.
        }

        if (inputEl) {
            inputEl.focus();
            inputEl.select();
        }
        showSaveToast('Clipboard blocked. Endpoint selected, press Ctrl+C.', true);
    }

    async function copyStripeWebhookEndpointUrl() {
        await copyEndpointValue('stripeWebhookEndpointUrlInput', 'Stripe webhook URL copied.');
    }

    async function copyStripeWebhookEndpointPath() {
        await copyEndpointValue('stripeWebhookEndpointPathInput', 'Stripe webhook path copied.');
    }

    let paymentProcessorConfigState = null;

    function renderPaymentProcessorsConfig(config) {
        paymentProcessorConfigState = config || null;

        const activeProcessor = 'stripe';
        const btcpayConfig = config?.btcpay || config?.coinbase || {};
        document.getElementById('activePaymentProcessor').value = activeProcessor;

        document.getElementById('stripeSecretKeyInput').value = config?.stripe?.secretKey || '';
        document.getElementById('stripePublishableKeyInput').value = config?.stripe?.publishableKey || '';
        document.getElementById('stripeWebhookSecretInput').value = config?.stripe?.webhookSecret || '';
        document.getElementById('stripeTierProductIdsInput').value = config?.stripe?.tierProductIds || '';
        document.getElementById('stripeTierPriceIdsInput').value = config?.stripe?.tierPriceIds || '';

        document.getElementById('coinbaseTipAmountsInput').value = btcpayConfig?.tipAmounts || '5,10,20';
        document.getElementById('coinbaseCurrencyInput').value = btcpayConfig?.currency || 'GBP';
        document.getElementById('coinbaseSupportedCoinsInput').value = btcpayConfig?.supportedCoins || 'BTC,ETH,LTC,BCH,DOGE,USDC,USDT,XRP';
        document.getElementById('btcpayServerUrlInput').value = btcpayConfig?.btcpayServerUrl || '';
        document.getElementById('btcpayStoreIdInput').value = btcpayConfig?.btcpayStoreId || '';
        document.getElementById('btcpayApiKeyInput').value = btcpayConfig?.btcpayApiKey || '';
        document.getElementById('btcpayWebhookSecretInput').value = btcpayConfig?.btcpayWebhookSecret || '';
        document.getElementById('cryptoAssetInput').value = btcpayConfig?.cryptoAsset || 'USDC';
        document.getElementById('cryptoReceiveAddressInput').value = btcpayConfig?.receiveAddress || '';
        document.getElementById('coinbaseDestinationAccountInput').value = btcpayConfig?.destinationAccount || '';

        // Build per-coin address rows from config
        const receiveDataFromJson = tryParseJson(btcpayConfig?.receiveAddressesJson || '{}');
        const receiveDataResolved = (btcpayConfig?.receiveAddresses && typeof btcpayConfig.receiveAddresses === 'object')
            ? btcpayConfig.receiveAddresses
            : {};
        const receiveData = Object.keys(receiveDataFromJson).length > 0 ? receiveDataFromJson : receiveDataResolved;

        const destinationDataFromJson = tryParseJson(btcpayConfig?.destinationAddressesJson || '{}');
        const destinationDataResolved = (btcpayConfig?.destinationAddresses && typeof btcpayConfig.destinationAddresses === 'object')
            ? btcpayConfig.destinationAddresses
            : {};
        const destinationData = Object.keys(destinationDataFromJson).length > 0 ? destinationDataFromJson : destinationDataResolved;
        const walletBaseData = tryParseJson(btcpayConfig?.walletBaseAddressesJson || '{}');

        buildCoinAddressRows('cryptoReceiveAddressRows', receiveData, 'Your');
        buildCoinAddressRows('cryptoDestinationAddressRows', destinationData, 'Coinbase');
        buildCoinAddressRows('walletBaseAddressRows', walletBaseData, 'Wallet base');
        autofillReceiveAddressRows();
        document.getElementById('coinbaseTransferRequestUrlInput').value = btcpayConfig?.transferRequestUrl || '';
        document.getElementById('coinbaseTransferAuthHeaderInput').value = btcpayConfig?.transferAuthHeader || 'x-coinbase-transfer-token';
        document.getElementById('coinbaseTransferAuthTokenInput').value = btcpayConfig?.transferAuthToken || '';
        document.getElementById('coinbaseApiKeyInput').value = btcpayConfig?.apiKey || '';
        document.getElementById('coinbaseWebhookSecretInput').value = btcpayConfig?.webhookSecret || '';
        document.getElementById('addressDerivationEnabledInput').checked = !!btcpayConfig?.addressDerivationEnabled;
        document.getElementById('addressDerivationUrlInput').value = btcpayConfig?.addressDerivationUrl || '';
        document.getElementById('addressDerivationAuthHeaderInput').value = btcpayConfig?.addressDerivationAuthHeader || 'x-webgames-wallet-token';
        document.getElementById('addressDerivationAuthTokenInput').value = btcpayConfig?.addressDerivationAuthToken || '';
        document.getElementById('walletServicePortInput').value = btcpayConfig?.walletServicePort || '8787';
        document.getElementById('walletTaggedCoinsInput').value = btcpayConfig?.walletTaggedCoins || 'XRP';
        document.getElementById('walletDerivationSecretInput').value = btcpayConfig?.walletDerivationSecret || '';
        document.getElementById('autoVerifyEnabledInput').checked = !!btcpayConfig?.autoVerifyEnabled;
        document.getElementById('autoVerifyProviderUrlInput').value = btcpayConfig?.autoVerifyProviderUrl || '';
        document.getElementById('autoVerifyAuthHeaderInput').value = btcpayConfig?.autoVerifyAuthHeader || 'x-webgames-verify-token';
        document.getElementById('autoVerifyAuthTokenInput').value = btcpayConfig?.autoVerifyAuthToken || '';
        document.getElementById('autoVerifyMinConfirmationsInput').value = Number(btcpayConfig?.autoVerifyMinConfirmations || 1);
        document.getElementById('walletAppInternalBaseUrlInput').value = btcpayConfig?.walletAppInternalBaseUrl || 'http://127.0.0.1';
    }

    async function loadPaymentProcessorsConfig() {
        const statusEl = document.getElementById('paymentProcessorsStatus');
        const cryptoStatusEl = document.getElementById('cryptoSettingsStatus');
        if (statusEl) {
            statusEl.className = 'settings-status';
            statusEl.textContent = 'Loading payment settings...';
        }
        if (cryptoStatusEl) {
            cryptoStatusEl.className = 'settings-status';
            cryptoStatusEl.textContent = 'Loading payment settings...';
        }
        populateStripeWebhookEndpointHelper();

        try {
            const data = await fetch_admin_api('payment-processors-config');
            const config = data.config || {};
            renderPaymentProcessorsConfig(config);
            if (statusEl) {
                statusEl.className = 'settings-status success';
                statusEl.textContent = 'Payment settings loaded.';
            }
            if (cryptoStatusEl) {
                cryptoStatusEl.className = 'settings-status success';
                cryptoStatusEl.textContent = 'Crypto settings loaded.';
            }
        } catch (err) {
            if (statusEl) {
                statusEl.className = 'settings-status error';
                statusEl.textContent = 'Unable to load payment settings.';
            }
            if (cryptoStatusEl) {
                cryptoStatusEl.className = 'settings-status error';
                cryptoStatusEl.textContent = 'Unable to load crypto settings.';
            }
            logPaymentApiError('payment-processors-config', err);
            console.error('Payment settings load error:', err);
        }
    }

    async function savePaymentProcessorsConfig() {
        const statusEl = document.getElementById('paymentProcessorsStatus');
        const cryptoStatusEl = document.getElementById('cryptoSettingsStatus');
        const saveBtn = document.getElementById('savePaymentProcessorsBtn');
        const setStatus = (className, text) => {
            if (statusEl) {
                statusEl.className = className;
                statusEl.textContent = text;
            }
            if (cryptoStatusEl) {
                cryptoStatusEl.className = className;
                cryptoStatusEl.textContent = text;
            }
        };

        const payload = {
            activeProcessor: String(document.getElementById('activePaymentProcessor').value || 'stripe').toLowerCase(),
            stripe: {
                secretKey: document.getElementById('stripeSecretKeyInput').value.trim(),
                publishableKey: document.getElementById('stripePublishableKeyInput').value.trim(),
                webhookSecret: document.getElementById('stripeWebhookSecretInput').value.trim(),
                tierProductIds: document.getElementById('stripeTierProductIdsInput').value.trim(),
                tierPriceIds: document.getElementById('stripeTierPriceIdsInput').value.trim()
            },
            btcpay: {
                btcpayServerUrl: document.getElementById('btcpayServerUrlInput').value.trim(),
                btcpayStoreId: document.getElementById('btcpayStoreIdInput').value.trim(),
                btcpayApiKey: document.getElementById('btcpayApiKeyInput').value.trim(),
                btcpayWebhookSecret: document.getElementById('btcpayWebhookSecretInput').value.trim(),
                tipAmounts: document.getElementById('coinbaseTipAmountsInput').value.trim(),
                currency: String(document.getElementById('coinbaseCurrencyInput').value || 'GBP').toUpperCase(),
                supportedCoins: String(document.getElementById('coinbaseSupportedCoinsInput').value || 'BTC,ETH,LTC,BCH,DOGE,USDC,USDT,XRP').toUpperCase(),
                receiveAddressesJson: collectCoinAddressJson('cryptoReceiveAddressRows'),
                cryptoAsset: String(document.getElementById('cryptoAssetInput').value || 'USDC').toUpperCase(),
                receiveAddress: document.getElementById('cryptoReceiveAddressInput').value.trim(),
                destinationAddressesJson: collectCoinAddressJson('cryptoDestinationAddressRows'),
                destinationAccount: document.getElementById('coinbaseDestinationAccountInput').value.trim(),
                transferRequestUrl: document.getElementById('coinbaseTransferRequestUrlInput').value.trim(),
                transferAuthHeader: document.getElementById('coinbaseTransferAuthHeaderInput').value.trim(),
                transferAuthToken: document.getElementById('coinbaseTransferAuthTokenInput').value.trim(),
                apiKey: document.getElementById('coinbaseApiKeyInput').value.trim(),
                webhookSecret: document.getElementById('coinbaseWebhookSecretInput').value.trim(),
                addressDerivationEnabled: document.getElementById('addressDerivationEnabledInput').checked,
                addressDerivationUrl: document.getElementById('addressDerivationUrlInput').value.trim(),
                addressDerivationAuthHeader: document.getElementById('addressDerivationAuthHeaderInput').value.trim(),
                addressDerivationAuthToken: document.getElementById('addressDerivationAuthTokenInput').value.trim(),
                walletServicePort: document.getElementById('walletServicePortInput').value.trim(),
                walletTaggedCoins: String(document.getElementById('walletTaggedCoinsInput').value || 'XRP').toUpperCase(),
                walletBaseAddressesJson: collectCoinAddressJson('walletBaseAddressRows'),
                walletDerivationSecret: document.getElementById('walletDerivationSecretInput').value.trim(),
                autoVerifyEnabled: document.getElementById('autoVerifyEnabledInput').checked,
                autoVerifyProviderUrl: document.getElementById('autoVerifyProviderUrlInput').value.trim(),
                autoVerifyAuthHeader: document.getElementById('autoVerifyAuthHeaderInput').value.trim(),
                autoVerifyAuthToken: document.getElementById('autoVerifyAuthTokenInput').value.trim(),
                autoVerifyMinConfirmations: String(document.getElementById('autoVerifyMinConfirmationsInput').value || '1').trim(),
                walletAppInternalBaseUrl: document.getElementById('walletAppInternalBaseUrlInput').value.trim()
            }
        };

        setStatus('settings-status', 'Saving payment settings...');
        if (saveBtn) {
            saveBtn.disabled = true;
        }

        const publishableKey = payload.stripe.publishableKey;
        const serverKey = payload.stripe.secretKey;
        const webhookSecret = payload.stripe.webhookSecret;

        if (publishableKey !== '' && !/^pk_(test|live)_[A-Za-z0-9]+$/.test(publishableKey)) {
            const err = new Error('Stripe publishable key must start with pk_test_ or pk_live_.');
            setStatus('settings-status error', err.message);
            logPaymentApiError('stripe-key-validation', err, { field: 'publishableKey' });
            if (saveBtn) {
                saveBtn.disabled = false;
            }
            return;
        }

        if (serverKey !== '' && !/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/.test(serverKey)) {
            const err = new Error('Stripe server key must start with sk_test_, sk_live_, rk_test_, or rk_live_.');
            setStatus('settings-status error', err.message);
            logPaymentApiError('stripe-key-validation', err, { field: 'serverKey' });
            if (saveBtn) {
                saveBtn.disabled = false;
            }
            return;
        }

        if (webhookSecret !== '' && !/^whsec_[A-Za-z0-9]+$/.test(webhookSecret)) {
            const err = new Error('Webhook signing secret must start with whsec_.');
            setStatus('settings-status error', err.message);
            logPaymentApiError('stripe-key-validation', err, { field: 'webhookSecret' });
            if (saveBtn) {
                saveBtn.disabled = false;
            }
            return;
        }

        try {
            const res = await fetch(`/api/admin-analytics.php?action=update-payment-processors-config&token=${encodeURIComponent(sessionToken)}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Admin-Token': sessionToken
                },
                body: JSON.stringify(payload)
            });

            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'ok') {
                throw new Error(data.error || 'Unable to save payment settings');
            }

            renderPaymentProcessorsConfig(data.config || payload);
            setStatus('settings-status success', 'Payment settings saved.');
            showSaveToast('Payment settings saved successfully.');
            await loadRuntimeConfig();
        } catch (err) {
            setStatus('settings-status error', err.message);
            logPaymentApiError('update-payment-processors-config', err, payload);
            showSaveToast(err.message || 'Unable to save payment settings.', true);
        } finally {
            if (saveBtn) {
                saveBtn.disabled = false;
            }
        }
    }

    async function testCryptoDerivation() {
        const statusEl = document.getElementById('cryptoSettingsStatus');
        if (!statusEl) {
            return;
        }

        statusEl.className = 'settings-status';
        statusEl.textContent = 'Testing wallet derivation...';

        try {
            const response = await fetch(`/api/admin-analytics.php?action=test-crypto-derivation&token=${encodeURIComponent(sessionToken)}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Admin-Token': sessionToken
                },
                body: JSON.stringify({
                    coins: String(document.getElementById('coinbaseSupportedCoinsInput')?.value || '').toUpperCase()
                })
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.status !== 'ok') {
                throw new Error(data.error || 'Derivation test failed');
            }

            const result = data.derivation || {};
            if (result.ok) {
                const count = Object.keys(result.addresses || {}).length;
                statusEl.className = 'settings-status success';
                statusEl.textContent = `Derivation is working. Received ${count} address(es) from wallet service.`;
            } else {
                const details = result.error || 'Unknown derivation error';
                statusEl.className = 'settings-status error';
                statusEl.textContent = `Derivation failed: ${details}`;
            }
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message || 'Unable to run derivation test.';
        }
    }

    function applyCryptoServiceBadge(el, level, text) {
        if (!el) {
            return;
        }

        el.className = `status-badge status-${level}`;
        el.textContent = text;
    }

    async function loadCryptoServiceHealth() {
        const infoEl = document.getElementById('cryptoServiceHealthInfo');
        const derivationBadge = document.getElementById('cryptoDerivationHealthBadge');
        const derivationMeta = document.getElementById('cryptoDerivationHealthMeta');
        const autoVerifyBadge = document.getElementById('cryptoAutoVerifyHealthBadge');
        const autoVerifyMeta = document.getElementById('cryptoAutoVerifyHealthMeta');

        applyCryptoServiceBadge(derivationBadge, 'degraded', 'Checking...');
        applyCryptoServiceBadge(autoVerifyBadge, 'degraded', 'Checking...');
        if (derivationMeta) derivationMeta.textContent = 'Checking derivation service health...';
        if (autoVerifyMeta) autoVerifyMeta.textContent = 'Checking auto verification worker health...';
        if (infoEl) infoEl.textContent = 'Health URL: checking...';

        try {
            const data = await fetch_admin_api('crypto-service-health');
            const health = data?.health || {};
            const derivation = health?.derivation || {};
            const autoVerify = health?.autoVerify || {};

            if (infoEl) {
                const healthUrl = String(health?.walletHealthUrl || '').trim();
                const statusCode = Number(health?.httpStatus || 0);
                if (healthUrl) {
                    infoEl.textContent = `Health URL: ${healthUrl}${statusCode > 0 ? ` (HTTP ${statusCode})` : ''}`;
                } else {
                    infoEl.textContent = 'Health URL: not configured';
                }
            }

            if (!derivation?.enabled) {
                applyCryptoServiceBadge(derivationBadge, 'degraded', 'Disabled');
                if (derivationMeta) derivationMeta.textContent = 'Enable per-tip auto-generated wallet addresses to use derivation.';
            } else if (derivation?.online) {
                applyCryptoServiceBadge(derivationBadge, 'healthy', 'Online');
                const coins = Array.isArray(derivation?.configuredCoins) ? derivation.configuredCoins : [];
                if (derivationMeta) {
                    derivationMeta.textContent = coins.length > 0
                        ? `Configured coins: ${coins.join(', ')}`
                        : 'Service is online.';
                }
            } else {
                applyCryptoServiceBadge(derivationBadge, 'critical', 'Offline');
                const err = String(health?.error || '').trim();
                if (derivationMeta) derivationMeta.textContent = err || 'Derivation service is not reachable.';
            }

            if (!autoVerify?.enabled) {
                applyCryptoServiceBadge(autoVerifyBadge, 'degraded', 'Disabled');
                if (autoVerifyMeta) autoVerifyMeta.textContent = 'Enable automatic on-chain verification worker to use auto verification.';
            } else if (!autoVerify?.providerConfigured) {
                applyCryptoServiceBadge(autoVerifyBadge, 'degraded', 'Misconfigured');
                if (autoVerifyMeta) autoVerifyMeta.textContent = 'Auto verify provider URL is missing.';
            } else if (autoVerify?.online) {
                applyCryptoServiceBadge(autoVerifyBadge, 'healthy', 'Online');
                const lastRun = String(autoVerify?.lastRunAt || '').trim();
                if (autoVerifyMeta) autoVerifyMeta.textContent = lastRun ? `Last run: ${new Date(lastRun).toLocaleString()}` : 'Worker is active.';
            } else {
                applyCryptoServiceBadge(autoVerifyBadge, 'critical', 'Offline');
                const lastErr = String(autoVerify?.lastError || '').trim();
                if (autoVerifyMeta) autoVerifyMeta.textContent = lastErr || 'Worker is not running or health check failed.';
            }
        } catch (err) {
            applyCryptoServiceBadge(derivationBadge, 'critical', 'Error');
            applyCryptoServiceBadge(autoVerifyBadge, 'critical', 'Error');
            if (derivationMeta) derivationMeta.textContent = err?.message || 'Unable to fetch service status.';
            if (autoVerifyMeta) autoVerifyMeta.textContent = 'Unable to fetch service status.';
            if (infoEl) infoEl.textContent = 'Health URL: unavailable';
        }
    }

    function shortText(value, max = 14) {
        const text = String(value || '');
        if (text.length <= max) {
            return text;
        }
        return `${text.slice(0, max)}...`;
    }

    function escapeHtml(value) {
        return String(value || '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    }

    // ── Coin address row builders ──────────────────────────────────────────────

    function tryParseJson(raw) {
        try { return JSON.parse(raw || '{}') || {}; } catch { return {}; }
    }

    function getCoinList() {
        const raw = String(document.getElementById('coinbaseSupportedCoinsInput')?.value || 'BTC,ETH,LTC,BCH,DOGE,USDC,USDT,XRP');
        const coins = raw.split(',').map(s => s.trim().toUpperCase()).filter(s => s.length >= 2);
        if (!coins.includes('XRP')) {
            coins.push('XRP');
        }
        return [...new Set(coins)];
    }

    const COIN_COLORS = {
        BTC: '#f7931a', ETH: '#627eea', LTC: '#bfbbbb', BCH: '#8dc351',
        DOGE: '#c2a633', USDC: '#2775ca', USDT: '#26a17b', XRP: '#346aa9'
    };

    function buildCoinAddressRows(containerId, currentData, placeholderPrefix) {
        const container = document.getElementById(containerId);
        if (!container) return;

        // Preserve existing values before rebuild
        const existing = {};
        container.querySelectorAll('input[data-coin]').forEach(inp => {
            const v = inp.value.trim();
            if (v) existing[inp.dataset.coin] = v;
        });

        const coins = getCoinList();
        container.innerHTML = '';

        coins.forEach(coin => {
            const val = existing[coin] || currentData[coin] || currentData[coin.toLowerCase()] || '';
            const color = COIN_COLORS[coin] || '#2ae8c7';

            const row = document.createElement('div');
            row.style.cssText = 'display:grid;grid-template-columns:72px 1fr;align-items:center;gap:0.5rem;';
            row.innerHTML = `
                <label style="margin:0;padding:0.28rem 0.5rem;border-radius:6px;background:${escapeHtml(color)}22;border:1px solid ${escapeHtml(color)}55;color:${escapeHtml(color)};font-weight:700;font-size:0.82rem;text-align:center;letter-spacing:0.04em;">${escapeHtml(coin)}</label>
                <input type="text"
                    id="${escapeHtml(containerId)}_${escapeHtml(coin)}"
                    data-coin="${escapeHtml(coin)}"
                    placeholder="${escapeHtml(placeholderPrefix)} ${escapeHtml(coin)} address"
                    value="${escapeHtml(val)}"
                    style="font-family:monospace;font-size:0.83rem;" />
            `;
            container.appendChild(row);
        });
    }

    function collectCoinAddressJson(containerId) {
        const container = document.getElementById(containerId);
        if (!container) return '{}';
        const result = {};
        container.querySelectorAll('input[data-coin]').forEach(inp => {
            const val = inp.value.trim();
            if (val) result[inp.dataset.coin] = val;
        });
        return JSON.stringify(result);
    }

    function collectCoinAddressMap(containerId) {
        try {
            return tryParseJson(collectCoinAddressJson(containerId));
        } catch {
            return {};
        }
    }

    function firstMapValue(mapObj) {
        if (!mapObj || typeof mapObj !== 'object') {
            return '';
        }

        const keys = Object.keys(mapObj);
        for (const key of keys) {
            const value = String(mapObj[key] || '').trim();
            if (value) {
                return value;
            }
        }

        return '';
    }

    function rebuildCoinAddressRows() {
        // Preserve current values during rebuild
        const receiveData = tryParseJson(collectCoinAddressJson('cryptoReceiveAddressRows'));
        const destData = tryParseJson(collectCoinAddressJson('cryptoDestinationAddressRows'));
        const baseData = tryParseJson(collectCoinAddressJson('walletBaseAddressRows'));
        buildCoinAddressRows('cryptoReceiveAddressRows', receiveData, 'Your');
        buildCoinAddressRows('cryptoDestinationAddressRows', destData, 'Coinbase');
        buildCoinAddressRows('walletBaseAddressRows', baseData, 'Wallet base');
    }

    function autofillAddressRows(containerId, fallbackValue) {
        const container = document.getElementById(containerId);
        if (!container || !fallbackValue) {
            return;
        }

        container.querySelectorAll('input[data-coin]').forEach((input) => {
            if (String(input.value || '').trim() === '') {
                input.value = fallbackValue;
            }
        });
    }

    function autofillReceiveAddressRows() {
        const container = document.getElementById('cryptoReceiveAddressRows');
        if (!container) {
            return;
        }

        const walletBaseMap = collectCoinAddressMap('walletBaseAddressRows');
        const destinationMap = collectCoinAddressMap('cryptoDestinationAddressRows');
        const receiveMap = collectCoinAddressMap('cryptoReceiveAddressRows');
        const fallback = String(document.getElementById('cryptoReceiveAddressInput')?.value || '').trim();
        const sharedFallback =
            fallback ||
            firstMapValue(walletBaseMap) ||
            firstMapValue(destinationMap) ||
            firstMapValue(receiveMap);
        let changed = 0;
        let emptyBefore = 0;

        container.querySelectorAll('input[data-coin]').forEach((input) => {
            if (String(input.value || '').trim() !== '') {
                return;
            }

            emptyBefore += 1;

            const coin = String(input.dataset.coin || '').toUpperCase();
            const fromBase = String(walletBaseMap[coin] || walletBaseMap[coin.toLowerCase()] || '').trim();
            const fromDestination = String(destinationMap[coin] || destinationMap[coin.toLowerCase()] || '').trim();
            const next = fromBase || fromDestination || sharedFallback;
            if (!next) {
                return;
            }

            input.value = next;
            changed += 1;
        });

        const statusEl = document.getElementById('cryptoSettingsStatus');
        if (statusEl) {
            if (changed > 0) {
                statusEl.className = 'settings-status success';
                statusEl.textContent = `Filled ${changed} receive row(s).`;
            } else {
                statusEl.className = 'settings-status error';
                if (emptyBefore === 0) {
                    statusEl.textContent = 'All receive rows already have values.';
                } else {
                    statusEl.textContent = 'Nothing filled. Add at least one wallet base, destination, or fallback receive address first.';
                }
            }
        }
    }

    function autofillWalletBaseAddressRows() {
        const fallback = String(document.getElementById('cryptoReceiveAddressInput')?.value || '').trim();
        autofillAddressRows('walletBaseAddressRows', fallback);

        const statusEl = document.getElementById('cryptoSettingsStatus');
        if (statusEl) {
            statusEl.className = 'settings-status success';
            statusEl.textContent = 'Wallet base address rows autofilled where possible.';
        }
    }

    function autofillDestinationAddressRows() {
        const fallback = String(document.getElementById('coinbaseDestinationAccountInput')?.value || '').trim();
        autofillAddressRows('cryptoDestinationAddressRows', fallback);
    }

    // ─────────────────────────────────────────────────────────────────────────

    async function loadWalletOverview() {
        const grid = document.getElementById('walletOverviewGrid');
        const statusEl = document.getElementById('walletOverviewStatus');
        if (!grid || !statusEl) {
            return;
        }

        statusEl.className = 'settings-status';
        statusEl.textContent = 'Loading wallet overview...';

        try {
            const data = await fetch_admin_api('wallet-overview');
            const wallets = Array.isArray(data.wallets) ? data.wallets : [];
            grid.innerHTML = '';

            if (wallets.length === 0) {
                grid.innerHTML = '<p style="opacity:0.7">No supported coins configured.</p>';
                statusEl.textContent = '';
                return;
            }

            wallets.forEach((w) => {
                const coin = String(w.coin || '').toUpperCase();
                const receiveAddr = String(w.receiveAddress || '');
                const destAddr = String(w.coinbaseDestination || '');
                const confirmedCents = Number(w.confirmedReceived?.amountCents || 0);
                const confirmedCount = Number(w.confirmedReceived?.count || 0);
                const transferredCents = Number(w.transferred?.amountCents || 0);
                const pendingCount = Number(w.pending?.count || 0);
                const confirmedFiat = (confirmedCents / 100).toFixed(2);
                const transferredFiat = (transferredCents / 100).toFixed(2);
                const hasReceive = !!receiveAddr;
                const hasDest = !!destAddr;

                const card = document.createElement('div');
                card.className = 'settings-panel';
                card.style.cssText = 'padding:0.85rem;border:1px solid rgba(42,232,199,0.28);';
                card.innerHTML = `
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
                        <strong style="font-size:1.15rem;color:#2ae8c7;">${escapeHtml(coin)}</strong>
                        <span style="font-size:0.75rem;opacity:0.7;">${pendingCount > 0 ? `⏳ ${pendingCount} pending` : ''}</span>
                    </div>
                    <div style="font-size:0.8rem;margin-bottom:0.35rem;">
                        <span style="opacity:0.75;">Receive address:</span><br>
                        <code style="font-size:0.75rem;word-break:break-all;">${hasReceive ? escapeHtml(receiveAddr) : '<em style="opacity:0.5">Not configured</em>'}</code>
                    </div>
                    <div style="font-size:0.8rem;margin-bottom:0.35rem;">
                        <span style="opacity:0.75;">Coinbase destination:</span><br>
                        <code style="font-size:0.75rem;word-break:break-all;">${hasDest ? escapeHtml(destAddr) : '<em style="opacity:0.5">Not configured</em>'}</code>
                    </div>
                    <div style="display:flex;gap:0.75rem;font-size:0.78rem;margin-bottom:0.65rem;flex-wrap:wrap;">
                        <span>✅ Confirmed: <strong>${escapeHtml(confirmedFiat)} (${confirmedCount})</strong></span>
                        <span>🔁 Transferred: <strong>${escapeHtml(transferredFiat)}</strong></span>
                    </div>
                    <button class="btn btn-sm" type="button"
                        ${confirmedCount > 0 && hasDest ? '' : 'disabled'}
                        onclick="withdrawToCoinbase('${escapeHtml(coin)}')">
                        Withdraw ${escapeHtml(coin)} → Coinbase
                    </button>
                    ${!hasDest ? '<div style="font-size:0.72rem;opacity:0.6;margin-top:0.35rem;">Set a Coinbase destination address in settings to enable withdrawal.</div>' : ''}
                `;
                grid.appendChild(card);
            });

            statusEl.className = 'settings-status success';
            statusEl.textContent = `Loaded ${wallets.length} wallet(s).`;
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message || 'Unable to load wallet overview.';
        }
    }

    async function withdrawToCoinbase(coin) {
        const statusEl = document.getElementById('walletOverviewStatus');
        if (!coin || !statusEl) {
            return;
        }

        if (!confirm(`Withdraw all confirmed ${coin} tips to your Coinbase destination address?\n\nThis will mark those tips as "transfer requested". Make sure your destination address is correct in settings.`)) {
            return;
        }

        statusEl.className = 'settings-status';
        statusEl.textContent = `Requesting ${coin} withdrawal to Coinbase...`;

        try {
            const response = await fetch(`/api/admin-analytics.php?action=withdraw-to-coinbase&token=${encodeURIComponent(sessionToken)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Admin-Token': sessionToken },
                body: JSON.stringify({ coin, note: `${coin} tip jar withdrawal` })
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.status !== 'ok') {
                throw new Error(data.error || `Unable to withdraw ${coin}`);
            }

            statusEl.className = 'settings-status success';
            const fiatTotal = (Number(data.amountCents || 0) / 100).toFixed(2);
            statusEl.textContent = `${data.tipsProcessed} ${coin} tip(s) totalling ${fiatTotal} ${data.fiatCurrency} queued for withdrawal. Status: ${data.withdrawStatus}.`;
            await loadWalletOverview();
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message;
        }
    }

    async function loadCryptoTransferQueue() {
        const tbody = document.getElementById('cryptoTransferTbody');
        const statusEl = document.getElementById('cryptoTransferStatus');
        if (!tbody || !statusEl) {
            return;
        }

        statusEl.className = 'settings-status';
        statusEl.textContent = 'Loading crypto queue...';

        try {
            const data = await fetch_admin_api('crypto-transfer-queue');
            const rows = Array.isArray(data.tips) ? data.tips : [];
            tbody.innerHTML = '';

            if (rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6">No local crypto tips in queue.</td></tr>';
                statusEl.className = 'settings-status';
                statusEl.textContent = 'Queue is empty.';
                return;
            }

            rows.forEach((tip) => {
                const tr = document.createElement('tr');
                const created = tip.createdAt ? new Date(tip.createdAt).toLocaleString() : '-';
                const fiatAmount = (Number(tip.amountCents || 0) / 100).toFixed(2);
                const fiatCurrency = String(tip.currency || 'USD').toUpperCase();
                const asset = String(tip.cryptoAsset || '').toUpperCase();
                const amount = `${fiatAmount} ${fiatCurrency}${asset ? ` (${asset})` : ''}`;
                const txHash = tip.txHash ? `<code style="font-size:.75rem">${escapeHtml(shortText(tip.txHash, 16))}</code>` : '-';
                const canConfirm = tip.status === 'awaiting_crypto_payment' || tip.status === 'payment_submitted';
                const canRequest = tip.status === 'paid' || tip.status === 'payment_submitted' || tip.status === 'coinbase_transfer_requested';

                tr.innerHTML = `
                    <td>${created}</td>
                    <td>${escapeHtml(tip.username || 'anonymous')}</td>
                    <td>${escapeHtml(amount)}</td>
                    <td>${escapeHtml(tip.status || 'unknown')}</td>
                    <td>${txHash}</td>
                    <td>
                        <button class="btn btn-sm" type="button" ${canConfirm ? '' : 'disabled'} onclick="confirmCryptoPayment('${tip.id}')">Confirm Received</button>
                        <button class="btn btn-sm" type="button" ${canRequest ? '' : 'disabled'} onclick="requestCoinbaseTransfer('${tip.id}')">Request Coinbase Transfer</button>
                    </td>`;
                tbody.appendChild(tr);
            });

            statusEl.className = 'settings-status success';
            statusEl.textContent = `Loaded ${rows.length} crypto queue item(s).`;
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message || 'Unable to load crypto queue.';
        }
    }

    async function confirmCryptoPayment(tipId) {
        const txHash = prompt('Enter the transaction hash for this payment (optional if already submitted):', '');
        const statusEl = document.getElementById('cryptoTransferStatus');
        if (!statusEl || !tipId) {
            return;
        }

        statusEl.className = 'settings-status';
        statusEl.textContent = 'Confirming crypto payment...';

        try {
            const response = await fetch(`/api/admin-analytics.php?action=confirm-crypto-payment&token=${encodeURIComponent(sessionToken)}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Admin-Token': sessionToken
                },
                body: JSON.stringify({ tipId, txHash: String(txHash || '').trim() })
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.status !== 'ok') {
                throw new Error(data.error || 'Unable to confirm payment');
            }

            statusEl.className = 'settings-status success';
            statusEl.textContent = 'Crypto payment marked as received.';
            await loadCryptoTransferQueue();
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message;
        }
    }

    async function requestCoinbaseTransfer(tipId) {
        const statusEl = document.getElementById('cryptoTransferStatus');
        if (!statusEl || !tipId) {
            return;
        }

        statusEl.className = 'settings-status';
        statusEl.textContent = 'Requesting Coinbase transfer...';

        try {
            const response = await fetch(`/api/admin-analytics.php?action=request-coinbase-transfer&token=${encodeURIComponent(sessionToken)}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Admin-Token': sessionToken
                },
                body: JSON.stringify({ tipId })
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.status !== 'ok') {
                throw new Error(data.error || 'Unable to request transfer');
            }

            statusEl.className = 'settings-status success';
            statusEl.textContent = data.message || 'Transfer request submitted.';
            await loadCryptoTransferQueue();
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message;
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
                headers: {
                    'Content-Type': 'application/json',
                    'X-Admin-Token': sessionToken
                },
                body: JSON.stringify({})
            });

            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'ok') {
                throw new Error(data.error || 'Unable to reset Stripe configuration');
            }

            statusEl.className = 'settings-status success';
            statusEl.textContent = data.message || 'Stripe configuration reset.';
            showSaveToast('Stripe account settings reset successfully.');
            await loadPaymentProcessorsConfig();
            await loadStripeOneTimeConfig();
            await loadRuntimeConfig();
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message;
            logPaymentApiError('stripe-reset-account', err);
            showSaveToast(err.message || 'Unable to reset Stripe settings.', true);
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
                '£' + ((m.monetization?.totalRevenueCents ?? 0) / 100).toFixed(2);
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
                    tr.innerHTML = `<td>${r.type}</td><td class="score-high">£${(r.amountCents/100).toFixed(2)}</td><td>${r.count}</td>`;
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
                        <td class="score-high">£${((t.amountCents ?? 0)/100).toFixed(2)}</td>
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
            logPaymentApiError('stripe-one-time-config', err);
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
            logPaymentApiError('stripe-create-one-time-product', err, payload);
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
            logPaymentApiError('stripe-create-one-time-checkout-session', err);
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

    async function runStripeBackfillFromSettings() {
        const statusEl = document.getElementById('stripeBackfillStatus');
        const runBtn = document.getElementById('stripeBackfillRunBtn');
        const modeNode = document.querySelector('input[name="stripeBackfillMode"]:checked');
        const mode = modeNode ? String(modeNode.value) : 'full';

        let days = Number(document.getElementById('stripeBackfillDaysInput').value || 365);
        if (!Number.isFinite(days) || days < 1) {
            days = 365;
        }
        if (days > 3650) {
            days = 3650;
        }

        statusEl.className = 'settings-status';
        statusEl.textContent = 'Running Stripe backfill...';
        runBtn.disabled = true;

        try {
            const res = await fetch(`/api/admin-analytics.php?action=stripe-backfill&token=${encodeURIComponent(sessionToken)}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Admin-Token': sessionToken
                },
                body: JSON.stringify({
                    mode,
                    days,
                    maxPages: 500
                })
            });

            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'ok') {
                throw new Error(data.error || 'Stripe backfill failed');
            }

            const result = data.backfill || {};
            statusEl.className = 'settings-status success';
            statusEl.textContent =
                `Backfill complete. Sessions: ${result.sessionsFetched || 0}, created: ${result.created || 0}, updated: ${result.updated || 0}, paid: ${result.paid || 0}, pending: ${result.pending || 0}, failed: ${result.failed || 0}${result.reachedPageLimit ? ' (page limit reached)' : ''}.`;

            await loadDashboard();
            await loadWebhookEvents();
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message;
            logPaymentApiError('stripe-backfill', err, { mode, days });
        } finally {
            runBtn.disabled = false;
        }
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
        const gameKeys = Object.keys(config.games || {}).sort();
        if (!gameKeys.length) {
            host.innerHTML = '<div class="settings-note">No game variables found in runtime config.</div>';
            return;
        }

        const groups = [];
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
        statusEl.textContent = 'Loading game variables...';

        try {
            const data = await fetch_admin_api('runtime-config');
            const config = data.config || {};
            runtimeConfigState = deepClone(config);
            renderRuntimeFields(runtimeConfigState);
            editor.value = JSON.stringify(runtimeConfigState, null, 2);
            statusEl.className = 'settings-status success';
            statusEl.textContent = 'Game variables loaded.';
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = 'Failed to load game variables.';
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
        statusEl.textContent = 'Saving game variables...';
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
            statusEl.textContent = 'Game variables saved.';
        } catch (err) {
            statusEl.className = 'settings-status error';
            statusEl.textContent = err.message;
        } finally {
            saveBtn.disabled = false;
        }
    }

    // ── Staff Management ────────────────────────────────────────────────────
    async function loadStaffList() {
        try {
            const data = await fetch_admin_api('list-staff');
            const tbody = document.getElementById('staffListTbody');
            if (!tbody) return;
            
            tbody.innerHTML = '';
            const staff = data.staff || [];
            if (!staff.length) {
                tbody.innerHTML = '<tr><td colspan="3">No staff members (other than you)</td></tr>';
                return;
            }
            
            staff.forEach(s => {
                const tr = tbody.insertRow();
                const created = new Date(s.createdAt).toLocaleDateString();
                const roleLabel = s.role === 'admin' ? 'Admin (Full)' : 'Mod (Overview)';
                tr.innerHTML = `<td>${s.username}</td><td>${roleLabel}</td><td>${created}</td>`;
            });
        } catch (err) {
            console.error('Error loading staff list:', err);
        }
    }

    async function handleAddStaffMember() {
        const username = document.getElementById('newStaffUsername').value.trim();
        const password = document.getElementById('newStaffPassword').value.trim();
        const role = document.getElementById('newStaffRole').value.trim();
        const statusEl = document.getElementById('staffStatusMessage');
        const btn = document.getElementById('addStaffBtn');

        if (!username || !password || !role) {
            statusEl.style.display = 'block';
            statusEl.style.background = 'rgba(239,68,68,0.1)';
            statusEl.style.borderLeft = '3px solid #ef4444';
            statusEl.style.color = '#fca5a5';
            statusEl.textContent = 'Please fill in all fields.';
            return;
        }

        if (password.length < 8) {
            statusEl.style.display = 'block';
            statusEl.style.background = 'rgba(239,68,68,0.1)';
            statusEl.style.borderLeft = '3px solid #ef4444';
            statusEl.style.color = '#fca5a5';
            statusEl.textContent = 'Password must be at least 8 characters.';
            return;
        }

        btn.disabled = true;
        try {
            const res = await fetch(`/api/admin-analytics.php?action=add-staff&token=${encodeURIComponent(sessionToken)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password, role })
            });

            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status !== 'ok') {
                throw new Error(data.error || 'Failed to add staff member');
            }

            statusEl.style.display = 'block';
            statusEl.style.background = 'rgba(34,197,94,0.1)';
            statusEl.style.borderLeft = '3px solid #22c55e';
            statusEl.style.color = '#86efac';
            statusEl.textContent = `Staff member '${username}' added successfully!`;

            document.getElementById('newStaffUsername').value = '';
            document.getElementById('newStaffPassword').value = '';
            document.getElementById('newStaffRole').value = 'mod';

            await loadStaffList();
        } catch (err) {
            statusEl.style.display = 'block';
            statusEl.style.background = 'rgba(239,68,68,0.1)';
            statusEl.style.borderLeft = '3px solid #ef4444';
            statusEl.style.color = '#fca5a5';
            statusEl.textContent = err.message;
        } finally {
            btn.disabled = false;
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
    populateStripeWebhookEndpointHelper();
    loadPaymentProcessorsConfig();
    loadStripeOneTimeConfig();
    loadRuntimeConfig();
    loadStaffList();
    startOverviewPolling();
    startAdminEventsStream();
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            refreshOverviewTick();
            startAdminEventsStream();
            return;
        }

        stopAdminEventsStream();
    });
    window.addEventListener('focus', () => {
        refreshOverviewTick();
        startAdminEventsStream();
    });
    setInterval(() => { loadSuspiciousScores(); loadWebhookEvents(); }, 30000);
    <?php endif; ?>
</script>
</body>
</html>
