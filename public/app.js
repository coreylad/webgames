const tipForm = document.getElementById("tipForm");
const tipMessage = document.getElementById("tipMessage");
const usernameInput = document.getElementById("username");
const priceIdSelect = document.getElementById("priceId");
const tipSubmit = document.getElementById("tipSubmit");
let activeTipProcessor = "stripe";

async function loadTipTiers() {
  if (!priceIdSelect) {
    return;
  }

  priceIdSelect.innerHTML = '<option value="">Loading tiers...</option>';
  tipSubmit.disabled = true;

  try {
    const response = await fetch("/api/tip-tiers.php");
    const payload = await response.json();

    if (!response.ok) {
      throw new Error(payload.error || "Unable to load Stripe tip tiers.");
    }

    const tiers = Array.isArray(payload.tiers) ? payload.tiers : [];
    if (tiers.length === 0) {
      throw new Error("No tip tiers were returned.");
    }

    activeTipProcessor = payload.processor === "paypal" ? "paypal" : "stripe";

    priceIdSelect.innerHTML = "";
    tiers.forEach((tier, index) => {
      const option = document.createElement("option");
      option.value = tier.id;
      option.textContent = tier.label;
      if (index === 0) {
        option.selected = true;
      }
      priceIdSelect.appendChild(option);
    });

    tipSubmit.textContent = activeTipProcessor === "paypal" ? "Continue to PayPal" : "Continue to Stripe";

    tipSubmit.disabled = false;
  } catch (error) {
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

  tipForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const formData = new FormData(tipForm);
    const username = String(formData.get("username") || "").trim();
    const priceId = String(formData.get("priceId") || "").trim();

    tipMessage.className = "status";
    tipMessage.textContent = activeTipProcessor === "paypal"
      ? "Redirecting to PayPal checkout..."
      : "Creating secure Stripe checkout...";
    tipSubmit.disabled = true;

    try {
      const response = await fetch("/api/create-tip-session.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({ username, priceId })
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
