"use strict";

const crypto = require("crypto");
const path = require("path");
const express = require("express");
const helmet = require("helmet");
const rateLimit = require("express-rate-limit");
const dotenv = require("dotenv");
const http = require("http");
const https = require("https");

dotenv.config({ path: path.resolve(__dirname, "../.env") });

const app = express();
const PORT = Number(process.env.WALLET_SERVICE_PORT || 8787);

const AUTH_HEADER = String(process.env.CRYPTO_DERIVATION_AUTH_HEADER || "x-webgames-wallet-token").trim().toLowerCase();
const AUTH_TOKEN = String(process.env.CRYPTO_DERIVATION_AUTH_TOKEN || "").trim();
const BASE_ADDRESSES = parseAddressMap(process.env.WALLET_BASE_ADDRESSES_JSON || "{}");
const TAGGED_COINS = parseCoinSet(process.env.WALLET_TAGGED_COINS || "XRP");
const DERIVATION_SECRET = String(process.env.WALLET_DERIVATION_SECRET || "").trim();
const AUTO_VERIFY_ENABLED = parseBool(process.env.CRYPTO_AUTO_VERIFY_ENABLED || "0");
const AUTO_VERIFY_PROVIDER_URL = String(process.env.CRYPTO_AUTO_VERIFY_PROVIDER_URL || "").trim();
const AUTO_VERIFY_AUTH_HEADER = String(process.env.CRYPTO_AUTO_VERIFY_AUTH_HEADER || "x-webgames-verify-token").trim();
const AUTO_VERIFY_AUTH_TOKEN = String(process.env.CRYPTO_AUTO_VERIFY_AUTH_TOKEN || "").trim();
const AUTO_VERIFY_MIN_CONFIRMATIONS = Math.max(1, Number(process.env.CRYPTO_AUTO_VERIFY_MIN_CONFIRMATIONS || 1));
const APP_INTERNAL_BASE_URL = String(process.env.WALLET_APP_INTERNAL_BASE_URL || "http://127.0.0.1").trim().replace(/\/$/, "");
const ADMIN_TOKEN = String(process.env.ADMIN_DASHBOARD_TOKEN || "").trim();
const POLL_INTERVAL_MS = Math.max(5000, Number(process.env.CRYPTO_AUTO_VERIFY_POLL_INTERVAL_MS || 15000));

const inFlightTipIds = new Set();
let workerLastRunAt = "";
let workerLastError = "";

app.disable("x-powered-by");
app.use(helmet({ contentSecurityPolicy: false }));
app.use(express.json({ limit: "64kb" }));
app.use(rateLimit({
  windowMs: 60 * 1000,
  max: 120,
  standardHeaders: true,
  legacyHeaders: false
}));

function parseAddressMap(raw) {
  try {
    const decoded = JSON.parse(raw);
    if (!decoded || typeof decoded !== "object") {
      return {};
    }
    const out = {};
    for (const [k, v] of Object.entries(decoded)) {
      const coin = String(k || "").trim().toUpperCase();
      const addr = String(v || "").trim();
      if (coin && addr) {
        out[coin] = addr;
      }
    }
    return out;
  } catch {
    return {};
  }
}

function parseCoinSet(raw) {
  const tokens = String(raw || "")
    .split(",")
    .map((x) => x.trim().toUpperCase())
    .filter((x) => /^[A-Z0-9]{2,12}$/.test(x));
  return new Set(tokens);
}

function parseBool(raw) {
  const value = String(raw || "").trim().toLowerCase();
  return ["1", "true", "yes", "on"].includes(value);
}

function requestJson(urlString, method = "GET", body = null, headers = {}) {
  return new Promise((resolve) => {
    let url;
    try {
      url = new URL(urlString);
    } catch {
      resolve({ ok: false, status: 0, json: null, text: "invalid url" });
      return;
    }

    const transport = url.protocol === "https:" ? https : http;
    const payload = body === null ? null : JSON.stringify(body);

    const req = transport.request(
      {
        protocol: url.protocol,
        hostname: url.hostname,
        port: url.port || (url.protocol === "https:" ? 443 : 80),
        path: `${url.pathname}${url.search}`,
        method,
        headers: {
          Accept: "application/json",
          ...(payload ? { "Content-Type": "application/json", "Content-Length": Buffer.byteLength(payload) } : {}),
          ...headers
        },
        timeout: 10000
      },
      (res) => {
        let raw = "";
        res.on("data", (chunk) => {
          raw += chunk;
        });
        res.on("end", () => {
          let parsed = null;
          try {
            parsed = raw ? JSON.parse(raw) : null;
          } catch {
            parsed = null;
          }
          resolve({
            ok: (res.statusCode || 0) >= 200 && (res.statusCode || 0) < 300,
            status: res.statusCode || 0,
            json: parsed,
            text: raw
          });
        });
      }
    );

    req.on("timeout", () => {
      req.destroy();
      resolve({ ok: false, status: 0, json: null, text: "timeout" });
    });

    req.on("error", (err) => {
      resolve({ ok: false, status: 0, json: null, text: err?.message || "request error" });
    });

    if (payload) {
      req.write(payload);
    }
    req.end();
  });
}

function safeEqual(a, b) {
  const lhs = Buffer.from(String(a || ""), "utf8");
  const rhs = Buffer.from(String(b || ""), "utf8");
  if (lhs.length !== rhs.length) {
    return false;
  }
  return crypto.timingSafeEqual(lhs, rhs);
}

function requireAuth(req, res, next) {
  if (!AUTH_TOKEN) {
    return res.status(503).json({ error: "Wallet service auth token is not configured" });
  }

  const headerVal = String(req.headers[AUTH_HEADER] || "").trim();
  if (!safeEqual(headerVal, AUTH_TOKEN)) {
    return res.status(401).json({ error: "Unauthorized" });
  }

  next();
}

function validatePayload(body) {
  const tipId = String(body?.tipId || "").trim();
  const username = String(body?.username || "").trim();
  const currency = String(body?.currency || "USD").trim().toUpperCase();
  const amountCents = Number(body?.amountCents || 0);
  const requestedAt = String(body?.requestedAt || "").trim();

  const coins = Array.isArray(body?.coins)
    ? body.coins
      .map((coin) => String(coin || "").trim().toUpperCase())
      .filter((coin) => /^[A-Z0-9]{2,12}$/.test(coin))
    : [];

  if (!tipId || tipId.length > 128) {
    return { ok: false, error: "tipId is required and must be <= 128 chars" };
  }
  if (!username || username.length > 64) {
    return { ok: false, error: "username is required and must be <= 64 chars" };
  }
  if (!/^[A-Z]{3}$/.test(currency)) {
    return { ok: false, error: "currency must be a 3-letter code" };
  }
  if (!Number.isFinite(amountCents) || amountCents <= 0) {
    return { ok: false, error: "amountCents must be a positive number" };
  }
  if (coins.length === 0) {
    return { ok: false, error: "coins must include at least one symbol" };
  }

  return {
    ok: true,
    value: {
      tipId,
      username,
      currency,
      amountCents: Math.round(amountCents),
      coins,
      requestedAt
    }
  };
}

function buildReference(input) {
  const basis = `${input.tipId}:${input.username}:${input.amountCents}:${input.currency}`;
  return crypto.createHash("sha256").update(basis).digest("hex").slice(0, 24);
}

function deriveDestinationTag(secret, tipId, coin) {
  const digest = crypto
    .createHmac("sha256", secret)
    .update(`${tipId}:${coin}`)
    .digest();

  const num = digest.readUInt32BE(0) % 900000000;
  return String(num + 100000000);
}

app.get("/api/health", (_req, res) => {
  res.json({
    ok: true,
    service: "webgames-wallet-service",
    hasAuthToken: AUTH_TOKEN !== "",
    hasDerivationSecret: DERIVATION_SECRET !== "",
    configuredCoins: Object.keys(BASE_ADDRESSES),
    autoVerify: {
      enabled: AUTO_VERIFY_ENABLED,
      providerConfigured: AUTO_VERIFY_PROVIDER_URL !== "",
      minConfirmations: AUTO_VERIFY_MIN_CONFIRMATIONS,
      lastRunAt: workerLastRunAt,
      lastError: workerLastError
    }
  });
});

app.post("/api/derive-addresses", requireAuth, (req, res) => {
  if (!DERIVATION_SECRET) {
    return res.status(503).json({ error: "WALLET_DERIVATION_SECRET is not configured" });
  }

  const validated = validatePayload(req.body);
  if (!validated.ok) {
    return res.status(400).json({ error: validated.error });
  }

  const input = validated.value;
  const addresses = {};
  const meta = {};

  for (const coin of input.coins) {
    const baseAddress = String(BASE_ADDRESSES[coin] || "").trim();
    if (!baseAddress) {
      continue;
    }

    addresses[coin] = baseAddress;

    if (TAGGED_COINS.has(coin)) {
      meta[coin] = {
        destinationTag: deriveDestinationTag(DERIVATION_SECRET, input.tipId, coin)
      };
    }
  }

  if (Object.keys(addresses).length === 0) {
    return res.status(400).json({
      error: "No wallet base addresses configured for requested coins",
      requestedCoins: input.coins,
      configuredCoins: Object.keys(BASE_ADDRESSES)
    });
  }

  return res.json({
    status: "ok",
    reference: buildReference(input),
    addresses,
    meta
  });
});

async function runAutoVerifyCycle() {
  if (!AUTO_VERIFY_ENABLED) {
    return;
  }
  if (!AUTO_VERIFY_PROVIDER_URL || !ADMIN_TOKEN || !APP_INTERNAL_BASE_URL) {
    return;
  }

  workerLastRunAt = new Date().toISOString();
  workerLastError = "";

  const queueUrl = `${APP_INTERNAL_BASE_URL}/api/admin-analytics.php?action=crypto-transfer-queue&token=${encodeURIComponent(ADMIN_TOKEN)}`;
  const queueRes = await requestJson(queueUrl, "GET", null, {
    "X-Admin-Token": ADMIN_TOKEN,
    "User-Agent": "webgames-wallet-service/auto-verify"
  });

  if (!queueRes.ok || !queueRes.json || queueRes.json.status !== "ok") {
    workerLastError = (queueRes.json && queueRes.json.error) ? String(queueRes.json.error) : `queue fetch failed (${queueRes.status})`;
    return;
  }

  const tips = Array.isArray(queueRes.json.tips) ? queueRes.json.tips : [];
  const candidates = tips.filter((tip) => {
    const tipId = String(tip.id || "");
    return tipId !== ""
      && !inFlightTipIds.has(tipId)
      && String(tip.status || "") === "payment_submitted"
      && String(tip.txHash || "").trim() !== "";
  });

  for (const tip of candidates) {
    const tipId = String(tip.id || "");
    inFlightTipIds.add(tipId);

    try {
      const verifyHeaders = {
        "User-Agent": "webgames-wallet-service/auto-verify"
      };
      if (AUTO_VERIFY_AUTH_TOKEN) {
        verifyHeaders[AUTO_VERIFY_AUTH_HEADER] = AUTO_VERIFY_AUTH_TOKEN;
      }

      const verifyPayload = {
        tipId,
        txHash: String(tip.txHash || ""),
        asset: String(tip.cryptoAsset || "").toUpperCase(),
        receiveAddress: String(tip.receiveAddress || ""),
        amountCents: Number(tip.amountCents || 0),
        currency: String(tip.currency || "USD").toUpperCase(),
        username: String(tip.username || "anonymous"),
        submittedAt: String(tip.submittedAt || ""),
        requestedAt: new Date().toISOString()
      };

      const verifyRes = await requestJson(AUTO_VERIFY_PROVIDER_URL, "POST", verifyPayload, verifyHeaders);
      if (!verifyRes.ok || !verifyRes.json) {
        continue;
      }

      const confirmed = verifyRes.json.confirmed === true;
      const matchesAddress = verifyRes.json.matchesAddress !== false;
      const confirmations = Number(verifyRes.json.confirmations || 0);

      if (!(confirmed && matchesAddress && confirmations >= AUTO_VERIFY_MIN_CONFIRMATIONS)) {
        continue;
      }

      const confirmUrl = `${APP_INTERNAL_BASE_URL}/api/admin-analytics.php?action=confirm-crypto-payment&token=${encodeURIComponent(ADMIN_TOKEN)}`;
      await requestJson(confirmUrl, "POST", {
        tipId,
        txHash: String(tip.txHash || "")
      }, {
        "X-Admin-Token": ADMIN_TOKEN,
        "User-Agent": "webgames-wallet-service/auto-verify"
      });
    } catch (err) {
      workerLastError = err?.message || "auto verify worker error";
    } finally {
      inFlightTipIds.delete(tipId);
    }
  }
}

app.listen(PORT, "127.0.0.1", () => {
  // Deliberately binding to localhost only for safer default deployment.
  console.log(`wallet-service listening on http://127.0.0.1:${PORT}`);

  setTimeout(() => {
    runAutoVerifyCycle();
  }, 3000);

  setInterval(() => {
    runAutoVerifyCycle();
  }, POLL_INTERVAL_MS);
});
