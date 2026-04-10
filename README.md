# webgames.lol

PHP-first HTML5 gaming platform with Stripe tipping, advanced leaderboards, achievements system, and comprehensive analytics.

## What is included

### 🎮 Games (9 titles)
- Neon Snake (with pause, combos, wrapping)
- Skyline Pong (classic arcade)
- Pixel Pairs (memory matching)
- Brick Blitz (breakout)
- Laser Grid (dodger)  
- Orbit Defender (shooter)
- Grid Duel (tic-tac-toe)
- Turbo Lane (traffic racer with shields/nitro)
- Meteor Drift (space combat with dash/shooting)

### 🏆 Advanced Leaderboards
- Per-game leaderboards with real-time rankings
- Daily/Weekly/All-time leaderboard splits
- Seasonal leaderboards with automatic resets
- Anti-cheat detection with anomaly scoring
- Suspicious score flagging for admin review
- Player ranking & percentile calculations
- Player score history tracking

### 🎖️ Achievement System
- 15+ achievements across games
- Rarity tiers (common, uncommon, rare, epic, legendary)
- Point-based achievement scoring
- Achievement leaderboard
- Auto-unlock on game events
- Cross-game progression tracking

### 📊 Analytics & Admin Dashboard  
- Revenue analytics by period (day/week/month)
- Player session tracking
- Game-specific play metrics
- Webhook event logging with retry tracking
- Comprehensive admin dashboard at `/admin`
- Suspicious score moderation interface
- Multi-tab admin interface (Overview, Moderation, Achievements, Webhooks)

### 💳 Monetization
- Stripe Checkout integration
- Per-transaction revenue tracking
- Refund/chargeback handling
- Revenue breakdown by type and currency
- Supporter achievements for tippers

### 🔒 Security & Quality
- Webhook replay attack prevention
- Webhook event logging and retries
- Admin token authentication
- Rate-limiting on score submissions
- Username validation (3-24 alphanumeric ± underscore/hyphen)
- Score bounds validation (0-1,000,000,000)
- Content Security Policy headers

## Stack

- PHP 8+
- Stripe API over HTTPS (no SDK required)
- Vanilla HTML/CSS/JavaScript
- JSON data stores (tips, leaderboards, analytics, achievements, webhooks)
- Nginx with CSP headers

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
   - Optional: WEBHOOK_FORWARD_URL and WEBHOOK_FORWARD_AUTH_TOKEN
   - STRIPE_TIER_PRODUCT_IDS (recommended)
   - Optional STRIPE_TIER_PRICE_IDS

5. Open site:

   - Home: http://127.0.0.1:8080/
   - Admin: http://127.0.0.1:8080/admin

## API Endpoints

### Leaderboards
- `GET /api/leaderboard.php?game=<slug>&limit=10` - Get top scores
- `POST /api/leaderboard.php` - Submit score
- `GET /api/leaderboard-advanced-endpoint.php?action=daily&game=<slug>` - Daily leaderboard
- `GET /api/leaderboard-advanced-endpoint.php?action=weekly&game=<slug>` - Weekly leaderboard
- `GET /api/leaderboard-advanced-endpoint.php?action=player-ranking&game=<slug>&username=<user>` - Player rank

### Achievements
- `GET /api/achievements-endpoint.php?action=player&username=<user>` - Player achievements
- `GET /api/achievements-endpoint.php?action=leaderboard` - Top achievement earners
- `POST /api/achievements-endpoint.php?action=earn` - Award achievement

### Analytics (Admin Only)
- `GET /api/admin-analytics.php?action=dashboard` - Main metrics
- `GET /api/admin-analytics.php?action=suspicious-scores` - Flagged scores for review  
- `GET /api/admin-analytics.php?action=webhook-health` - Webhook status
- `POST /api/admin-analytics.php?action=moderate-score` - Approve/reject score

## Data Files

The system uses JSON data files (auto-created in `data/` folder):
- `tips.json` - Stripe tip transactions
- `leaderboards.json` - Game scores and rankings
- `achievements.json` - Earned achievements
- `analytics.json` - Player sessions and events
- `webhook-events.json` - Stripe webhook log
- `suspicious-scores.json` - Flagged scores for moderation
- `seasons.json` - Seasonal leaderboard data
- `admins.json` - Admin accounts
- If neither is set, the app falls back to active one-time prices from your account.

## Stripe webhook setup

Point Stripe webhook endpoint to:

https://your-domain/api/stripe-webhook.php

Local testing with Stripe CLI:

stripe listen --forward-to 127.0.0.1:8080/api/stripe-webhook.php

Copy the webhook signing secret into .env as STRIPE_WEBHOOK_SECRET.

### Optional: forward webhooks to another site (proxy mode)

If you want this site to proxy Stripe webhooks to another endpoint, set these `.env` values:

```
WEBHOOK_FORWARD_URL=https://other-site.example/api/stripe-webhook.php
WEBHOOK_FORWARD_AUTH_HEADER=x-webgames-proxy-token
WEBHOOK_FORWARD_AUTH_TOKEN=shared-secret
```

Behavior:

- Incoming Stripe webhook payloads are still processed locally.
- The same raw JSON payload is POSTed to `WEBHOOK_FORWARD_URL`.
- `Stripe-Signature` header is forwarded when present.
- Proxy headers are added: `X-Webgames-Proxy-Hop`, `X-Webgames-Proxy-Source`, `X-Webgames-Proxy-Event`, `X-Webgames-Proxy-Type`.
- Proxy loop protection: requests with `X-Webgames-Proxy-Hop` already set are not forwarded again.

If your target endpoint needs auth, validate `WEBHOOK_FORWARD_AUTH_HEADER`/`WEBHOOK_FORWARD_AUTH_TOKEN` on that site.

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
   - Admin: https://your-domain.com/admin

## Admin dashboard auth

Admin endpoints require header x-admin-token, validated against ADMIN_DASHBOARD_TOKEN.

## License

MIT
