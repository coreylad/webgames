const express = require("express");
const dotenv = require("dotenv");
const fs = require("fs");
const path = require("path");
const crypto = require("crypto");
const Stripe = require("stripe");

dotenv.config();

const app = express();
const PORT = Number(process.env.PORT || 3000);
const BASE_URL = process.env.BASE_URL || `http://localhost:${PORT}`;
const ADMIN_DASHBOARD_TOKEN = process.env.ADMIN_DASHBOARD_TOKEN || "dev-admin-token";
const STRIPE_SECRET_KEY = process.env.STRIPE_SECRET_KEY || "";
const STRIPE_WEBHOOK_SECRET = process.env.STRIPE_WEBHOOK_SECRET || "";

const stripe = STRIPE_SECRET_KEY ? new Stripe(STRIPE_SECRET_KEY) : null;

const dataDir = path.join(__dirname, "data");
const tipsFile = path.join(dataDir, "tips.json");

function ensureTipStore() {
  if (!fs.existsSync(dataDir)) {
    fs.mkdirSync(dataDir, { recursive: true });
  }

  if (!fs.existsSync(tipsFile)) {
    fs.writeFileSync(tipsFile, JSON.stringify({ tips: [] }, null, 2));
  }
}

function readTipStore() {
  ensureTipStore();

  try {
    const raw = fs.readFileSync(tipsFile, "utf-8");
    const parsed = JSON.parse(raw);

    if (!Array.isArray(parsed.tips)) {
      return { tips: [] };
    }

    return parsed;
  } catch {
    return { tips: [] };
  }
}

function writeTipStore(store) {
  ensureTipStore();
  fs.writeFileSync(tipsFile, JSON.stringify(store, null, 2));
}

function addTipRecord(tip) {
  const store = readTipStore();
  store.tips.push(tip);
  writeTipStore(store);
  return tip;
}

function updateTip(predicate, updates) {
  const store = readTipStore();
  const index = store.tips.findIndex(predicate);

  if (index === -1) {
    return null;
  }

  store.tips[index] = {
    ...store.tips[index],
    ...updates,
    updatedAt: new Date().toISOString()
  };

  writeTipStore(store);
  return store.tips[index];
}

function parseTipAmount(raw) {
  const parsed = Number(raw);

  if (!Number.isFinite(parsed)) {
    return null;
  }

  const amount = Math.round(parsed);
  const minCents = 100;
  const maxCents = 50000;

  if (amount < minCents || amount > maxCents) {
    return null;
  }

  return amount;
}

function isValidUsername(name) {
  return /^[a-zA-Z0-9_-]{3,24}$/.test(name || "");
}

function requireAdmin(req, res, next) {
  const token = req.header("x-admin-token") || req.query.token;

  if (!token || token !== ADMIN_DASHBOARD_TOKEN) {
    return res.status(401).json({ error: "Unauthorized" });
  }

  return next();
}

app.post("/api/stripe-webhook", express.raw({ type: "application/json" }), (req, res) => {
  if (!stripe) {
    return res.status(500).send("Stripe is not configured on the server");
  }

  let event;

  try {
    const signature = req.headers["stripe-signature"];

    if (STRIPE_WEBHOOK_SECRET && signature) {
      event = stripe.webhooks.constructEvent(req.body, signature, STRIPE_WEBHOOK_SECRET);
    } else {
      event = JSON.parse(req.body.toString("utf8"));
    }
  } catch (error) {
    return res.status(400).send(`Webhook Error: ${error.message}`);
  }

  if (event.type === "checkout.session.completed") {
    const session = event.data.object;
    const metadata = session.metadata || {};

    const update = {
      username: metadata.username || "anonymous",
      amountCents: session.amount_total || null,
      currency: session.currency || "usd",
      status: session.payment_status === "paid" ? "paid" : "completed",
      sessionId: session.id,
      customerEmail: session.customer_details?.email || "",
      paidAt: new Date().toISOString(),
      paymentIntentId: session.payment_intent || ""
    };

    if (metadata.tipRecordId) {
      updateTip((tip) => tip.id === metadata.tipRecordId, update);
    } else {
      updateTip((tip) => tip.sessionId === session.id, update);
    }
  }

  res.json({ received: true });
});

app.use(express.json());
app.use(express.static(path.join(__dirname, "public")));

app.get("/api/health", (req, res) => {
  res.json({ ok: true, app: "webgames.lol" });
});

app.post("/api/create-tip-session", async (req, res) => {
  if (!stripe) {
    return res.status(500).json({ error: "Stripe is not configured. Add STRIPE_SECRET_KEY in .env." });
  }

  const username = (req.body.username || "").trim();
  const amountCents = parseTipAmount(req.body.amountCents);

  if (!isValidUsername(username)) {
    return res.status(400).json({
      error: "Username must be 3-24 characters and only include letters, numbers, _ or -."
    });
  }

  if (!amountCents) {
    return res.status(400).json({ error: "Amount must be between $1.00 and $500.00" });
  }

  const tipRecord = addTipRecord({
    id: crypto.randomUUID(),
    username,
    amountCents,
    currency: "usd",
    status: "checkout_pending",
    sessionId: "",
    customerEmail: "",
    paymentIntentId: "",
    createdAt: new Date().toISOString(),
    updatedAt: new Date().toISOString()
  });

  try {
    const session = await stripe.checkout.sessions.create({
      mode: "payment",
      success_url: `${BASE_URL}/success.html?session_id={CHECKOUT_SESSION_ID}`,
      cancel_url: `${BASE_URL}/?tip=cancelled`,
      line_items: [
        {
          quantity: 1,
          price_data: {
            currency: "usd",
            unit_amount: amountCents,
            product_data: {
              name: "Tip for webgames.lol",
              description: `Support from ${username}`
            }
          }
        }
      ],
      metadata: {
        username,
        tipRecordId: tipRecord.id
      }
    });

    updateTip(
      (tip) => tip.id === tipRecord.id,
      {
        status: "checkout_created",
        sessionId: session.id
      }
    );

    return res.json({ checkoutUrl: session.url });
  } catch (error) {
    updateTip(
      (tip) => tip.id === tipRecord.id,
      {
        status: "checkout_failed"
      }
    );

    return res.status(500).json({ error: error.message || "Unable to create Stripe session" });
  }
});

app.get("/api/tip-session/:sessionId", (req, res) => {
  const { sessionId } = req.params;
  const store = readTipStore();
  const tip = store.tips.find((item) => item.sessionId === sessionId);

  if (!tip) {
    return res.status(404).json({ error: "Tip session not found" });
  }

  return res.json({
    username: tip.username,
    amountCents: tip.amountCents,
    status: tip.status,
    paidAt: tip.paidAt || null
  });
});

app.get("/api/admin/tips", requireAdmin, (req, res) => {
  const store = readTipStore();
  const tips = [...store.tips].sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));
  res.json({ tips });
});

app.get("/api/admin/summary", requireAdmin, (req, res) => {
  const store = readTipStore();
  const uniqueUsers = [...new Set(store.tips.map((tip) => tip.username))];

  const totalPaidCents = store.tips
    .filter((tip) => tip.status === "paid")
    .reduce((sum, tip) => sum + (Number(tip.amountCents) || 0), 0);

  res.json({
    uniqueUsernames: uniqueUsers,
    totalTips: store.tips.length,
    totalPaidCents
  });
});

app.get("/admin", (req, res) => {
  res.sendFile(path.join(__dirname, "public", "admin.html"));
});

app.listen(PORT, () => {
  ensureTipStore();

  if (!STRIPE_SECRET_KEY) {
    console.warn("[webgames.lol] Stripe key missing. Add STRIPE_SECRET_KEY to enable tipping.");
  }

  if (ADMIN_DASHBOARD_TOKEN === "dev-admin-token") {
    console.warn("[webgames.lol] Using default admin token. Set ADMIN_DASHBOARD_TOKEN in .env.");
  }

  console.log(`[webgames.lol] running on ${BASE_URL}`);
});
