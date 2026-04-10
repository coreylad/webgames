#!/usr/bin/env bash
# ── add-admin.sh ─────────────────────────────────────────────────────────────
# Create a new admin account for the /admin dashboard.
#
# Usage:
#   sudo bash scripts/add-admin.sh [--username NAME] [--password PASS]
#
# Or from anywhere:  sudo bash /var/www/webgames/scripts/add-admin.sh
# -----------------------------------------------------------------------------
set -euo pipefail

APP_DIR="/var/www/webgames"
ADMINS_FILE="${APP_DIR}/data/admins.json"

USERNAME=""
PASSWORD=""

# ── Argument parsing ──────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        --username)  USERNAME="$2"; shift 2 ;;
        --password)  PASSWORD="$2"; shift 2 ;;
        *)
            echo "Unknown option: $1"
            echo "Usage: sudo bash add-admin.sh [--username NAME] [--password PASS]"
            exit 1
            ;;
    esac
done

# ── Prompt if not supplied ────────────────────────────────────────────────────
if [[ -z "$USERNAME" ]]; then
    read -rp "Username (3-24 chars, lowercase letters/numbers/_/-): " USERNAME
fi

if [[ -z "$PASSWORD" ]]; then
    while true; do
        read -rsp "Password (min 8 chars): " PASSWORD
        echo
        read -rsp "Confirm password: " PASSWORD2
        echo
        if [[ "$PASSWORD" == "$PASSWORD2" ]]; then
            break
        fi
        echo "Passwords do not match. Try again."
    done
fi

# ── Sanity checks before calling PHP ─────────────────────────────────────────
if [[ ! -f "$ADMINS_FILE" ]]; then
    echo "Error: admins file not found at ${ADMINS_FILE}"
    echo "Make sure the app is deployed to ${APP_DIR} first."
    exit 1
fi

if ! command -v php &>/dev/null; then
    echo "Error: php CLI not found. Install php-cli and retry."
    exit 1
fi

# ── Delegate to PHP for bcrypt hashing and JSON manipulation ──────────────────
php -r "
\$username  = strtolower(trim('$(printf '%s' "$USERNAME" | sed "s/'/'\\\\''/g")'));
\$password  = '$(printf '%s' "$PASSWORD" | sed "s/'/'\\\\''/g")';
\$file      = '${ADMINS_FILE}';

// Validate username
if (!preg_match('/^[a-z0-9_-]{3,24}$/', \$username)) {
    fwrite(STDERR, \"Error: Username must be 3-24 chars: lowercase letters, numbers, _ or -.\n\");
    exit(1);
}

// Validate password length
if (strlen(\$password) < 8) {
    fwrite(STDERR, \"Error: Password must be at least 8 characters.\n\");
    exit(1);
}

// Read store
\$raw = file_get_contents(\$file);
\$store = (\$raw !== false && \$raw !== '') ? json_decode(\$raw, true) : null;
if (!is_array(\$store) || !isset(\$store['admins'])) {
    \$store = ['admins' => []];
}

// Check duplicate
foreach (\$store['admins'] as \$admin) {
    if ((\$admin['username'] ?? '') === \$username) {
        fwrite(STDERR, \"Error: Username '\$username' already exists.\n\");
        exit(1);
    }
}

// Create record
\$record = [
    'id'        => bin2hex(random_bytes(16)),
    'username'  => \$username,
    'tokenHash' => password_hash(\$password, PASSWORD_DEFAULT),
    'createdAt' => (new DateTime())->format(DateTime::ATOM),
];
\$store['admins'][] = \$record;

// Write back
file_put_contents(\$file, json_encode(\$store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo \"Admin '\$username' created successfully.\n\";
"
