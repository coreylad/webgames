const tipForm = document.getElementById("tipForm");
const tipMessage = document.getElementById("tipMessage");
const usernameInput = document.getElementById("username");
const paymentProcessorSelect = document.getElementById("paymentProcessor");
const priceIdSelect = document.getElementById("priceId");
const tipSubmit = document.getElementById("tipSubmit");
let activeTipProcessor = "stripe";
const processorTierMap = new Map();
const processorErrors = new Map();

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
  if (processor === "coinbase") {
    tipSubmit.textContent = "Continue with Crypto";
  } else {
    tipSubmit.textContent = "Continue to Stripe";
  }
}

function renderProcessorTiers(processor) {
  const tiers = processorTierMap.get(processor) || [];
  priceIdSelect.innerHTML = "";

  if (tiers.length === 0) {
    priceIdSelect.innerHTML = '<option value="">No tiers available</option>';
    tipSubmit.disabled = true;
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
  tipSubmit.disabled = false;
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
  if (!priceIdSelect || !paymentProcessorSelect) {
    return;
  }

  paymentProcessorSelect.innerHTML = '<option value="">Loading payment methods...</option>';
  priceIdSelect.innerHTML = '<option value="">Loading tiers...</option>';
  tipSubmit.disabled = true;
  processorTierMap.clear();
  processorErrors.clear();

  let stripeAvailable = false;
  let coinbaseAvailable = false;

  // Stripe is the hard fallback path: render it first so a slow/broken crypto
  // setup never leaves the payment selector stuck in a loading state.
  try {
    await loadTiersForProcessor("stripe");
    stripeAvailable = true;
    paymentProcessorSelect.innerHTML = `<option value="stripe">${processorLabel("stripe")}</option>`;
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
    paymentProcessorSelect.innerHTML = "";

    if (stripeAvailable) {
      const stripeOption = document.createElement("option");
      stripeOption.value = "stripe";
      stripeOption.textContent = processorLabel("stripe");
      paymentProcessorSelect.appendChild(stripeOption);
    }

    const coinbaseOption = document.createElement("option");
    coinbaseOption.value = "coinbase";
    coinbaseOption.textContent = coinbaseAvailable
      ? processorLabel("coinbase")
      : `${processorLabel("coinbase")} (not configured)`;
    coinbaseOption.disabled = !coinbaseAvailable;
    paymentProcessorSelect.appendChild(coinbaseOption);

    if (stripeAvailable) {
      paymentProcessorSelect.value = "stripe";
      renderProcessorTiers("stripe");
    } else {
      paymentProcessorSelect.value = "coinbase";
      renderProcessorTiers("coinbase");
    }

    if (!coinbaseAvailable && stripeAvailable) {
      tipMessage.className = "status";
      tipMessage.textContent = "Crypto is currently unavailable. Stripe checkout is ready.";
    } else {
      tipMessage.className = "status";
      tipMessage.textContent = "";
    }

    return;
  }

  paymentProcessorSelect.innerHTML = '<option value="">Unavailable</option>';
  priceIdSelect.innerHTML = '<option value="">Tier loading failed</option>';
  const stripeError = processorErrors.get("stripe") || "Stripe is unavailable.";
  tipMessage.textContent = `${stripeError} Refresh to retry or check payment settings in admin.`;
  tipMessage.className = "status error";
  tipSubmit.disabled = true;
}

if (tipForm) {
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

  if (paymentProcessorSelect) {
    paymentProcessorSelect.addEventListener("change", () => {
      const next = String(paymentProcessorSelect.value || "").trim();
      if (!next) {
        return;
      }

      tipMessage.className = "status";
      tipMessage.textContent = "";
      renderProcessorTiers(next);
    });
  }

  tipForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const formData = new FormData(tipForm);
    const username = String(formData.get("username") || "").trim();
    const processor = String(formData.get("paymentProcessor") || activeTipProcessor).trim();
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
