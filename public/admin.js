const adminTokenInput = document.getElementById("adminToken");
const loadDashboardButton = document.getElementById("loadDashboard");
const adminStatus = document.getElementById("adminStatus");
const totalTipsEl = document.getElementById("totalTips");
const paidUsdEl = document.getElementById("paidUsd");
const uniqueUsersCountEl = document.getElementById("uniqueUsersCount");
const usernamesListEl = document.getElementById("usernamesList");
const tipsTableBody = document.getElementById("tipsTableBody");

function centsToGbp(amountCents) {
  return `£${(Number(amountCents || 0) / 100).toFixed(2)}`;
}

function formatDate(value) {
  if (!value) {
    return "-";
  }

  return new Date(value).toLocaleString();
}

async function fetchAdminJson(path, token) {
  const response = await fetch(path, {
    headers: {
      "x-admin-token": token
    }
  });

  if (!response.ok) {
    const payload = await response.json().catch(() => ({ error: "Request failed" }));
    throw new Error(payload.error || "Unauthorized or request failed");
  }

  return response.json();
}

function renderTips(rows) {
  tipsTableBody.innerHTML = "";

  if (!rows.length) {
    tipsTableBody.innerHTML =
      '<tr><td colspan="6">No tips yet. Once users complete Stripe checkout, records will appear.</td></tr>';
    return;
  }

  rows.forEach((tip) => {
    const tr = document.createElement("tr");

    const usernameTd = document.createElement("td");
    usernameTd.textContent = tip.username || "anonymous";

    const tierTd = document.createElement("td");
    tierTd.textContent = tip.tierName || "Tip Tier";

    const amountTd = document.createElement("td");
    amountTd.textContent = centsToGbp(tip.amountCents);

    const statusTd = document.createElement("td");
    statusTd.textContent = tip.status || "unknown";

    const createdTd = document.createElement("td");
    createdTd.textContent = formatDate(tip.createdAt);

    const sessionTd = document.createElement("td");
    sessionTd.textContent = tip.sessionId || "-";

    tr.append(usernameTd, tierTd, amountTd, statusTd, createdTd, sessionTd);
    tipsTableBody.appendChild(tr);
  });
}

async function loadDashboard() {
  const token = adminTokenInput.value.trim();

  if (!token) {
    adminStatus.textContent = "Enter admin token first.";
    adminStatus.className = "status error";
    return;
  }

  adminStatus.className = "status";
  adminStatus.textContent = "Loading dashboard...";
  loadDashboardButton.disabled = true;

  try {
    const [summaryData, tipsData] = await Promise.all([
      fetchAdminJson("/api/admin-summary.php", token),
      fetchAdminJson("/api/admin-tips.php", token)
    ]);

    totalTipsEl.textContent = String(summaryData.totalTips);
    paidUsdEl.textContent = centsToGbp(summaryData.totalPaidCents);
    uniqueUsersCountEl.textContent = String(summaryData.uniqueUsernames.length);
    usernamesListEl.textContent =
      summaryData.uniqueUsernames.length > 0
        ? `Usernames: ${summaryData.uniqueUsernames.join(", ")}`
        : "Usernames: none yet";

    renderTips(tipsData.tips || []);

    adminStatus.textContent = "Dashboard loaded.";
    adminStatus.className = "status success";
    sessionStorage.setItem("webgames.adminToken", token);
  } catch (error) {
    adminStatus.textContent = error.message;
    adminStatus.className = "status error";
  } finally {
    loadDashboardButton.disabled = false;
  }
}

const cachedToken = sessionStorage.getItem("webgames.adminToken");
if (cachedToken) {
  adminTokenInput.value = cachedToken;
}

loadDashboardButton.addEventListener("click", loadDashboard);
