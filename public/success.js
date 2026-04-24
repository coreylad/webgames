const successMessage = document.getElementById("successMessage");

function escapeHtml(value) {
  return String(value || "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function formatMoney(amountCents, currency) {
  const value = (Number(amountCents || 0) / 100).toFixed(2);
  return `${value} ${String(currency || "USD").toUpperCase()}`;
}

async function loadCryptoQuote(sessionId, asset) {
  const response = await fetch(`/api/crypto-quote.php?session_id=${encodeURIComponent(sessionId)}&asset=${encodeURIComponent(asset)}`);
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.status !== "ok") {
    throw new Error(data.error || "Unable to load quote for selected coin.");
  }
  return data;
}

function setCryptoActions(sessionId, data) {
  const amountLabel = formatMoney(data.amountCents, data.currency);
  const supportedAssets = Array.isArray(data.supportedAssets) && data.supportedAssets.length > 0
    ? data.supportedAssets
    : [data.cryptoAsset || "BTC"];
  const defaultAsset = supportedAssets.includes(data.cryptoAsset) ? data.cryptoAsset : supportedAssets[0];
  const txHash = data.txHash || "";

  successMessage.innerHTML =
    `BTCPay-inspired crypto checkout: send <strong>${escapeHtml(amountLabel)}</strong> using your preferred coin, then submit your tx hash for confirmation.`;

  const host = document.querySelector(".success-card");
  if (!host || document.getElementById("cryptoTxForm")) {
    return;
  }

  const wrapper = document.createElement("div");
  wrapper.innerHTML = `
    <div class="crypto-checkout-box" style="margin-top:1rem;">
      <label for="cryptoAssetSelect">Choose coin</label>
      <select id="cryptoAssetSelect" name="cryptoAssetSelect">
        ${supportedAssets.map((asset) => `<option value="${escapeHtml(asset)}">${escapeHtml(asset)}</option>`).join("")}
      </select>
      <p id="cryptoQuoteStatus" class="status" aria-live="polite"></p>
      <div id="cryptoQuoteDetails" class="crypto-quote-details"></div>
    </div>
    <form id="cryptoTxForm" class="tip-form" style="margin-top:1rem;">
      <label for="cryptoTxHash">Transaction hash</label>
      <input id="cryptoTxHash" name="cryptoTxHash" type="text" placeholder="0x... or txid" value="${escapeHtml(txHash)}" required />
      <button type="submit">Submit Hash</button>
      <p id="cryptoTxStatus" class="status" aria-live="polite"></p>
    </form>
  `;
  host.appendChild(wrapper);

  const form = document.getElementById("cryptoTxForm");
  const txStatus = document.getElementById("cryptoTxStatus");
  const txInput = document.getElementById("cryptoTxHash");
  const assetSelect = document.getElementById("cryptoAssetSelect");
  const quoteStatus = document.getElementById("cryptoQuoteStatus");
  const quoteDetails = document.getElementById("cryptoQuoteDetails");

  const renderQuote = async (asset) => {
    quoteStatus.className = "status";
    quoteStatus.textContent = `Loading ${asset} quote...`;

    try {
      const quote = await loadCryptoQuote(sessionId, asset);
      quoteStatus.className = "status success";
      quoteStatus.textContent = `Send ${quote.cryptoAmount} ${quote.asset} to the address below.`;
      quoteDetails.innerHTML = `
        <div style="display:grid;gap:0.45rem;margin-top:0.35rem;">
          <div><strong>Quote:</strong> ${escapeHtml(quote.cryptoAmount)} ${escapeHtml(quote.asset)} for ${escapeHtml(formatMoney(data.amountCents, data.currency))}</div>
          <div><strong>Address:</strong> <code>${escapeHtml(quote.address)}</code></div>
          <div><strong>Payment URI:</strong> <code>${escapeHtml(quote.paymentUri)}</code></div>
          <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
            <button type="button" class="btn" id="copyAddressBtn">Copy Address</button>
            <button type="button" class="btn" id="copyUriBtn">Copy URI</button>
          </div>
          <div>
            <img src="${escapeHtml(quote.qrUrl)}" alt="Crypto payment QR code" style="width:180px;height:180px;border-radius:10px;border:1px solid rgba(0,229,255,0.28);" />
          </div>
        </div>
      `;

      const copyAddressBtn = document.getElementById("copyAddressBtn");
      const copyUriBtn = document.getElementById("copyUriBtn");

      copyAddressBtn?.addEventListener("click", async () => {
        try {
          await navigator.clipboard.writeText(quote.address);
          quoteStatus.className = "status success";
          quoteStatus.textContent = "Address copied.";
        } catch {
          quoteStatus.className = "status error";
          quoteStatus.textContent = "Clipboard unavailable. Copy the address manually.";
        }
      });

      copyUriBtn?.addEventListener("click", async () => {
        try {
          await navigator.clipboard.writeText(quote.paymentUri);
          quoteStatus.className = "status success";
          quoteStatus.textContent = "Payment URI copied.";
        } catch {
          quoteStatus.className = "status error";
          quoteStatus.textContent = "Clipboard unavailable. Copy the URI manually.";
        }
      });
    } catch (error) {
      quoteStatus.className = "status error";
      quoteStatus.textContent = error.message;
      quoteDetails.innerHTML = "";
    }
  };

  assetSelect.value = defaultAsset;
  renderQuote(defaultAsset);
  assetSelect.addEventListener("change", () => {
    renderQuote(String(assetSelect.value || defaultAsset));
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const submittedTxHash = String(txInput.value || "").trim();
    if (!submittedTxHash) {
      return;
    }

    txStatus.className = "status";
    txStatus.textContent = "Submitting hash...";

    try {
      const response = await fetch("/api/submit-crypto-payment.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          session_id: sessionId,
          txHash: submittedTxHash,
          asset: String(assetSelect?.value || defaultAsset)
        })
      });

      const payload = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(payload.error || "Unable to submit transaction hash");
      }

      txStatus.className = "status success";
      txStatus.textContent = "Hash submitted. Admin confirmation is pending.";
    } catch (error) {
      txStatus.className = "status error";
      txStatus.textContent = error.message;
    }
  });
}

async function loadTipStatus() {
  const params = new URLSearchParams(window.location.search);
  const sessionId = params.get("session_id");

  if (!sessionId) {
    successMessage.textContent = "Checkout completed. Payment confirmation can take a few seconds.";
    return;
  }

  try {
    const response = await fetch(`/api/tip-session.php?session_id=${encodeURIComponent(sessionId)}`);

    if (!response.ok) {
      throw new Error("Tip session is still being processed.");
    }

    const data = await response.json();
    const amount = ((data.amountCents || 0) / 100).toFixed(2);
    const tier = data.tierName ? ` (${data.tierName})` : "";

    if (data.processor === "coinbase") {
      setCryptoActions(sessionId, data);
      return;
    }

    successMessage.textContent = `Thanks ${data.username}. Your $${amount}${tier} tip status is: ${data.status}.`;
  } catch {
    successMessage.textContent = "Payment is processing. Refresh in a moment to see confirmed status.";
  }
}

loadTipStatus();
