const tipForm = document.getElementById("tipForm");
const tipMessage = document.getElementById("tipMessage");
const usernameInput = document.getElementById("username");
const paymentProcessorSelect = document.getElementById("paymentProcessor");
const priceIdSelect = document.getElementById("priceId");
const tipSubmit = document.getElementById("tipSubmit");
let activeTipProcessor = "stripe";
const processorTierMap = new Map();
const processorErrors = new Map();

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
  const response = await fetch(`/api/tip-tiers.php?processor=${encodeURIComponent(processor)}`);
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

  try {
    const candidates = ["stripe", "coinbase"];
    await Promise.all(candidates.map(async (processor) => {
      try {
        await loadTiersForProcessor(processor);
      } catch (error) {
        const message = error instanceof Error ? error.message : `Unable to load ${processorLabel(processor)} tiers.`;
        processorErrors.set(processor, message);
      }
    }));

    const availableProcessors = candidates.filter((processor) => processorTierMap.has(processor));
    if (availableProcessors.length === 0) {
      throw new Error("No payment methods are currently available.");
    }

    paymentProcessorSelect.innerHTML = "";
    candidates.forEach((processor) => {
      const option = document.createElement("option");
      option.value = processor;
      const available = processorTierMap.has(processor);
      option.textContent = available
        ? processorLabel(processor)
        : `${processorLabel(processor)} (not configured)`;
      option.disabled = !available;
      if (available && !paymentProcessorSelect.value) {
        option.selected = true;
      }
      paymentProcessorSelect.appendChild(option);
    });

    renderProcessorTiers(availableProcessors[0]);

    const unavailable = candidates.filter((processor) => !processorTierMap.has(processor));
    if (unavailable.length > 0) {
      const details = unavailable
        .map((processor) => `${processorLabel(processor)}: ${processorErrors.get(processor) || "not configured"}`)
        .join(" | ");
      tipMessage.className = "status error";
      tipMessage.textContent = `Some methods are unavailable: ${details}`;
    } else {
      tipMessage.className = "status";
      tipMessage.textContent = "";
    }
  } catch (error) {
    paymentProcessorSelect.innerHTML = '<option value="">Unavailable</option>';
    priceIdSelect.innerHTML = '<option value="">Tier loading failed</option>';
    tipMessage.textContent = error.message;
    tipMessage.className = "status error";
  }
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
