<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

// Advanced leaderboard system with seasonal support and anti-cheat detection

function ensure_seasonal_store(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'seasons.json';
    if (!is_file($file)) {
        file_put_contents($file, json_encode([
            'seasons' => [],
            'current' => null
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $file;
}

function read_seasonal_store(): array
{
    $file = ensure_seasonal_store();
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return ['seasons' => [], 'current' => null];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['seasons' => [], 'current' => null];
}

function write_seasonal_store(array $store): void
{
    $file = ensure_seasonal_store();
    file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function get_current_season(): array
{
    $store = read_seasonal_store();
    $now = time();
    
    foreach ($store['seasons'] as $season) {
        $startTime = strtotime($season['startedAt']);
        $endTime = strtotime($season['endsAt']);
        
        if ($startTime <= $now && $now < $endTime) {
            return $season;
        }
    }
    
    // Create new season if none active
    $currentSeason = $store['seasons'][count($store['seasons']) - 1] ?? null;
    $nextNumber = ($currentSeason['number'] ?? 0) + 1;
    
    $newSeason = [
        'id' => generate_id(),
        'number' => $nextNumber,
        'name' => 'Season ' . $nextNumber,
        'startedAt' => now_iso(),
        'endsAt' => gmdate('c', time() + (2592000)), // 30 days
        'leaderboards' => []
    ];
    
    $store['seasons'][] = $newSeason;
    $store['current'] = $newSeason['id'];
    write_seasonal_store($store);
    
    return $newSeason;
}

function get_score_anomaly_score(string $game, int $score, string $username): float
{
    $lb = read_leaderboard_store();
    
    $gameData = null;
    foreach ($lb['games'] ?? [] as $g) {
        if ($g['game'] === $game) {
            $gameData = $g;
            break;
        }
    }
    
    if ($gameData === null) {
        return 0.0;
    }
    
    // Get user's previous scores
    $previousScores = [];
    foreach ($gameData['entries'] ?? [] as $entry) {
        if ($entry['username'] === $username) {
            $previousScores[] = $entry['score'];
        }
    }
    
    if (empty($previousScores)) {
        return 0.0;
    }
    
    // Check for extreme jumps in score
    $avgPreviousScore = array_sum($previousScores) / count($previousScores);
    $maxPreviousScore = max($previousScores);
    
    // Flag if score increases by more than 10x or is impossibly high
    if ($score > ($maxPreviousScore * 10)) {
        return 0.85;
    }
    
    // Flag if submitted too quickly (time-based analysis)
    $lastSubmission = strtotime($gameData['entries'][0]['updatedAt'] ?? now_iso());
    $timeSinceLastSubmission = time() - $lastSubmission;
    
    if ($timeSinceLastSubmission < 5 && $score > $avgPreviousScore * 2) {
        return 0.75;
    }
    
    // Check score pattern consistency
    if (count($previousScores) >= 5) {
        $diffs = [];
        for ($i = 1; $i < count($previousScores); $i++) {
            $diffs[] = $previousScores[$i] - $previousScores[$i - 1];
        }
        
        $avgDiff = array_sum($diffs) / count($diffs);
        $deviation = $score - $maxPreviousScore - $avgDiff;
        
        if (abs($deviation) > $avgDiff * 5) {
            return 0.6;
        }
    }
    
    return 0.0;
}

function flag_suspicious_score(string $game, string $username, int $score, float $anomalyScore): array
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    $file = $dir . DIRECTORY_SEPARATOR . 'suspicious-scores.json';
    
    if (!is_file($file)) {
        file_put_contents($file, json_encode(['flags' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    $store = json_decode(file_get_contents($file), true) ?? ['flags' => []];
    
    $flag = [
        'id' => generate_id(),
        'game' => $game,
        'username' => $username,
        'score' => $score,
        'anomalyScore' => $anomalyScore,
        'ip' => client_ip_address(),
        'flaggedAt' => now_iso(),
        'reviewed' => false,
        'action' => null
    ];
    
    $store['flags'][] = $flag;
    file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    
    return $flag;
}

function get_leaderboard_entry_history(string $game, string $username, int $limit = 20): array
{
    $lb = read_leaderboard_store();
    
    $history = [];
    foreach ($lb['games'] ?? [] as $gameData) {
        if ($gameData['game'] !== $game) {
            continue;
        }
        
        foreach ($gameData['entries'] ?? [] as $entry) {
            if ($entry['username'] === $username) {
                $history[] = $entry;
            }
        }
    }
    
    usort($history, fn($a, $b) => strcmp($b['updatedAt'], $a['updatedAt']));
    
    return array_slice($history, 0, $limit);
}

function get_daily_leaderboard(string $game, int $dayOffset = 0): array
{
    $lb = read_leaderboard_store();
    $targetDate = gmdate('Y-m-d', time() + ($dayOffset * 86400));
    
    $entries = [];
    foreach ($lb['games'] ?? [] as $gameData) {
        if ($gameData['game'] !== $game) {
            continue;
        }
        
        foreach ($gameData['entries'] ?? [] as $entry) {
            $entryDate = substr($entry['updatedAt'] ?? '0000-00-00', 0, 10);
            if ($entryDate === $targetDate) {
                $entries[] = $entry;
            }
        }
    }
    
    usort($entries, fn($a, $b) => $b['score'] <=> $a['score']);
    
    return [
        'game' => $game,
        'period' => 'daily',
        'date' => $targetDate,
        'entries' => array_slice($entries, 0, 100)
    ];
}

function get_weekly_leaderboard(string $game, int $weekOffset = 0): array
{
    $lb = read_leaderboard_store();
    $now = time();
    $weekStart = $now + ($weekOffset * 604800);
    $weekEnd = $weekStart + 604800;
    
    $entries = [];
    foreach ($lb['games'] ?? [] as $gameData) {
        if ($gameData['game'] !== $game) {
            continue;
        }
        
        foreach ($gameData['entries'] ?? [] as $entry) {
            $entryTime = strtotime($entry['updatedAt'] ?? now_iso());
            if ($entryTime >= $weekStart && $entryTime < $weekEnd) {
                $entries[] = $entry;
            }
        }
    }
    
    usort($entries, fn($a, $b) => $b['score'] <=> $a['score']);
    
    return [
        'game' => $game,
        'period' => 'weekly',
        'startDate' => gmdate('Y-m-d', $weekStart),
        'endDate' => gmdate('Y-m-d', $weekEnd),
        'entries' => array_slice($entries, 0, 100)
    ];
}

function reset_seasonal_leaderboard(string $seasonId): array
{
    $store = read_seasonal_store();
    
    foreach ($store['seasons'] as &$season) {
        if ($season['id'] === $seasonId) {
            $season['leaderboards'] = [];
            break;
        }
    }
    
    write_seasonal_store($store);
    
    return $store;
}

function get_player_ranking(string $game, string $username): array
{
    $lb = read_leaderboard_store();
    
    $entries = [];
    foreach ($lb['games'] ?? [] as $gameData) {
        if ($gameData['game'] === $game) {
            $entries = $gameData['entries'] ?? [];
            break;
        }
    }
    
    usort($entries, fn($a, $b) => $b['score'] <=> $a['score']);
    
    $rank = 0;
    $userScore = null;
    foreach ($entries as $idx => $entry) {
        if ($entry['username'] === $username) {
            $rank = $idx + 1;
            $userScore = $entry['score'];
            break;
        }
    }
    
    $percentile = 0;
    if ($rank > 0 && count($entries) > 0) {
        $percentile = round(((count($entries) - $rank) / count($entries)) * 100, 2);
    }
    
    return [
        'game' => $game,
        'username' => $username,
        'rank' => $rank,
        'score' => $userScore,
        'totalPlayers' => count($entries),
        'percentile' => $percentile
    ];
}
