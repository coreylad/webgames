<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/api/common.php';

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
  if (verify_admin_token($entered)) {
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

      <section class="panel">
        <div class="panel-header">
          <h2>Admin Users</h2>
          <p>Add or remove dashboard admins.</p>
        </div>

        <div class="admin-top" style="margin-bottom:1rem">
          <div>
            <label for="newAdminUsername">Username</label>
            <input id="newAdminUsername" type="text" placeholder="new_admin" maxlength="24" />
          </div>
          <div>
            <label for="newAdminToken">Token</label>
            <input id="newAdminToken" type="password" placeholder="min 8 chars" />
          </div>
          <button id="addAdminBtn" type="button">Add Admin</button>
        </div>

        <p id="adminManageStatus" class="status" aria-live="polite"></p>

        <div class="table-wrap" style="margin-top:0.8rem">
          <table>
            <thead>
              <tr>
                <th>Username</th>
                <th>Created</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="adminsTableBody">
              <tr><td colspan="3">Loading...</td></tr>
            </tbody>
          </table>
        </div>
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

    async function fetchJson(path, options = {}) {
      const response = await fetch(path, {
        ...options,
        headers: {
          'Content-Type': 'application/json',
          'x-admin-token': ADMIN_TOKEN,
          ...(options.headers || {})
        }
      });

      const payload = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(payload.error || 'Request failed');
      }

      return payload;
    }

    async function loadAdmins() {
      const body = document.getElementById('adminsTableBody');
      body.innerHTML = '<tr><td colspan="3">Loading...</td></tr>';
      try {
        const data = await fetchJson('/api/admin-admins.php');
        const admins = Array.isArray(data.admins) ? data.admins : [];
        body.innerHTML = '';

        if (!admins.length) {
          body.innerHTML = '<tr><td colspan="3">No admins configured.</td></tr>';
          return;
        }

        admins.forEach((admin) => {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>${admin.username}</td>
            <td>${fmt(admin.createdAt)}</td>
            <td><button type="button" class="btn-ghost" data-remove-admin="${admin.username}">Remove</button></td>
          `;
          body.appendChild(tr);
        });
      } catch (error) {
        body.innerHTML = `<tr><td colspan="3">${error.message}</td></tr>`;
      }
    }

    async function loadDashboard() {
      const status = document.getElementById('adminStatus');
      try {
        const [sum, tips] = await Promise.all([
          fetchJson('/api/admin-summary.php'),
          fetchJson('/api/admin-tips.php')
        ]);

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

    document.getElementById('addAdminBtn').addEventListener('click', async () => {
      const statusEl = document.getElementById('adminManageStatus');
      const username = document.getElementById('newAdminUsername').value.trim();
      const token = document.getElementById('newAdminToken').value;

      statusEl.className = 'status';
      statusEl.textContent = 'Adding admin...';

      try {
        await fetchJson('/api/admin-add-admin.php', {
          method: 'POST',
          body: JSON.stringify({ username, token })
        });
        document.getElementById('newAdminUsername').value = '';
        document.getElementById('newAdminToken').value = '';
        statusEl.className = 'status success';
        statusEl.textContent = 'Admin added.';
        await loadAdmins();
      } catch (error) {
        statusEl.className = 'status error';
        statusEl.textContent = error.message;
      }
    });

    document.getElementById('adminsTableBody').addEventListener('click', async (event) => {
      const target = event.target.closest('[data-remove-admin]');
      if (!target) {
        return;
      }

      const username = target.getAttribute('data-remove-admin');
      if (!username) {
        return;
      }

      if (!confirm(`Remove admin '${username}'?`)) {
        return;
      }

      const statusEl = document.getElementById('adminManageStatus');
      statusEl.className = 'status';
      statusEl.textContent = 'Removing admin...';

      try {
        await fetchJson('/api/admin-remove-admin.php', {
          method: 'POST',
          body: JSON.stringify({ username })
        });
        statusEl.className = 'status success';
        statusEl.textContent = 'Admin removed.';
        await loadAdmins();
      } catch (error) {
        statusEl.className = 'status error';
        statusEl.textContent = error.message;
      }
    });

    loadDashboard();
    loadAdmins();
    </script>
<?php endif; ?>
  </body>
</html>
