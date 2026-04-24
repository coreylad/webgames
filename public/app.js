const tipForm = document.getElementById("tipForm");
const tipMessage = document.getElementById("tipMessage");
const usernameInput = document.getElementById("username");
const priceIdSelect = document.getElementById("priceId");
const tipSubmit = document.getElementById("tipSubmit");
let tipCryptoSubmit = document.getElementById("tipCryptoSubmit");
const legacyPaymentProcessorSelect = document.getElementById("paymentProcessor");
let activeTipProcessor = "stripe";
const processorTierMap = new Map();
const processorErrors = new Map();

function sanitizeTipFormUi() {
  if (!tipForm) {
    return;
  }

  // If an older cached/stale template is served, remove the broken payment
  // method selector and keep only explicit Stripe/Crypto actions.
  if (legacyPaymentProcessorSelect) {
    const maybeLabel = tipForm.querySelector('label[for="paymentProcessor"]');
    maybeLabel?.remove();
    legacyPaymentProcessorSelect.remove();
  }

  if (!tipCryptoSubmit && tipSubmit) {
    const container = document.createElement("div");
    container.style.display = "grid";
    container.style.gap = "0.65rem";

    tipSubmit.parentNode?.insertBefore(container, tipSubmit);
    container.appendChild(tipSubmit);

    const created = document.createElement("button");
    created.type = "button";
    created.id = "tipCryptoSubmit";
    created.textContent = "Continue with Crypto";
    container.appendChild(created);
    tipCryptoSubmit = created;
  }
}

async function fetchWithTimeout(url, options = {}, timeoutMs = 10000) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

  try {
    return await fetch(url, {
      ...options,
      signal: controller.signal
    });
  } finally {
    clearTimeout(timeoutId);
  }
}

function processorLabel(processor) {
  if (processor === "coinbase") {
    return "Crypto (BTCPay-style)";
  }
  return "Stripe";
}

function updateSubmitLabel(processor) {
  if (tipSubmit) {
    tipSubmit.textContent = "Continue to Stripe";
  }
  if (tipCryptoSubmit) {
    tipCryptoSubmit.textContent = "Continue with Crypto";
  }
}

function setProcessorAvailability() {
  const stripeAvailable = processorTierMap.has("stripe");
  const coinbaseAvailable = processorTierMap.has("coinbase");

  if (tipSubmit) {
    tipSubmit.disabled = !stripeAvailable;
  }
  if (tipCryptoSubmit) {
    tipCryptoSubmit.disabled = !coinbaseAvailable;
  }

  return { stripeAvailable, coinbaseAvailable };
}

function renderProcessorTiers(processor) {
  const tiers = processorTierMap.get(processor) || [];
  priceIdSelect.innerHTML = "";

  if (tiers.length === 0) {
    priceIdSelect.innerHTML = '<option value="">No tiers available</option>';
    return;
  }

  tiers.forEach((tier, index) => {
    const option = document.createElement("option");
    option.value = tier.id;
    option.textContent = tier.label;
    if (index === 0) {
      option.selected = true;
    }
    priceIdSelect.appendChild(option);
  });

  activeTipProcessor = processor;
  updateSubmitLabel(processor);
}

async function loadTiersForProcessor(processor) {
  let response;
  try {
    response = await fetchWithTimeout(`/api/tip-tiers.php?processor=${encodeURIComponent(processor)}`, {}, 10000);
  } catch (error) {
    throw new Error(`Timed out loading ${processorLabel(processor)} tiers.`);
  }

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(payload.error || `Unable to load ${processorLabel(processor)} tiers.`);
  }

  const tiers = Array.isArray(payload.tiers) ? payload.tiers : [];
  if (tiers.length === 0) {
    throw new Error(`No ${processorLabel(processor)} tiers were returned.`);
  }

  processorTierMap.set(processor, tiers);
}

async function loadTipTiers() {
  if (!priceIdSelect) {
    return;
  }

  priceIdSelect.innerHTML = '<option value="">Loading tiers...</option>';
  if (tipSubmit) {
    tipSubmit.disabled = true;
  }
  if (tipCryptoSubmit) {
    tipCryptoSubmit.disabled = true;
  }
  processorTierMap.clear();
  processorErrors.clear();

  let stripeAvailable = false;
  let coinbaseAvailable = false;

  // Stripe is the hard fallback path: render it first so a slow/broken crypto
  // setup never leaves the payment selector stuck in a loading state.
  try {
    await loadTiersForProcessor("stripe");
    stripeAvailable = true;
    renderProcessorTiers("stripe");
    tipMessage.className = "status";
    tipMessage.textContent = "";
  } catch (error) {
    const message = error instanceof Error ? error.message : `Unable to load ${processorLabel("stripe")} tiers.`;
    processorErrors.set("stripe", message);
  }

  try {
    await loadTiersForProcessor("coinbase");
    coinbaseAvailable = true;
  } catch (error) {
    const message = error instanceof Error ? error.message : `Unable to load ${processorLabel("coinbase")} tiers.`;
    processorErrors.set("coinbase", message);
  }

  if (stripeAvailable || coinbaseAvailable) {
    if (stripeAvailable) {
      renderProcessorTiers("stripe");
    } else {
      renderProcessorTiers("coinbase");
    }

    setProcessorAvailability();

    if (!coinbaseAvailable && stripeAvailable) {
      tipMessage.className = "status";
      tipMessage.textContent = "Crypto is currently unavailable. Stripe checkout is ready.";
    } else {
      tipMessage.className = "status";
      tipMessage.textContent = "";
    }

    return;
  }

  priceIdSelect.innerHTML = '<option value="">Tier loading failed</option>';
  const stripeError = processorErrors.get("stripe") || "Stripe is unavailable.";
  tipMessage.textContent = `${stripeError} Refresh to retry or check payment settings in admin.`;
  tipMessage.className = "status error";
  if (tipSubmit) {
    tipSubmit.disabled = true;
  }
  if (tipCryptoSubmit) {
    tipCryptoSubmit.disabled = true;
  }
}

if (tipForm) {
  sanitizeTipFormUi();
  loadTipTiers();

  const cachedName = localStorage.getItem("webgames.username");
  if (cachedName) {
    usernameInput.value = cachedName;
  }

  const query = new URLSearchParams(window.location.search);
  if (query.get("tip") === "cancelled") {
    tipMessage.textContent = "Checkout was canceled. You can try again any time.";
    tipMessage.className = "status error";
  }

  if (tipCryptoSubmit) {
    tipCryptoSubmit.addEventListener("click", () => {
      if (!processorTierMap.has("coinbase")) {
        tipMessage.className = "status error";
        tipMessage.textContent = processorErrors.get("coinbase") || "Crypto is currently unavailable.";
        return;
      }

      renderProcessorTiers("coinbase");
      tipForm.requestSubmit();
    });
  }

  if (tipSubmit) {
    tipSubmit.addEventListener("click", () => {
      if (processorTierMap.has("stripe")) {
        renderProcessorTiers("stripe");
      }
    });
  }

  tipForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const formData = new FormData(tipForm);
    const username = String(formData.get("username") || "").trim();
    const processor = activeTipProcessor;
    const priceId = String(formData.get("priceId") || "").trim();

    tipMessage.className = "status";
    if (processor === "coinbase") {
      tipMessage.textContent = "Preparing local crypto payment instructions...";
    } else {
      tipMessage.textContent = "Creating secure Stripe checkout...";
    }
    tipSubmit.disabled = true;

    try {
      const response = await fetch("/api/create-tip-session.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({ username, priceId, processor })
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || "Unable to start checkout");
      }

      localStorage.setItem("webgames.username", username);
      window.location.href = data.checkoutUrl;
    } catch (error) {
      tipMessage.textContent = error.message;
      tipMessage.className = "status error";
      tipSubmit.disabled = false;
    }
  });
}
