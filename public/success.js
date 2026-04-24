const successMessage = document.getElementById("successMessage");

function escapeHtml(value) {
  return String(value || "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function setCryptoActions(sessionId, data) {
  const amount = ((data.amountCents || 0) / 100).toFixed(2);
  const asset = data.cryptoAsset || "USDC";
  const address = data.receiveAddress || "(not configured)";
  const txHash = data.txHash || "";

  successMessage.innerHTML =
    `Send <strong>${escapeHtml(amount)} ${escapeHtml(asset)}</strong> to:<br><code>${escapeHtml(address)}</code><br>` +
    `Then submit your transaction hash below for admin confirmation.`;

  const host = document.querySelector(".success-card");
  if (!host || document.getElementById("cryptoTxForm")) {
    return;
  }

  const wrapper = document.createElement("div");
  wrapper.innerHTML = `
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
          txHash: submittedTxHash
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
