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

echo "[1/5] Pulling latest code..."
cd "${REPO_DIR}"
git pull --ff-only

echo "[2/5] Syncing files to ${APP_DIR}..."
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

echo "[3/5] Ensuring data directory permissions..."
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
ensure_env_key "STRIPE_SECRET_KEY" ""
ensure_env_key "STRIPE_PUBLISHABLE_KEY" ""
ensure_env_key "STRIPE_WEBHOOK_SECRET" ""
ensure_env_key "STRIPE_TIER_PRODUCT_IDS" ""
ensure_env_key "STRIPE_TIER_PRICE_IDS" ""
ensure_env_key "PAYPAL_CLIENT_ID" ""
ensure_env_key "PAYPAL_CLIENT_SECRET" ""
ensure_env_key "PAYPAL_WEBHOOK_ID" ""
ensure_env_key "PAYPAL_ENV" "sandbox"
ensure_env_key "PAYPAL_CURRENCY" "USD"
ensure_env_key "PAYPAL_TIP_AMOUNTS" "5,10,20"
ensure_env_key "PAYPAL_CHECKOUT_URL" ""

echo "[4/5] Verifying required served files..."
REQUIRED_FILES=(
  "index.php"
  "admin.php"
  "public/index.html"
  "public/admin.php"
  "public/admin-advanced.html"
  "api/admin-analytics.php"
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

echo "[5/5] Reloading nginx..."
nginx -t && systemctl reload nginx

echo ""
echo "✓ Deploy complete."
