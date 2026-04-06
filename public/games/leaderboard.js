(() => {
  function validUsername(name) {
    return /^[a-zA-Z0-9_-]{3,24}$/.test(name || "");
  }

  function fmtScore(score) {
    return Number(score || 0).toLocaleString();
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, options);
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(payload.error || "Request failed");
    }
    return payload;
  }

  window.createLeaderboardController = function createLeaderboardController(config) {
    const game = config.game;
    const listEl = document.getElementById(config.listId);
    const statusEl = document.getElementById(config.statusId);
    let submittedInRound = false;

    if (!listEl || !statusEl || !game) {
      return {
        refresh: async () => {},
        submit: async () => {}
      };
    }

    async function refresh() {
      statusEl.className = "status";
      statusEl.textContent = "Loading leaderboard...";
      try {
        const data = await fetchJson(`/api/leaderboard.php?game=${encodeURIComponent(game)}&limit=10`);
        const entries = Array.isArray(data.entries) ? data.entries : [];

        if (!entries.length) {
          listEl.innerHTML = "<li>No scores yet. Be the first.</li>";
        } else {
          listEl.innerHTML = "";
          entries.forEach((entry, index) => {
            const item = document.createElement("li");
            item.textContent = `#${index + 1} ${entry.username} - ${fmtScore(entry.score)}`;
            listEl.appendChild(item);
          });
        }

        statusEl.className = "status success";
        statusEl.textContent = "Leaderboard live.";
      } catch (error) {
        statusEl.className = "status error";
        statusEl.textContent = error.message;
      }
    }

    function resetRound() {
      submittedInRound = false;
    }

    async function submit(scoreValue) {
      const score = Math.floor(Number(scoreValue));
      if (!Number.isFinite(score) || score < 0 || submittedInRound) {
        return;
      }

      let username = localStorage.getItem("webgames.username") || "";
      if (!validUsername(username)) {
        const entered = prompt("Enter your leaderboard username (3-24 chars, letters/numbers/_/-)", "arcade_hero") || "";
        username = entered.trim();
      }

      if (!validUsername(username)) {
        statusEl.className = "status error";
        statusEl.textContent = "Leaderboard submit canceled: invalid username.";
        return;
      }

      localStorage.setItem("webgames.username", username);

      statusEl.className = "status";
      statusEl.textContent = "Submitting score...";

      try {
        await fetchJson("/api/leaderboard.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ game, username, score })
        });
        submittedInRound = true;
        statusEl.className = "status success";
        statusEl.textContent = `Score submitted: ${fmtScore(score)}`;
        await refresh();
      } catch (error) {
        statusEl.className = "status error";
        statusEl.textContent = error.message;
      }
    }

    return { refresh, submit, resetRound };
  };
})();
