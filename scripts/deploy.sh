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

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root (use sudo)."
  exit 1
fi

APP_NAME="webgames"
APP_DIR="/var/www/${APP_NAME}"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

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

echo "[1/7] Installing deployment requirements..."
if command -v apt-get >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y
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
else
  echo "apt-get not found; skipping automatic package installation."
fi

echo "[2/7] Pulling latest code..."
cd "${REPO_DIR}"
git pull --ff-only

echo "[3/7] Syncing files to ${APP_DIR}..."
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

echo "[4/7] Ensuring data directory permissions..."
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

# Seed new payment settings without overwriting existing values.
ensure_env_key "PAYMENT_PROCESSOR" "stripe"
ensure_env_key "BASE_URL" ""
ensure_env_key "ADMIN_DASHBOARD_TOKEN" ""
ensure_env_key "STRIPE_SECRET_KEY" ""
ensure_env_key "STRIPE_PUBLISHABLE_KEY" ""
ensure_env_key "STRIPE_WEBHOOK_SECRET" ""
ensure_env_key "STRIPE_TIER_PRODUCT_IDS" ""
ensure_env_key "STRIPE_TIER_PRICE_IDS" ""
ensure_env_key "COINBASE_COMMERCE_API_KEY" ""
ensure_env_key "COINBASE_COMMERCE_WEBHOOK_SECRET" ""
ensure_env_key "COINBASE_TIP_AMOUNTS" "5,10,20"
ensure_env_key "COINBASE_CURRENCY" "USD"
ensure_env_key "COINBASE_SUPPORTED_COINS" "BTC,ETH,LTC,BCH,DOGE,USDC,USDT,XRP"
ensure_env_key "CRYPTO_RECEIVE_ADDRESSES_JSON" "{}"
ensure_env_key "COINBASE_DESTINATION_ADDRESSES_JSON" "{}"
ensure_env_key "CRYPTO_ASSET" "USDC"
ensure_env_key "CRYPTO_RECEIVE_ADDRESS" ""
ensure_env_key "COINBASE_DESTINATION_ACCOUNT" ""
ensure_env_key "COINBASE_TRANSFER_REQUEST_URL" ""
ensure_env_key "COINBASE_TRANSFER_AUTH_HEADER" "x-coinbase-transfer-token"
ensure_env_key "COINBASE_TRANSFER_AUTH_TOKEN" ""
ensure_env_key "WEBHOOK_FORWARD_URL" ""
ensure_env_key "WEBHOOK_FORWARD_AUTH_HEADER" "x-webgames-proxy-token"
ensure_env_key "WEBHOOK_FORWARD_AUTH_TOKEN" ""

echo "[5/7] Verifying required served files..."
REQUIRED_FILES=(
  "admin.php"
  "installer.php"
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
  "scripts/deploy.sh"
)

for f in "${REQUIRED_FILES[@]}"; do
  if [ ! -f "${APP_DIR}/${f}" ]; then
    echo "Missing required file after deploy: ${APP_DIR}/${f}"
    exit 1
  fi
done

echo "[6/7] Refreshing PHP runtime cache..."
PHP_FPM_SERVICE=""
if systemctl list-unit-files | grep -qE '^php[0-9]+\.[0-9]+-fpm\.service'; then
  PHP_FPM_SERVICE="$(systemctl list-unit-files | awk '/^php[0-9]+\.[0-9]+-fpm\.service/ {print $1}' | head -n 1)"
fi

if [ -n "${PHP_FPM_SERVICE}" ]; then
  systemctl reload "${PHP_FPM_SERVICE}" || systemctl restart "${PHP_FPM_SERVICE}"
else
  echo "No php-fpm service detected; skipping php-fpm reload."
fi

echo "[7/7] Reloading nginx..."
nginx -t && systemctl reload nginx

echo ""
echo "✓ Deploy complete."
