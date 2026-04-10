<?php

declare(strict_types=1);

$root = __DIR__;
$envPath = $root . DIRECTORY_SEPARATOR . '.env';

// Lock installer once .env is configured
if (is_file($envPath)) {
    $envContents = file_get_contents($envPath);
    if ($envContents !== false && str_contains($envContents, 'STRIPE_SECRET_KEY=')) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Installer is disabled after setup. Remove or rename installer.php to re-run it.</p></body></html>';
        exit;
    }
}
$dataDir = $root . DIRECTORY_SEPARATOR . 'data';
$tipStorePath = $dataDir . DIRECTORY_SEPARATOR . 'tips.json';

$errors = [];
$success = '';

function post_value(string $key): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stripeSecret = post_value('stripe_secret_key');
    $stripeWebhook = post_value('stripe_webhook_secret');
    $webhookForwardUrl = post_value('webhook_forward_url');
    $webhookForwardAuthHeader = post_value('webhook_forward_auth_header');
    $webhookForwardAuthToken = post_value('webhook_forward_auth_token');
    $adminToken = post_value('admin_token');
    $baseUrl = post_value('base_url');
    $tierProducts = post_value('tier_products');
    $tierPrices = post_value('tier_prices');
    $overwrite = post_value('overwrite') === 'yes';

    if ($stripeSecret === '') {
        $errors[] = 'Stripe secret key is required.';
    }

    if ($adminToken === '') {
        $errors[] = 'Admin dashboard token is required.';
    }

    if ($baseUrl === '') {
        $errors[] = 'Base URL is required.';
    }

    if (is_file($envPath) && !$overwrite) {
        $errors[] = '.env already exists. Tick overwrite if you want to replace it.';
    }

    if (empty($errors)) {
        if (!is_dir($dataDir) && !mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
            $errors[] = 'Unable to create data directory.';
        } else {
            $envContent = "# webgames.lol runtime config\n" .
                "STRIPE_SECRET_KEY={$stripeSecret}\n" .
                "STRIPE_WEBHOOK_SECRET={$stripeWebhook}\n" .
              "WEBHOOK_FORWARD_URL={$webhookForwardUrl}\n" .
              "WEBHOOK_FORWARD_AUTH_HEADER={$webhookForwardAuthHeader}\n" .
              "WEBHOOK_FORWARD_AUTH_TOKEN={$webhookForwardAuthToken}\n" .
                "ADMIN_DASHBOARD_TOKEN={$adminToken}\n" .
                "BASE_URL={$baseUrl}\n" .
                "STRIPE_TIER_PRODUCT_IDS={$tierProducts}\n" .
                "STRIPE_TIER_PRICE_IDS={$tierPrices}\n";

            if (file_put_contents($envPath, $envContent) === false) {
                $errors[] = 'Unable to write .env file.';
            }

            if (!is_file($tipStorePath)) {
                $seed = json_encode(['tips' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (file_put_contents($tipStorePath, (string)$seed) === false) {
                    $errors[] = 'Unable to initialize data/tips.json.';
                }
            }

            if (empty($errors)) {
                $success = 'Installation complete. Your .env file and tip storage are ready.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>webgames.lol installer</title>
    <style>
      body {
        margin: 0;
        font-family: Arial, sans-serif;
        background: #f5f1e4;
        color: #172336;
      }

      .wrap {
        width: min(760px, 92vw);
        margin: 2rem auto;
        background: #fffdf6;
        border: 1px solid #d8cbae;
        border-radius: 12px;
        padding: 1.25rem;
      }

      h1 {
        margin-top: 0;
      }

      label {
        display: block;
        margin-top: 0.8rem;
        font-weight: bold;
      }

      input,
      textarea,
      button {
        width: 100%;
        padding: 0.65rem;
        border: 1px solid #c8baa0;
        border-radius: 8px;
        font-size: 0.95rem;
      }

      textarea {
        min-height: 64px;
      }

      button {
        margin-top: 1rem;
        background: #ff6433;
        color: #fff;
        border: none;
        cursor: pointer;
        font-weight: bold;
      }

      .note {
        margin-top: 0.45rem;
        font-size: 0.9rem;
        color: #4d5f74;
      }

      .error {
        background: #ffe7e7;
        border: 1px solid #d34f4f;
        border-radius: 8px;
        padding: 0.7rem;
      }

      .success {
        background: #e8ffe9;
        border: 1px solid #3f9b4f;
        border-radius: 8px;
        padding: 0.7rem;
      }
    </style>
  </head>
  <body>
    <main class="wrap">
      <h1>webgames.lol installer</h1>
      <p>Set up PHP config and Stripe catalogue tip tiers without Node.</p>

      <?php if (!empty($errors)): ?>
      <div class="error">
        <?php foreach ($errors as $error): ?>
        <p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($success !== ''): ?>
      <div class="success">
        <p><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p>
        <p>Next: configure Stripe webhook to point at /api/stripe-webhook.php and open <a href="/admin">/admin</a>.</p>
      </div>
      <?php endif; ?>

      <form method="post" action="">
        <label for="stripe_secret_key">Stripe secret key</label>
        <input id="stripe_secret_key" name="stripe_secret_key" placeholder="sk_live_..." required />

        <label for="stripe_webhook_secret">Stripe webhook secret</label>
        <input id="stripe_webhook_secret" name="stripe_webhook_secret" placeholder="whsec_..." />
        <p class="note">Optional during first install. Add after you configure webhook forwarding.</p>

        <label for="webhook_forward_url">Webhook forward URL (optional proxy target)</label>
        <input id="webhook_forward_url" name="webhook_forward_url" placeholder="https://second-site.example/api/stripe-webhook.php" />
        <p class="note">If set, incoming Stripe webhook payloads are forwarded to this URL and still processed locally.</p>

        <label for="webhook_forward_auth_header">Forward auth header name (optional)</label>
        <input id="webhook_forward_auth_header" name="webhook_forward_auth_header" placeholder="x-webgames-proxy-token" />

        <label for="webhook_forward_auth_token">Forward auth token (optional)</label>
        <input id="webhook_forward_auth_token" name="webhook_forward_auth_token" placeholder="shared-secret-value" />
        <p class="note">Sent only to the forward URL. Useful to authenticate this proxy on the target site.</p>

        <label for="admin_token">Admin dashboard token</label>
        <input id="admin_token" name="admin_token" placeholder="long-random-secret" required />

        <label for="base_url">Base URL</label>
        <input id="base_url" name="base_url" placeholder="https://webgames.lol" required />

        <label for="tier_products">Stripe product IDs for tip tiers (comma separated)</label>
        <textarea id="tier_products" name="tier_products" placeholder="prod_abc,prod_xyz"></textarea>

        <label for="tier_prices">Optional Stripe price IDs override (comma separated)</label>
        <textarea id="tier_prices" name="tier_prices" placeholder="price_123,price_456"></textarea>

        <label>
          <input type="checkbox" name="overwrite" value="yes" style="width: auto" />
          Overwrite existing .env if present
        </label>

        <button type="submit">Run Installer</button>
      </form>
    </main>
  </body>
</html>
