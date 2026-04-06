# webgames.lol

PHP-first HTML5 gaming website with Stripe tipping and an admin dashboard that stores usernames.

## What is included

- Nine built-in HTML5 games:
  - Neon Snake
  - Skyline Pong
  - Pixel Pairs
  - Brick Blitz
  - Laser Grid
  - Orbit Defender
  - Grid Duel
   - Turbo Lane
   - Meteor Drift
- Persistent per-game leaderboards with username support
- Stripe Checkout tip flow with required username
- Stripe tip tiers pulled from your Stripe product catalogue (products/prices)
- Admin dashboard that displays usernames, tier names, statuses, and totals
- Installer page for easy first-time setup
- Full production-ready source code

## Stack

- PHP 8+
- Stripe API over HTTPS (no SDK required)
- Vanilla HTML/CSS/JavaScript
- JSON data store at data/tips.json

## Quick start (PHP)

1. Copy environment template:

   cp .env.example .env

2. Start local server from project root:

   php -S 127.0.0.1:8080

3. Open installer in browser:

   http://127.0.0.1:8080/installer.php

4. Fill installer fields:

   - STRIPE_SECRET_KEY
   - ADMIN_DASHBOARD_TOKEN
   - BASE_URL
   - STRIPE_TIER_PRODUCT_IDS (recommended)
   - Optional STRIPE_TIER_PRICE_IDS

5. Open site:

   - Home: http://127.0.0.1:8080/
   - Admin: http://127.0.0.1:8080/admin.php

## Stripe catalogue tier behavior

The tip selector uses API endpoint /api/tip-tiers.php.

- If STRIPE_TIER_PRODUCT_IDS is set, active one-time prices from those products are shown.
- If STRIPE_TIER_PRICE_IDS is set, those prices are also included.
- If neither is set, the app falls back to active one-time prices from your account.

## Stripe webhook setup

Point Stripe webhook endpoint to:

https://your-domain/api/stripe-webhook.php

Local testing with Stripe CLI:

stripe listen --forward-to 127.0.0.1:8080/api/stripe-webhook.php

Copy the webhook signing secret into .env as STRIPE_WEBHOOK_SECRET.

## Linux-first deployment notes

1. Install PHP 8+, curl extension, and nginx on your Linux server.
2. Deploy files to web root and ensure write access for data/tips.json.
3. Configure BASE_URL to production domain:

   BASE_URL=https://webgames.lol

4. Use HTTPS and set Stripe webhook to production endpoint.
5. Remove or protect installer.php after setup.

## Debian one-command web stack install + hardening

Run this from the project root on Debian/Ubuntu:

```
chmod +x scripts/debian-install.sh
sudo ./scripts/debian-install.sh your-domain.com admin@your-domain.com
```

What this command does:

- Installs nginx, PHP-FPM (with curl/json/mbstring/xml), certbot, ufw, fail2ban, and rsync
- Deploys this repo to /var/www/webgames
- Sets safe ownership and write access for data/tips.json
- Creates nginx site config with security headers and hidden-file blocking
- Enables firewall rules (OpenSSH + Nginx Full)
- Enables fail2ban
- Requests and installs a Let's Encrypt certificate if domain/email are provided

After it finishes:

- App: https://your-domain.com/
- Installer: https://your-domain.com/installer.php
   - Admin: https://your-domain.com/admin.php

## Admin dashboard auth

Admin endpoints require header x-admin-token, validated against ADMIN_DASHBOARD_TOKEN.

## License

MIT
