<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

// Analytics tracking system

function ensure_analytics_store(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'analytics.json';
    if (!is_file($file)) {
        file_put_contents($file, json_encode([
            'sessions' => [],
            'events' => [],
            'revenue' => [],
            'playerStats' => []
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $file;
}

function read_analytics_store(): array
{
    $file = ensure_analytics_store();
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return ['sessions' => [], 'events' => [], 'revenue' => [], 'playerStats' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['sessions' => [], 'events' => [], 'revenue' => [], 'playerStats' => []];
    }

    return $decoded;
}

function write_analytics_store(array $store): void
{
    $file = ensure_analytics_store();
    file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function track_player_session(string $username, string $game): array
{
    $store = read_analytics_store();
    
    $session = [
        'id' => generate_id(),
        'username' => $username,
        'game' => $game,
        'ip' => client_ip_address(),
        'startedAt' => now_iso(),
        'endedAt' => null,
        'duration' => 0,
        'gameCount' => 1
    ];

    $store['sessions'][] = $session;
    write_analytics_store($store);
    
    return $session;
}

function track_event(string $type, string $username, string $game, array $metadata = []): void
{
    $store = read_analytics_store();
    
    $event = [
        'id' => generate_id(),
        'type' => $type,
        'username' => $username,
        'game' => $game,
        'ip' => client_ip_address(),
        'metadata' => $metadata,
        'timestamp' => now_iso()
    ];

    $store['events'][] = $event;
    if (count($store['events']) > 10000) {
        $store['events'] = array_slice($store['events'], -5000);
    }
    
    write_analytics_store($store);
}

function track_revenue_event(string $username, string $type, int $amountCents, string $currency = 'usd', array $metadata = []): void
{
    $store = read_analytics_store();
    
    $revenue = [
        'id' => generate_id(),
        'username' => $username,
        'type' => $type,
        'amountCents' => $amountCents,
        'currency' => strtolower($currency),
        'metadata' => $metadata,
        'timestamp' => now_iso()
    ];

    $store['revenue'][] = $revenue;
    write_analytics_store($store);
}

function get_player_stats(string $username): array
{
    $store = read_analytics_store();
    $lb = read_leaderboard_store();
    
    $sessions = 0;
    $gamesPlayed = [];
    $firstSeenAt = null;
    $lastSeenAt = null;
    
    foreach ($store['sessions'] as $session) {
        if ($session['username'] !== $username) {
            continue;
        }
        $sessions++;
        $gamesPlayed[$session['game']] = ($gamesPlayed[$session['game']] ?? 0) + 1;
        
        if ($firstSeenAt === null || $session['startedAt'] < $firstSeenAt) {
            $firstSeenAt = $session['startedAt'];
        }
        $lastSeenAt = $session['startedAt'];
    }
    
    $totalTipped = 0;
    foreach ($store['revenue'] as $rev) {
        if ($rev['username'] === $username && $rev['type'] === 'tip') {
            $totalTipped += $rev['amountCents'];
        }
    }
    
    $highScores = [];
    foreach ($lb['games'] ?? [] as $gameData) {
        foreach ($gameData['entries'] ?? [] as $entry) {
            if ($entry['username'] === $username) {
                $highScores[$gameData['game']] = $entry['score'];
            }
        }
    }
    
    return [
        'username' => $username,
        'sessionsCount' => $sessions,
        'gamesPlayed' => $gamesPlayed,
        'highScores' => $highScores,
        'totalTipped' => $totalTipped,
        'firstSeenAt' => $firstSeenAt,
        'lastSeenAt' => $lastSeenAt
    ];
}

function get_revenue_analytics(string $period = 'all'): array
{
    $store = read_analytics_store();
    $now = time();
    $cutoff = $now;
    
    switch ($period) {
        case 'day':
            $cutoff = $now - (86400);
            break;
        case 'week':
            $cutoff = $now - (604800);
            break;
        case 'month':
            $cutoff = $now - (2592000);
            break;
    }
    
    $total = 0;
    $byType = [];
    $byCurrency = [];
    $transactions = [];
    
    foreach ($store['revenue'] as $rev) {
        $revTime = strtotime($rev['timestamp'] ?? now_iso());
        if ($revTime < $cutoff) {
            continue;
        }
        
        $total += $rev['amountCents'];
        $type = $rev['type'] ?? 'unknown';
        $byType[$type] = ($byType[$type] ?? 0) + $rev['amountCents'];
        
        $currency = $rev['currency'] ?? 'usd';
        $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $rev['amountCents'];
        
        $transactions[] = $rev;
    }
    
    usort($transactions, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));
    
    return [
        'period' => $period,
        'totalCents' => $total,
        'totalFormatted' => format_money($total, 'usd'),
        'byType' => $byType,
        'byCurrency' => $byCurrency,
        'transactionCount' => count($transactions),
        'recentTransactions' => array_slice($transactions, 0, 50)
    ];
}

function get_game_analytics(string $game): array
{
    $store = read_analytics_store();
    $lb = read_leaderboard_store();
    
    $plays = 0;
    $uniquePlayers = [];
    $avgSessionDuration = 0;
    $totalDuration = 0;
    
    foreach ($store['sessions'] as $session) {
        if ($session['game'] !== $game) {
            continue;
        }
        $plays++;
        $uniquePlayers[$session['username']] = true;
        $totalDuration += ($session['duration'] ?? 0);
    }
    
    if ($plays > 0) {
        $avgSessionDuration = $totalDuration / $plays;
    }
    
    $topScores = [];
    foreach ($lb['games'] ?? [] as $gameData) {
        if ($gameData['game'] === $game) {
            $topScores = array_slice($gameData['entries'] ?? [], 0, 10);
            break;
        }
    }
    
    return [
        'game' => $game,
        'totalPlays' => $plays,
        'uniquePlayers' => count($uniquePlayers),
        'avgSessionDuration' => round($avgSessionDuration, 2),
        'topScores' => $topScores
    ];
}
