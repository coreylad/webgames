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
BTCPAY_BASE_DIR="/opt/webgames-btcpay"
BTCPAY_COMPOSE_FILE="${BTCPAY_BASE_DIR}/docker-compose.yml"
BTCPAY_NGINX_SNIPPET="/etc/nginx/snippets/webgames-btcpay-location.conf"

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
    docker.io \
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

  # Docker Compose package names vary by distro/repository.
  apt-get install -y docker-compose-plugin >/dev/null 2>&1 || true
  apt-get install -y docker-compose >/dev/null 2>&1 || true

  if ! command -v docker >/dev/null 2>&1; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc

    . /etc/os-release
    echo \
      "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian ${VERSION_CODENAME} stable" \
      > /etc/apt/sources.list.d/docker.list

    apt-get update -y
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  fi

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

  if ! command -v docker >/dev/null 2>&1; then
    echo "Docker installation did not provide a docker command."
    exit 1
  fi
elif command -v dnf >/dev/null 2>&1; then
  dnf install -y \
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
    php-gmp \
    nodejs \
    npm

  if ! command -v docker >/dev/null 2>&1; then
    dnf install -y dnf-plugins-core
    dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
    dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  else
    dnf install -y docker-compose-plugin >/dev/null 2>&1 || true
  fi
elif command -v yum >/dev/null 2>&1; then
  yum install -y \
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
    php-gmp \
    nodejs \
    npm

  if ! command -v docker >/dev/null 2>&1; then
    yum install -y yum-utils
    yum-config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
    yum install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  else
    yum install -y docker-compose-plugin >/dev/null 2>&1 || true
  fi
else
  echo "No supported package manager found; skipping automatic package installation."
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

get_env_value() {
  local key="$1"
  if [ ! -f "${APP_DIR}/.env" ]; then
    return 0
  fi

  grep -E "^${key}=" "${APP_DIR}/.env" | tail -n 1 | cut -d '=' -f 2-
}

is_falsy() {
  local raw="$(echo "${1:-}" | tr '[:upper:]' '[:lower:]' | xargs)"
  case "${raw}" in
    0|false|no|off) return 0 ;;
    *) return 1 ;;
  esac
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
ensure_env_key "BTCPAY_INSTALL_ENABLED" "1"
ensure_env_key "BTCPAY_EXTERNAL_URL" "https://webgames.lol/btcpay/"
ensure_env_key "BTCPAY_INTERNAL_PORT" "23000"
ensure_env_key "BTCPAY_POSTGRES_PASSWORD" ""
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
set_env_secret_if_empty "BTCPAY_POSTGRES_PASSWORD" 24

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

echo "[6/8] Ensuring BTCPay Server is installed and routed at /btcpay..."
BTCPAY_INSTALL_ENABLED_RAW="$(get_env_value "BTCPAY_INSTALL_ENABLED")"
if is_falsy "${BTCPAY_INSTALL_ENABLED_RAW}"; then
  echo "BTCPAY_INSTALL_ENABLED is disabled; skipping BTCPay setup."
else
  if ! command -v docker >/dev/null 2>&1; then
    echo "docker command not found; cannot configure BTCPay."
    exit 1
  fi

  systemctl enable docker >/dev/null 2>&1 || true
  systemctl start docker

  BTCPAY_EXTERNAL_URL_VALUE="$(get_env_value "BTCPAY_EXTERNAL_URL")"
  if [ -z "${BTCPAY_EXTERNAL_URL_VALUE}" ]; then
    BTCPAY_EXTERNAL_URL_VALUE="https://webgames.lol/btcpay/"
  fi

  BTCPAY_INTERNAL_PORT_VALUE="$(get_env_value "BTCPAY_INTERNAL_PORT")"
  if [ -z "${BTCPAY_INTERNAL_PORT_VALUE}" ]; then
    BTCPAY_INTERNAL_PORT_VALUE="23000"
  fi

  BTCPAY_POSTGRES_PASSWORD_VALUE="$(get_env_value "BTCPAY_POSTGRES_PASSWORD")"
  if [ -z "${BTCPAY_POSTGRES_PASSWORD_VALUE}" ]; then
    echo "BTCPAY_POSTGRES_PASSWORD is empty; unable to continue BTCPay setup."
    exit 1
  fi

  # Keep API integration endpoint aligned if not already set by admin.
  set_env_if_empty "BTCPAY_SERVER_URL" "${BTCPAY_EXTERNAL_URL_VALUE%/}"

  mkdir -p "${BTCPAY_BASE_DIR}"
  mkdir -p /etc/nginx/snippets

  cat > "${BTCPAY_COMPOSE_FILE}" <<EOF
version: "3.8"

services:
  btcpay-db:
    image: postgres:16-alpine
    container_name: webgames-btcpay-db
    restart: unless-stopped
    environment:
      POSTGRES_DB: btcpay
      POSTGRES_USER: btcpay
      POSTGRES_PASSWORD: ${BTCPAY_POSTGRES_PASSWORD_VALUE}
    volumes:
      - btcpay_db_data:/var/lib/postgresql/data

  btcpay-server:
    image: btcpayserver/btcpayserver:latest
    container_name: webgames-btcpay
    restart: unless-stopped
    depends_on:
      - btcpay-db
    environment:
      ASPNETCORE_URLS: http://0.0.0.0:${BTCPAY_INTERNAL_PORT_VALUE}
      BTCPAY_POSTGRES: User ID=btcpay;Password=${BTCPAY_POSTGRES_PASSWORD_VALUE};Host=btcpay-db;Port=5432;Database=btcpay;
      BTCPAY_ROOTPATH: /btcpay
      BTCPAY_HOST: ${BTCPAY_EXTERNAL_URL_VALUE%/}
    ports:
      - 127.0.0.1:${BTCPAY_INTERNAL_PORT_VALUE}:${BTCPAY_INTERNAL_PORT_VALUE}
    volumes:
      - btcpay_data:/datadir

volumes:
  btcpay_db_data:
  btcpay_data:
EOF

  if docker compose version >/dev/null 2>&1; then
    docker compose -f "${BTCPAY_COMPOSE_FILE}" pull
    docker compose -f "${BTCPAY_COMPOSE_FILE}" up -d
  elif command -v docker-compose >/dev/null 2>&1; then
    docker-compose -f "${BTCPAY_COMPOSE_FILE}" pull
    docker-compose -f "${BTCPAY_COMPOSE_FILE}" up -d
  else
    echo "Neither docker compose plugin nor docker-compose binary is available."
    exit 1
  fi

  cat > "${BTCPAY_NGINX_SNIPPET}" <<EOF
location = /btcpay {
    return 301 /btcpay/;
}

location /btcpay/ {
    proxy_pass http://127.0.0.1:${BTCPAY_INTERNAL_PORT_VALUE}/;
    proxy_http_version 1.1;
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
    proxy_set_header X-Forwarded-Host \$host;
    proxy_set_header X-Forwarded-Prefix /btcpay;
    proxy_set_header X-Forwarded-PathBase /btcpay;
    proxy_read_timeout 120s;
}
EOF

  BTCPAY_INCLUDE_LINE="include ${BTCPAY_NGINX_SNIPPET};"
  mapfile -t BTCPAY_NGINX_TARGETS < <(grep -RIl "server_name[[:space:]].*webgames\\.lol" /etc/nginx/sites-enabled /etc/nginx/sites-available 2>/dev/null | sort -u)

  if [ "${#BTCPAY_NGINX_TARGETS[@]}" -eq 0 ]; then
    echo "No nginx server block with server_name webgames.lol was found."
    echo "Add this line inside your webgames.lol server block and rerun deploy: ${BTCPAY_INCLUDE_LINE}"
    exit 1
  fi

  for cfg in "${BTCPAY_NGINX_TARGETS[@]}"; do
    if ! grep -qF "${BTCPAY_INCLUDE_LINE}" "${cfg}"; then
      sed -i "/server_name[[:space:]].*webgames\\.lol/a\\    ${BTCPAY_INCLUDE_LINE}" "${cfg}"
    fi
  done
fi

echo "[7/8] Refreshing PHP runtime cache..."
PHP_FPM_SERVICE=""
if systemctl list-unit-files | grep -qE '^php[0-9]+\.[0-9]+-fpm\.service'; then
  PHP_FPM_SERVICE="$(systemctl list-unit-files | awk '/^php[0-9]+\.[0-9]+-fpm\.service/ {print $1}' | head -n 1)"
fi

if [ -n "${PHP_FPM_SERVICE}" ]; then
  systemctl reload "${PHP_FPM_SERVICE}" || systemctl restart "${PHP_FPM_SERVICE}"
else
  echo "No php-fpm service detected; skipping php-fpm reload."
fi

echo "[8/8] Reloading nginx..."
nginx -t && systemctl reload nginx

echo ""
echo "✓ Deploy complete."
