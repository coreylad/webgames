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

function renderBtcpayStatus(sessionId, data) {
  const amountLabel = formatMoney(data.amountCents, data.currency);
  const status = String(data.status || "processing").toLowerCase();
  const checkoutUrl = String(data.btcpayCheckoutUrl || "");

  if (status === "paid") {
    successMessage.textContent = `Thanks ${data.username}. Your ${amountLabel} tip is confirmed.`;
    return true;
  }

  if (status === "checkout_failed") {
    successMessage.innerHTML =
      `BTCPay checkout failed for <strong>${escapeHtml(amountLabel)}</strong>. Start a new tip and try again.`;
    return true;
  }

  if (checkoutUrl !== "") {
    successMessage.innerHTML =
      `BTCPay invoice is still processing for <strong>${escapeHtml(amountLabel)}</strong>. ` +
      `<a href="${escapeHtml(checkoutUrl)}" target="_blank" rel="noopener">Re-open invoice</a> and complete payment.`;
  } else {
    successMessage.textContent =
      `BTCPay invoice is still processing for ${amountLabel}. This page will refresh status automatically.`;
  }

  setTimeout(() => {
    loadTipStatus(sessionId);
  }, 5000);
  return false;
}

async function loadTipStatus(providedSessionId) {
  const params = new URLSearchParams(window.location.search);
  const sessionId = providedSessionId || params.get("session_id");

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

    if (data.processor === "btcpay" || data.processor === "coinbase") {
      renderBtcpayStatus(sessionId, data);
      return;
    }

    successMessage.textContent = `Thanks ${data.username}. Your $${amount}${tier} tip status is: ${data.status}.`;
  } catch {
    successMessage.textContent = "Payment is processing. Refresh in a moment to see confirmed status.";
  }
}

loadTipStatus();
