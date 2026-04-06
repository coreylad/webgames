// Advanced leaderboard controller with achievements and anti-cheat

function createAdvancedLeaderboardController(config) {
    const {
        game,
        listId,
        statusId = null,
        achievementsId = null,
        rankingId = null
    } = config;

    let leaderboardData = [];
    let playerAchievements = [];
    let playerRanking = null;
    let username = localStorage.getItem(`lb_username_${game}`) || null;

    const updateStatus = (message, type = 'info') => {
        if (!statusId) return;
        const el = document.getElementById(statusId);
        if (el) {
            el.textContent = message;
            el.className = `leaderboard-status ${type}`;
        }
    };

    const refresh = async () => {
        try {
            updateStatus('Loading leaderboard...', 'loading');
            const response = await fetch(`/api/leaderboard-advanced-endpoint.php?action=current&game=${game}`);
            const data = await response.json();
            
            if (data.status === 'ok' && data.leaderboard) {
                leaderboardData = data.leaderboard.entries || [];
                renderLeaderboard();
                updateStatus('Leaderboard updated', 'success');
            } else {
                updateStatus('Failed to load leaderboard', 'error');
            }
        } catch (err) {
            updateStatus('Error loading leaderboard: ' + err.message, 'error');
        }
    };

    const submit = async (scoreValue) => {
        if (!username) {
            username = prompt('Enter your username (3-24 alphanumeric):');
            if (!username) return;
            
            if (!/^[a-zA-Z0-9_-]{3,24}$/.test(username)) {
                updateStatus('Invalid username format', 'error');
                username = null;
                return;
            }
            
            localStorage.setItem(`lb_username_${game}`, username);
        }

        try {
            updateStatus('Checking score...', 'loading');

            // Check for anomalies
            const anomalyResponse = await fetch(`/api/leaderboard-advanced-endpoint.php?action=check-anomaly&game=${game}&username=${username}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ score: scoreValue })
            });

            const anomalyData = await anomalyResponse.json();
            if (anomalyData.isSuspicious) {
                updateStatus('⚠️ Unusual score detected - submitted for review', 'warning');
            }

            // Submit score via regular leaderboard endpoint
            updateStatus('Submitting score...', 'loading');
            const submitResponse = await fetch('/api/leaderboard.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    game,
                    username,
                    score: scoreValue
                })
            });

            const submitData = await submitResponse.json();
            if (submitData.ok || submitData.status === 'ok') {
                updateStatus('Score submitted! 🎉', 'success');
                await refresh();
            } else {
                updateStatus(submitData.error || 'Failed to submit', 'error');
            }
        } catch (err) {
            updateStatus('Error: ' + err.message, 'error');
        }
    };

    const loadAchievements = async () => {
        if (!username || !achievementsId) return;

        try {
            const response = await fetch(`/api/achievements-endpoint.php?action=player&username=${username}`);
            const data = await response.json();
            
            if (data.status === 'ok') {
                playerAchievements = data.achievements.achievements || [];
                renderAchievements();
            }
        } catch (err) {
            console.error('Error loading achievements:', err);
        }
    };

    const loadPlayerRanking = async () => {
        if (!username || !rankingId) return;

        try {
            const response = await fetch(`/api/leaderboard-advanced-endpoint.php?action=player-ranking&game=${game}&username=${username}`);
            const data = await response.json();
            
            if (data.status === 'ok') {
                playerRanking = data.ranking;
                renderRanking();
            }
        } catch (err) {
            console.error('Error loading ranking:', err);
        }
    };

    const renderLeaderboard = () => {
        const container = document.getElementById(listId);
        if (!container) return;

        container.innerHTML = '';
        
        leaderboardData.forEach((entry, idx) => {
            const li = document.createElement('li');
            const isMine = entry.username === username ? ' class="mine"' : '';
            li.innerHTML = `<span>#${idx + 1}</span> <strong${isMine}>${entry.username}</strong> <span class="score">${entry.score.toLocaleString()}</span>`;
            container.appendChild(li);
        });
    };

    const renderAchievements = () => {
        const container = document.getElementById(achievementsId);
        if (!container) return;

        container.innerHTML = '';
        
        if (playerAchievements.length === 0) {
            container.innerHTML = '<p>No achievements yet. Keep playing!</p>';
            return;
        }

        playerAchievements.forEach(achievement => {
            const div = document.createElement('div');
            div.className = `achievement achievement-${achievement.rarity}`;
            div.innerHTML = `
                <strong>${achievement.name}</strong>
                <p>${achievement.description}</p>
                <span class="points">+${achievement.points}pts</span>
            `;
            container.appendChild(div);
        });
    };

    const renderRanking = () => {
        const container = document.getElementById(rankingId);
        if (!container || !playerRanking) return;

        container.innerHTML = `
            <div class="ranking">
                <div class="rank-item">
                    <span class="label">Rank</span>
                    <span class="value">#${playerRanking.rank} / ${playerRanking.totalPlayers}</span>
                </div>
                <div class="rank-item">
                    <span class="label">Score</span>
                    <span class="value">${playerRanking.score?.toLocaleString() || '—'}</span>
                </div>
                <div class="rank-item">
                    <span class="label">Percentile</span>
                    <span class="value">${playerRanking.percentile}%</span>
                </div>
            </div>
        `;
    };

    const loadDailyLeaderboard = async () => {
        try {
            const response = await fetch(`/api/leaderboard-advanced-endpoint.php?action=daily&game=${game}&dayOffset=0`);
            const data = await response.json();
            return data.leaderboard;
        } catch (err) {
            console.error('Error loading daily leaderboard:', err);
            return null;
        }
    };

    const loadWeeklyLeaderboard = async () => {
        try {
            const response = await fetch(`/api/leaderboard-advanced-endpoint.php?action=weekly&game=${game}&weekOffset=0`);
            const data = await response.json();
            return data.leaderboard;
        } catch (err) {
            console.error('Error loading weekly leaderboard:', err);
            return null;
        }
    };

    const getUsername = () => username;
    const setUsername = (newUsername) => { username = newUsername; };

    return {
        refresh,
        submit,
        loadAchievements,
        loadPlayerRanking,
        getUsername,
        setUsername,
        loadDailyLeaderboard,
        loadWeeklyLeaderboard,
        getRanking: () => playerRanking
    };
}
