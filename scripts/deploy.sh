#!/usr/bin/env bash
# ── webgames.lol quick deploy ────────────────────────────────────────────────
# Run this on the server after every git push to sync files and reload nginx.
#
# Usage:
#   sudo bash scripts/deploy.sh
#
# Or from anywhere:  sudo bash /var/www/webgames/scripts/deploy.sh
# ---------------------------------------------------------------------------
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Always pull latest code before any other deploy action.
if [ -z "${DEPLOY_SCRIPT_REEXEC:-}" ] && command -v git >/dev/null 2>&1; then
  if git -C "${REPO_DIR}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "[0/8] Pulling latest code before deploy..."
    git -C "${REPO_DIR}" pull --ff-only
    exec env DEPLOY_SCRIPT_REEXEC=1 bash "${REPO_DIR}/scripts/deploy.sh" "$@"
  fi
fi

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root (use sudo)."
  exit 1
fi

APP_NAME="webgames"
APP_DIR="/var/www/${APP_NAME}"

normalize_path() {
  local p="$1"
  (cd "$p" 2>/dev/null && pwd -P) || return 1
}

REPO_REAL="$(normalize_path "${REPO_DIR}")"
APP_REAL="$(normalize_path "${APP_DIR}" || echo "")"

if [ -z "${APP_REAL}" ]; then
  mkdir -p "${APP_DIR}"
  APP_REAL="$(normalize_path "${APP_DIR}")"
fi

echo "[1/8] Syncing files to ${APP_DIR}..."
if [ "${REPO_REAL}" = "${APP_REAL}" ]; then
  echo "Repo and app directory are the same; skipping rsync copy step."
else
  rsync -a --delete \
    --exclude '.git' \
    --exclude 'node_modules' \
    --exclude '.env' \
    --exclude 'data/' \
    "${REPO_DIR}/" "${APP_DIR}/"
fi

echo "[1/8] Publishing public web assets to root compatibility paths..."
publish_public_asset() {
  local rel="$1"
  local src="${APP_DIR}/public/${rel}"
  local dest="${APP_DIR}/${rel}"

  if [ -f "${src}" ]; then
    cp -f "${src}" "${dest}"
    chown root:root "${dest}" 2>/dev/null || true
    chmod 644 "${dest}" 2>/dev/null || true
  else
    rm -f "${dest}" 2>/dev/null || true
  fi
}

# Keep legacy/direct root URLs updated (for caches, bookmarks, and old links).
publish_public_asset "index.html"
publish_public_asset "tip.html"
publish_public_asset "styles.css"
publish_public_asset "app.js"
publish_public_asset "success.html"
publish_public_asset "success.js"
publish_public_asset "admin.php"

echo "[2/8] Installing deployment requirements..."
if command -v apt-get >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  # Clear distro npm selection before any install transaction when NodeSource
  # nodejs is present (or will be selected).
  apt-mark unhold nodejs npm >/dev/null 2>&1 || true
  apt-get purge -y npm >/dev/null 2>&1 || true

  apt-get update -y

  # Install core system packages first; Node.js/npm are handled separately
  # to avoid NodeSource vs distro npm conflicts.
  apt-get install -y \
    nginx \
    rsync \
    git \
    curl \
    jq \
    openssl \
    php \
    php-cli \
    php-fpm \
    php-curl \
    php-mbstring \
    php-xml \
    php-intl \
    php-zip \
    php-bcmath \
    php-gmp

  # Ensure Node.js + npm are installed from a single package source.
  # Never run a blind --fix-broken here because it can re-select apt npm,
  # which conflicts with NodeSource nodejs packages.
  NODEJS_POLICY="$(apt-cache policy nodejs 2>/dev/null || true)"
  NODEJS_VERSION="$(dpkg-query -W -f='${Version}' nodejs 2>/dev/null || true)"
  if printf '%s\n%s' "${NODEJS_POLICY}" "${NODEJS_VERSION}" | grep -Eqi 'nodesource|deb\.nodesource\.com|nodistro'; then
    # NodeSource nodejs already bundles npm and conflicts with apt npm.
    apt-get purge -y npm >/dev/null 2>&1 || true
    apt-get install -y nodejs
  else
    # Distro packages typically split nodejs and npm.
    apt-get install -y nodejs npm
  fi
else
  echo "apt-get not found; skipping automatic package installation."
fi

echo "[3/8] Ensuring data directory permissions..."
mkdir -p "${APP_DIR}/data"
chown www-data:www-data "${APP_DIR}/data"
chmod 775 "${APP_DIR}/data"

DATA_FILES=(
  "tips.json"
  "admins.json"
  "leaderboards.json"
  "leaderboard-rate-limit.json"
  "analytics.json"
  "achievements.json"
  "seasons.json"
  "webhook-events.json"
  "suspicious-scores.json"
  "admin-sessions.json"
  "stripe-checkout.json"
  "runtime-config.json"
)

for f in "${DATA_FILES[@]}"; do
  touch "${APP_DIR}/data/${f}"
  chown www-data:www-data "${APP_DIR}/data/${f}"
  chmod 664 "${APP_DIR}/data/${f}"
done

# Keep .env owned and writable by www-data
touch "${APP_DIR}/.env"
chown www-data:www-data "${APP_DIR}/.env"
chmod 660 "${APP_DIR}/.env"

ensure_env_key() {
  local key="$1"
  local value="$2"
  if ! grep -qE "^${key}=" "${APP_DIR}/.env"; then
    echo "${key}=${value}" >> "${APP_DIR}/.env"
  fi
}

set_env_if_empty() {
  local key="$1"
  local value="$2"
  if grep -qE "^${key}=$" "${APP_DIR}/.env"; then
    sed -i "s|^${key}=$|${key}=${value}|" "${APP_DIR}/.env"
  fi
}

set_env_secret_if_empty() {
  local key="$1"
  local bytes="$2"

  if ! command -v openssl >/dev/null 2>&1; then
    return 0
  fi

  local generated
  generated="$(openssl rand -hex "${bytes}")"
  if [ -n "${generated}" ]; then
    set_env_if_empty "${key}" "${generated}"
  fi
}

# Seed new payment settings without overwriting existing values.
ensure_env_key "PAYMENT_PROCESSOR" "stripe"
ensure_env_key "BASE_URL" ""
ensure_env_key "ADMIN_DASHBOARD_TOKEN" ""
ensure_env_key "STRIPE_SECRET_KEY" ""
ensure_env_key "STRIPE_PUBLISHABLE_KEY" ""
ensure_env_key "STRIPE_WEBHOOK_SECRET" ""
ensure_env_key "STRIPE_TIER_PRODUCT_IDS" ""
ensure_env_key "STRIPE_TIER_PRICE_IDS" ""
ensure_env_key "BTCPAY_SERVER_URL" ""
ensure_env_key "BTCPAY_API_KEY" ""
ensure_env_key "BTCPAY_STORE_ID" ""
ensure_env_key "BTCPAY_WEBHOOK_SECRET" ""
ensure_env_key "COINBASE_COMMERCE_API_KEY" ""
ensure_env_key "COINBASE_COMMERCE_WEBHOOK_SECRET" ""
ensure_env_key "COINBASE_TIP_AMOUNTS" "5,10,20"
ensure_env_key "COINBASE_CURRENCY" "GBP"
ensure_env_key "COINBASE_SUPPORTED_COINS" "BTC,ETH,LTC,BCH,DOGE,USDC,USDT,XRP"
ensure_env_key "CRYPTO_RECEIVE_ADDRESSES_JSON" "{}"
ensure_env_key "COINBASE_DESTINATION_ADDRESSES_JSON" "{}"
ensure_env_key "CRYPTO_DERIVATION_ENABLED" "0"
ensure_env_key "CRYPTO_DERIVATION_URL" ""
ensure_env_key "CRYPTO_DERIVATION_AUTH_HEADER" "x-webgames-wallet-token"
ensure_env_key "CRYPTO_DERIVATION_AUTH_TOKEN" ""
ensure_env_key "WALLET_SERVICE_PORT" "8787"
ensure_env_key "WALLET_BASE_ADDRESSES_JSON" "{}"
ensure_env_key "WALLET_TAGGED_COINS" "XRP"
ensure_env_key "WALLET_DERIVATION_SECRET" ""
ensure_env_key "CRYPTO_AUTO_VERIFY_ENABLED" "0"
ensure_env_key "CRYPTO_AUTO_VERIFY_PROVIDER_URL" ""
ensure_env_key "CRYPTO_AUTO_VERIFY_AUTH_HEADER" "x-webgames-verify-token"
ensure_env_key "CRYPTO_AUTO_VERIFY_AUTH_TOKEN" ""
ensure_env_key "CRYPTO_AUTO_VERIFY_MIN_CONFIRMATIONS" "1"
ensure_env_key "WALLET_APP_INTERNAL_BASE_URL" "http://127.0.0.1"
ensure_env_key "CRYPTO_ASSET" "USDC"
ensure_env_key "CRYPTO_RECEIVE_ADDRESS" ""
ensure_env_key "COINBASE_DESTINATION_ACCOUNT" ""
ensure_env_key "COINBASE_TRANSFER_REQUEST_URL" ""
ensure_env_key "COINBASE_TRANSFER_AUTH_HEADER" "x-coinbase-transfer-token"
ensure_env_key "COINBASE_TRANSFER_AUTH_TOKEN" ""
ensure_env_key "WEBHOOK_FORWARD_URL" ""
ensure_env_key "WEBHOOK_FORWARD_AUTH_HEADER" "x-webgames-proxy-token"
ensure_env_key "WEBHOOK_FORWARD_AUTH_TOKEN" ""

set_env_secret_if_empty "ADMIN_DASHBOARD_TOKEN" 32
set_env_secret_if_empty "CRYPTO_DERIVATION_AUTH_TOKEN" 32
set_env_secret_if_empty "WALLET_DERIVATION_SECRET" 32
set_env_secret_if_empty "CRYPTO_AUTO_VERIFY_AUTH_TOKEN" 32
set_env_secret_if_empty "COINBASE_TRANSFER_AUTH_TOKEN" 32
set_env_secret_if_empty "WEBHOOK_FORWARD_AUTH_TOKEN" 32

if grep -qE '^CRYPTO_AUTO_VERIFY_PROVIDER_URL=$' "${APP_DIR}/.env"; then
  sed -i 's|^CRYPTO_AUTO_VERIFY_PROVIDER_URL=$|CRYPTO_AUTO_VERIFY_PROVIDER_URL=http://127.0.0.1:8787/api/verify-tx|' "${APP_DIR}/.env"
fi

echo "[4/8] Verifying required served files..."
REQUIRED_FILES=(
  "admin.php"
  "installer.php"
  "index.html"
  "tip.html"
  "styles.css"
  "app.js"
  "success.html"
  "success.js"
  "public/index.html"
  "public/tip.html"
  "public/app.js"
  "public/success.html"
  "public/success.js"
  "public/admin.php"
  "public/admin-advanced.html"
  "api/admin-analytics.php"
  "api/create-tip-session.php"
  "api/tip-tiers.php"
  "api/tip-session.php"
  "api/crypto-quote.php"
  "api/submit-crypto-payment.php"
  "api/admin-tips.php"
  "api/stripe-webhook.php"
  "api/btcpay-webhook.php"
  "wallet-service/package.json"
  "wallet-service/index.js"
  "scripts/deploy.sh"
)

for f in "${REQUIRED_FILES[@]}"; do
  if [ ! -f "${APP_DIR}/${f}" ]; then
    echo "Missing required file after deploy: ${APP_DIR}/${f}"
    exit 1
  fi
done

echo "[5/8] Installing wallet service dependencies and ensuring service..."
if [ -f "${APP_DIR}/wallet-service/package.json" ]; then
  if command -v npm >/dev/null 2>&1; then
    (cd "${APP_DIR}/wallet-service" && npm install --omit=dev)
  else
    echo "npm not found; skipping wallet-service dependency install."
  fi

  WALLET_SYSTEMD_FILE="/etc/systemd/system/webgames-wallet.service"
  cat > "${WALLET_SYSTEMD_FILE}" <<'UNIT'
[Unit]
Description=webgames wallet derivation service
After=network.target

[Service]
Type=simple
WorkingDirectory=/var/www/webgames/wallet-service
ExecStart=/usr/bin/node /var/www/webgames/wallet-service/index.js
Restart=always
RestartSec=2
User=www-data
Group=www-data
EnvironmentFile=/var/www/webgames/.env

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable webgames-wallet.service
systemctl restart webgames-wallet.service

if grep -qE '^CRYPTO_DERIVATION_URL=$' "${APP_DIR}/.env"; then
  sed -i 's|^CRYPTO_DERIVATION_URL=$|CRYPTO_DERIVATION_URL=http://127.0.0.1:8787/api/derive-addresses|' "${APP_DIR}/.env"
fi
else
  echo "wallet-service files missing; skipping wallet service setup."
fi

echo "[6/8] Refreshing PHP runtime cache..."
PHP_FPM_SERVICE=""
if systemctl list-unit-files | grep -qE '^php[0-9]+\.[0-9]+-fpm\.service'; then
  PHP_FPM_SERVICE="$(systemctl list-unit-files | awk '/^php[0-9]+\.[0-9]+-fpm\.service/ {print $1}' | head -n 1)"
fi

if [ -n "${PHP_FPM_SERVICE}" ]; then
  systemctl reload "${PHP_FPM_SERVICE}" || systemctl restart "${PHP_FPM_SERVICE}"
else
  echo "No php-fpm service detected; skipping php-fpm reload."
fi

echo "[7/8] Reloading nginx..."
nginx -t && systemctl reload nginx

echo ""
echo "✓ Deploy complete."
