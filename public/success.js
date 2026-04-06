const successMessage = document.getElementById("successMessage");

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

    successMessage.textContent = `Thanks ${data.username}. Your $${amount}${tier} tip status is: ${data.status}.`;
  } catch {
    successMessage.textContent = "Payment is processing. Refresh in a moment to see confirmed status.";
  }
}

loadTipStatus();
