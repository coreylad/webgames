<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/leaderboard-advanced.php';

// Advanced leaderboard endpoint with seasonal support, daily/weekly splits, and anti-cheat

$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? 'current');
$game = (string)($_GET['game'] ?? '');
$username = (string)($_GET['username'] ?? '');

if (!is_valid_game_slug($game)) {
    json_response(['error' => 'Invalid game'], 400);
}

if ($method === 'GET') {
    switch ($action) {
        case 'current':
            // Get current (all-time) leaderboard
            $lb = read_leaderboard_store();
            $entries = [];
            foreach ($lb['games'] as $gameData) {
                if ($gameData['game'] === $game) {
                    $entries = $gameData['entries'] ?? [];
                    break;
                }
            }
            
            usort($entries, fn($a, $b) => $b['score'] <=> $a['score']);
            
            json_response([
                'status' => 'ok',
                'game' => $game,
                'leaderboard' => [
                    'type' => 'all-time',
                    'entries' => array_slice($entries, 0, 100)
                ]
            ]);
            break;
        
        case 'daily':
            // Get daily leaderboard
            $dayOffset = (int)($_GET['dayOffset'] ?? 0);
            if (abs($dayOffset) > 30) {
                json_response(['error' => 'Day offset out of range'], 400);
            }
            
            $daily = get_daily_leaderboard($game, $dayOffset);
            json_response([
                'status' => 'ok',
                'game' => $game,
                'leaderboard' => $daily
            ]);
            break;
        
        case 'weekly':
            // Get weekly leaderboard
            $weekOffset = (int)($_GET['weekOffset'] ?? 0);
            if (abs($weekOffset) > 12) {
                json_response(['error' => 'Week offset out of range'], 400);
            }
            
            $weekly = get_weekly_leaderboard($game, $weekOffset);
            json_response([
                'status' => 'ok',
                'game' => $game,
                'leaderboard' => $weekly
            ]);
            break;
        
        case 'seasonal':
            // Get seasonal leaderboard
            $season = get_current_season();
            
            $lb = read_leaderboard_store();
            $entries = [];
            foreach ($lb['games'] as $gameData) {
                if ($gameData['game'] === $game) {
                    $entries = $gameData['entries'] ?? [];
                    break;
                }
            }
            
            // Filter to current season (for now, all entries)
            usort($entries, fn($a, $b) => $b['score'] <=> $a['score']);
            
            json_response([
                'status' => 'ok',
                'game' => $game,
                'season' => $season,
                'leaderboard' => [
                    'type' => 'seasonal',
                    'seasonNumber' => $season['number'],
                    'entries' => array_slice($entries, 0, 100)
                ]
            ]);
            break;
        
        case 'player-ranking':
            // Get player's rank
            if (!is_valid_username($username)) {
                json_response(['error' => 'Invalid username'], 400);
            }
            
            $ranking = get_player_ranking($game, $username);
            json_response([
                'status' => 'ok',
                'ranking' => $ranking
            ]);
            break;
        
        case 'player-history':
            // Get submission history for player
            if (!is_valid_username($username)) {
                json_response(['error' => 'Invalid username'], 400);
            }
            
            $history = get_leaderboard_entry_history($game, $username, 20);
            json_response([
                'status' => 'ok',
                'game' => $game,
                'username' => $username,
                'history' => $history
            ]);
            break;
        
        default:
            json_response(['error' => 'Unknown action'], 404);
    }

} elseif ($method === 'POST') {
    // POST requests for leaderboard operations
    $data = read_json_input();
    
    switch ($action) {
        case 'check-anomaly':
            // Check if a score looks suspicious
            if (!is_valid_username($username)) {
                json_response(['error' => 'Invalid username'], 400);
            }
            
            $score = (int)($data['score'] ?? 0);
            if ($score < 0 || $score > 1000000000) {
                json_response(['error' => 'Invalid score'], 400);
            }
            
            $anomalyScore = get_score_anomaly_score($game, $score, $username);
            
            json_response([
                'status' => 'ok',
                'game' => $game,
                'username' => $username,
                'score' => $score,
                'anomalyScore' => $anomalyScore,
                'isSuspicious' => $anomalyScore > 0.5
            ]);
            break;
        
        case 'flag-suspicious':
            // Flag a score as suspicious (admin only)
            require_admin_token();
            
            $username = (string)($data['username'] ?? '');
            $score = (int)($data['score'] ?? 0);
            
            if (!is_valid_username($username)) {
                json_response(['error' => 'Invalid username'], 400);
            }
            
            $anomalyScore = (float)($data['anomalyScore'] ?? 0.5);
            $flag = flag_suspicious_score($game, $username, $score, $anomalyScore);
            
            json_response([
                'status' => 'ok',
                'flagId' => $flag['id'],
                'message' => 'Score flagged for review'
            ]);
            break;
        
        default:
            json_response(['error' => 'Unknown action'], 404);
    }

} else {
    json_response(['error' => 'Method not allowed'], 405);
}
