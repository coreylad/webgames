<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/api/common.php';

$env        = load_env_values();
$adminToken = $env['ADMIN_DASHBOARD_TOKEN'];
$error      = '';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin.php');
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered = trim((string)($_POST['token'] ?? ''));
    if ($entered !== '' && hash_equals($adminToken, $entered)) {
        $_SESSION['webgames_admin']  = true;
        $_SESSION['webgames_token']  = $entered;
        header('Location: /admin.php');
        exit;
    }
    $error = 'Invalid token.';
}

$authed       = !empty($_SESSION['webgames_admin']);
$sessionToken = $authed ? htmlspecialchars((string)$_SESSION['webgames_token'], ENT_QUOTES) : '';
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard | webgames.lol</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700;900&family=Rajdhani:wght@400;500;600;700&family=Share+Tech+Mono&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="/public/styles.css" />
  </head>
  <body>
    <div class="bg-shape bg-shape-left"></div>
    <div class="bg-shape bg-shape-right"></div>

<?php if (!$authed): ?>
    <main class="container">
      <section class="panel admin-login">
        <h1>Admin Access</h1>
        <?php if ($error !== ''): ?>
          <p class="status error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
        <?php endif; ?>
        <form method="POST" action="/admin.php">
          <div style="margin-bottom:1rem">
            <label for="token">Admin Token</label>
            <input type="password" id="token" name="token" placeholder="Enter ADMIN_DASHBOARD_TOKEN" autofocus required />
          </div>
          <button type="submit" style="width:100%">Authenticate</button>
        </form>
      </section>
    </main>

<?php else: ?>
    <header class="site-header container">
      <a class="logo" href="/">webgames.lol</a>
      <nav class="top-nav">
        <a href="/">Home</a>
        <a href="/admin.php">Dashboard</a>
        <a href="/admin.php?logout=1">Log out</a>
      </nav>
    </header>

    <main class="container admin-wrap">
      <section class="panel">
        <div class="panel-header">
          <h2>Dashboard</h2>
          <p id="adminStatus" class="status" aria-live="polite">Loading data...</p>
        </div>
        <div class="summary-grid">
          <article class="summary-box">
            <p>Total tips</p>
            <strong id="totalTips">—</strong>
          </article>
          <article class="summary-box">
            <p>Total paid</p>
            <strong id="paidUsd">—</strong>
          </article>
          <article class="summary-box">
            <p>Unique players</p>
            <strong id="uniqueUsersCount">—</strong>
          </article>
        </div>
        <p id="usernamesList" class="status" style="margin-top:0.75rem"></p>
      </section>

      <section class="panel table-wrap">
        <div class="panel-header">
          <h2>Tip Records</h2>
        </div>
        <table>
          <thead>
            <tr>
              <th>Username</th>
              <th>Tier</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Created</th>
              <th>Session ID</th>
            </tr>
          </thead>
          <tbody id="tipsTableBody">
            <tr><td colspan="6">Loading...</td></tr>
          </tbody>
        </table>
      </section>
    </main>

    <script>
    const ADMIN_TOKEN = <?= json_encode($sessionToken, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    function centsToUsd(v) {
      return '$' + (Number(v || 0) / 100).toFixed(2);
    }
    function fmt(v) {
      return v ? new Date(v).toLocaleString() : '—';
    }
    function statusBadge(s) {
      const cls = s === 'paid' || s === 'completed' ? 'badge-paid'
                : (s === 'checkout_pending' || s === 'checkout_created') ? 'badge-pending'
                : 'badge-other';
      return `<span class="badge ${cls}">${s || 'unknown'}</span>`;
    }

    async function loadDashboard() {
      const status = document.getElementById('adminStatus');
      try {
        const headers = { 'x-admin-token': ADMIN_TOKEN };
        const [sumRes, tipsRes] = await Promise.all([
          fetch('/api/admin-summary.php', { headers }),
          fetch('/api/admin-tips.php',   { headers })
        ]);
        if (!sumRes.ok || !tipsRes.ok) throw new Error('Unauthorized or request failed');
        const sum  = await sumRes.json();
        const tips = await tipsRes.json();

        document.getElementById('totalTips').textContent        = sum.totalTips;
        document.getElementById('paidUsd').textContent          = centsToUsd(sum.totalPaidCents);
        document.getElementById('uniqueUsersCount').textContent = sum.uniqueUsernames.length;
        document.getElementById('usernamesList').textContent    =
          sum.uniqueUsernames.length ? 'Players: ' + sum.uniqueUsernames.join(', ') : '';

        const tbody = document.getElementById('tipsTableBody');
        tbody.innerHTML = '';
        if (!tips.tips || !tips.tips.length) {
          tbody.innerHTML = '<tr><td colspan="6">No tips yet.</td></tr>';
        } else {
          tips.tips.forEach(t => {
            tbody.insertAdjacentHTML('beforeend', `<tr>
              <td>${t.username || 'anonymous'}</td>
              <td>${t.tierName || '—'}</td>
              <td>${centsToUsd(t.amountCents)}</td>
              <td>${statusBadge(t.status)}</td>
              <td>${fmt(t.createdAt)}</td>
              <td style="font-size:0.75rem;opacity:0.6">${t.sessionId || '—'}</td>
            </tr>`);
          });
        }
        status.textContent  = 'Dashboard loaded.';
        status.className    = 'status success';
      } catch (e) {
        status.textContent = e.message;
        status.className   = 'status error';
      }
    }

    loadDashboard();
    </script>
<?php endif; ?>
  </body>
</html>
