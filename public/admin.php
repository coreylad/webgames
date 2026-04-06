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

        <!-- Webhooks -->
        <div class="tab-content" id="webhooks">
            <div class="section">
                <h2>Webhook Events</h2>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Time</th><th>Type</th><th>Status</th><th>Retries</th><th>Event ID</th></tr></thead>
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
    async function handleLogout() {
        if (!confirm('Are you sure you want to logout?')) return;

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
        if (res.status === 401) { handleLogout(); return {}; }
        return res.json();
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
                tr.innerHTML = `
                    <td>${time}</td>
                    <td>${ev.eventType}</td>
                    <td>${status}</td>
                    <td>${ev.retries}</td>
                    <td><code style="font-size:.7rem">${ev.eventId.substring(0,12)}</code></td>`;
            });
        } catch (err) { console.error('Webhook events error:', err); }
    }

    // ── Boot ───────────────────────────────────────────────────────────────
    // PHP already decided what to show, just load data if dashboard is visible.
    <?php if ($show_dashboard): ?>
    loadDashboard();
    loadSuspiciousScores();
    loadAchievementLeaderboard();
    loadWebhookEvents();
    setInterval(() => { loadDashboard(); loadSuspiciousScores(); loadWebhookEvents(); }, 30000);
    <?php endif; ?>
</script>
</body>
</html>
