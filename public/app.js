const tipForm = document.getElementById("tipForm");
const tipMessage = document.getElementById("tipMessage");
const usernameInput = document.getElementById("username");
const priceIdSelect = document.getElementById("priceId");
const tipSubmit = document.getElementById("tipSubmit");
const tipCryptoSubmit = document.getElementById("tipCryptoSubmit");
const legacyPaymentProcessorSelect = document.getElementById("paymentProcessor");

function sanitizeTipFormUi() {
  if (!tipForm) {
    return;
  }

  // Remove stale or deprecated processor controls and keep Stripe-only UI.
  if (legacyPaymentProcessorSelect) {
    const maybeLabel = tipForm.querySelector('label[for="paymentProcessor"]');
    maybeLabel?.remove();
    legacyPaymentProcessorSelect.remove();
  }

  tipCryptoSubmit?.remove();
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

function renderStripeTiers(tiers) {
  const previousSelection = String(priceIdSelect?.value || "").trim();
  priceIdSelect.innerHTML = "";

  if (tiers.length === 0) {
    priceIdSelect.innerHTML = '<option value="">No tiers available</option>';
    return;
  }

  tiers.forEach((tier, index) => {
    const option = document.createElement("option");
    option.value = tier.id;
    option.textContent = tier.label;
    if (previousSelection !== "" && tier.id === previousSelection) {
      option.selected = true;
    } else if (index === 0) {
      option.selected = true;
    }
    priceIdSelect.appendChild(option);
  });
}

async function loadTipTiers() {
  if (!priceIdSelect) {
    return;
  }

  priceIdSelect.innerHTML = '<option value="">Loading tiers...</option>';
  if (tipSubmit) {
    tipSubmit.disabled = true;
  }

  let response;
  try {
    response = await fetchWithTimeout("/api/tip-tiers.php", {}, 10000);
  } catch (error) {
    tipMessage.textContent = "Timed out loading Stripe tiers.";
    tipMessage.className = "status error";
    return;
  }

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    tipMessage.textContent = payload.error || "Unable to load Stripe tiers.";
    tipMessage.className = "status error";
    return;
  }

  const tiers = Array.isArray(payload.tiers) ? payload.tiers : [];
  if (tiers.length === 0) {
    tipMessage.textContent = "No Stripe tiers were returned.";
    tipMessage.className = "status error";
    return;
  }

  renderStripeTiers(tiers);
  tipMessage.className = "status";
  tipMessage.textContent = "";
  tipSubmit.disabled = false;
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

  tipForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const formData = new FormData(tipForm);
    const username = String(formData.get("username") || "").trim();
    const priceId = String(formData.get("priceId") || "").trim();

    tipMessage.className = "status";
    tipMessage.textContent = "Creating secure Stripe checkout...";
    tipSubmit.disabled = true;

    try {
      const response = await fetch("/api/create-tip-session.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({ username, priceId, processor: "stripe" })
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
