#!/usr/bin/env bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root (use sudo)."
  exit 1
fi

DOMAIN="${1:-}"
LETSENCRYPT_EMAIL="${2:-}"

APP_NAME="webgames"
APP_DIR="/var/www/${APP_NAME}"
NGINX_SITE="/etc/nginx/sites-available/${APP_NAME}"
NGINX_SITE_LINK="/etc/nginx/sites-enabled/${APP_NAME}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

export DEBIAN_FRONTEND=noninteractive

echo "[1/10] Installing packages..."
apt-get update
apt-get install -y nginx php-fpm php-curl php-json php-mbstring php-xml php-cli \
  certbot python3-certbot-nginx ufw fail2ban rsync

echo "[2/10] Preparing app directory..."
mkdir -p "${APP_DIR}"
rsync -a --delete \
  --exclude '.git' \
  --exclude 'node_modules' \
  --exclude '.env' \
  "${SRC_DIR}/" "${APP_DIR}/"

echo "[3/10] Setting permissions..."
mkdir -p "${APP_DIR}/data"
chown -R root:root "${APP_DIR}"
find "${APP_DIR}" -type d -exec chmod 755 {} \;
find "${APP_DIR}" -type f -exec chmod 644 {} \;
# Allow www-data to write .env (created by installer.php)
touch "${APP_DIR}/.env"
chown www-data:www-data "${APP_DIR}/.env"
chmod 660 "${APP_DIR}/.env"
# Allow www-data to write data/
chown www-data:www-data "${APP_DIR}/data"
chmod 775 "${APP_DIR}/data"
touch "${APP_DIR}/data/tips.json"
chown www-data:www-data "${APP_DIR}/data/tips.json"
chmod 664 "${APP_DIR}/data/tips.json"

echo "[4/10] Detecting PHP-FPM socket..."
PHP_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n 1 || true)"
if [ -z "${PHP_SOCK}" ]; then
  echo "Unable to detect PHP-FPM socket in /run/php/."
  exit 1
fi

if [ -n "${DOMAIN}" ]; then
  SERVER_NAMES="${DOMAIN} www.${DOMAIN}"
else
  SERVER_NAMES="_"
fi

echo "[5/10] Writing nginx config..."
cat > "${NGINX_SITE}" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${SERVER_NAMES};

    root ${APP_DIR};
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Content-Security-Policy "default-src 'self' https://js.stripe.com https://api.stripe.com; script-src 'self' https://js.stripe.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data:; connect-src 'self' https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; object-src 'none'; base-uri 'self'; frame-ancestors 'self'" always;

    client_max_body_size 4m;
    autoindex off;
    server_tokens off;

    location = / { try_files /public/index.html =404; }
    location = /admin.html { return 301 /admin.php; }
    location = /styles.css { return 301 /public/styles.css; }
    location = /app.js { return 301 /public/app.js; }
    location = /success.html { return 301 /public/success.html; }
    location = /success.js { return 301 /public/success.js; }

    location ^~ /games/ {
      return 301 /public\$request_uri;
    }

    location / {
      try_files \$uri \$uri/ /public/index.html;
    }

    location ~ \.php$ {
      include snippets/fastcgi-php.conf;
      fastcgi_pass unix:${PHP_SOCK};
    }

    location ~ /\. {
      deny all;
    }
}
EOF

echo "[6/10] Enabling nginx site..."
rm -f /etc/nginx/sites-enabled/default
ln -sf "${NGINX_SITE}" "${NGINX_SITE_LINK}"
nginx -t

echo "[7/10] Restarting services..."
systemctl enable nginx
systemctl restart nginx
systemctl enable fail2ban
systemctl restart fail2ban

echo "[8/10] Applying firewall rules..."
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

echo "[9/10] Obtaining TLS certificate if possible..."
if [ -n "${DOMAIN}" ] && [ -n "${LETSENCRYPT_EMAIL}" ]; then
  certbot --nginx --non-interactive --agree-tos --redirect -m "${LETSENCRYPT_EMAIL}" -d "${DOMAIN}" -d "www.${DOMAIN}"
else
  echo "Skipping certbot. Provide domain and email to enable HTTPS automatically."
fi

echo "[10/10] Done."
if [ -n "${DOMAIN}" ]; then
  echo "Open: https://${DOMAIN}/"
  echo "Installer: https://${DOMAIN}/installer.php"
else
  echo "Open: http://<server-ip>/"
  echo "Installer: http://<server-ip>/installer.php"
fi

echo "Next: edit ${APP_DIR}/.env and set STRIPE_SECRET_KEY, ADMIN_DASHBOARD_TOKEN, BASE_URL, STRIPE_WEBHOOK_SECRET."