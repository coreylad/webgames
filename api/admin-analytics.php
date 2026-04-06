<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/leaderboard-advanced.php';
require_once __DIR__ . '/achievements.php';

// Admin API for analytics, moderation, and platform insights

require_admin_token();

$action = (string)($_GET['action'] ?? '');
$output = [];

switch ($action) {
    case 'dashboard':
        // Main dashboard with key metrics
        require_once __DIR__ . '/webhook-advanced.php';
        
        $revenue = get_revenue_analytics('month');
        $webhook = get_webhook_health();
        
        $lb = read_leaderboard_store();
        $totalGames = count($lb['games'] ?? []);
        $totalLeaderboardEntries = 0;
        foreach ($lb['games'] as $game) {
            $totalLeaderboardEntries += count($game['entries'] ?? []);
        }
        
        $tips = read_tip_store();
        $paidTips = array_filter($tips['tips'], fn($t) => ($t['status'] ?? '') === 'paid');
        
        $analytics = read_analytics_store();
        $uniquePlayers = count(array_unique(array_map(fn($s) => $s['username'], $analytics['sessions'] ?? [])));
        
        $output = [
            'status' => 'ok',
            'metrics' => [
                'revenue' => $revenue,
                'webhookHealth' => $webhook,
                'leaderboards' => [
                    'gameCount' => $totalGames,
                    'totalEntries' => $totalLeaderboardEntries
                ],
                'monetization' => [
                    'totalTips' => count($paidTips),
                    'totalRevenueCents' => array_sum(array_map(fn($t) => $t['amountCents'] ?? 0, $paidTips))
                ],
                'players' => [
                    'uniquePlayers' => $uniquePlayers,
                    'totalSessions' => count($analytics['sessions'] ?? [])
                ]
            ]
        ];
        break;
    
    case 'game-analytics':
        // Analytics for a specific game
        $game = (string)($_GET['game'] ?? '');
        if (!is_valid_game_slug($game)) {
            json_response(['error' => 'Invalid game'], 400);
        }
        
        $output = [
            'status' => 'ok',
            'gameAnalytics' => get_game_analytics($game)
        ];
        break;
    
    case 'player-stats':
        // Statistics for a specific player
        $username = (string)($_GET['username'] ?? '');
        if (!is_valid_username($username)) {
            json_response(['error' => 'Invalid username'], 400);
        }
        
        $stats = get_player_stats($username);
        $achievements = get_player_achievements($username);
        
        $output = [
            'status' => 'ok',
            'playerStats' => $stats,
            'achievements' => $achievements
        ];
        break;
    
    case 'suspicious-scores':
        // Get flagged suspicious scores
        $dir = dirname(__DIR__) . '/data';
        $file = $dir . '/suspicious-scores.json';
        
        $suspicious = [];
        if (is_file($file)) {
            $store = json_decode(file_get_contents($file), true) ?? ['flags' => []];
            $suspicious = array_filter($store['flags'], fn($f) => !($f['reviewed'] ?? false));
        }
        
        usort($suspicious, fn($a, $b) => $b['anomalyScore'] <=> $a['anomalyScore']);
        
        $output = [
            'status' => 'ok',
            'suspiciousScores' => array_slice($suspicious, 0, 50)
        ];
        break;
    
    case 'moderate-score':
        // Moderate a suspicious score
        $scoreId = (string)($_POST['scoreId'] ?? '');
        $action_type = (string)($_POST['action'] ?? ''); // approve, reject
        
        if (!in_array($action_type, ['approve', 'reject'])) {
            json_response(['error' => 'Invalid action'], 400);
        }
        
        $dir = dirname(__DIR__) . '/data';
        $file = $dir . '/suspicious-scores.json';
        
        if (!is_file($file)) {
            json_response(['error' => 'No suspicious scores found'], 404);
        }
        
        $store = json_decode(file_get_contents($file), true) ?? ['flags' => []];
        
        $found = false;
        foreach ($store['flags'] as &$flag) {
            if ($flag['id'] === $scoreId) {
                $flag['reviewed'] = true;
                $flag['action'] = $action_type;
                $flag['reviewedAt'] = now_iso();
                $flag['reviewedBy'] = get_header_value('x-admin-username');
                
                if ($action_type === 'reject') {
                    // Remove from leaderboard
                    $lb = read_leaderboard_store();
                    foreach ($lb['games'] as &$gameData) {
                        $gameData['entries'] = array_filter(
                            $gameData['entries'],
                            fn($e) => !($e['username'] === $flag['username'] && $e['score'] === $flag['score'])
                        );
                    }
                    write_leaderboard_store($lb);
                }
                
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            json_response(['error' => 'Score not found'], 404);
        }
        
        file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        $output = [
            'status' => 'ok',
            'message' => 'Score moderated',
            'action' => $action_type
        ];
        break;
    
    case 'webhook-health':
        // Get webhook processing health
        require_once __DIR__ . '/webhook-advanced.php';
        
        $events = get_webhook_events('', 100);
        $health = get_webhook_health();
        
        $output = [
            'status' => 'ok',
            'health' => $health,
            'recentEvents' => array_slice($events, 0, 20)
        ];
        break;
    
    case 'achievement-leaderboard':
        // Get top achievement earners
        $output = [
            'status' => 'ok',
            'leaderboard' => get_achievement_leaderboard(100)
        ];
        break;
    
    case 'revenue-detailed':
        // Get detailed revenue breakdown
        $period = (string)($_GET['period'] ?? 'month');
        
        $revenue = get_revenue_analytics($period);
        
        $output = [
            'status' => 'ok',
            'revenue' => $revenue
        ];
        break;
    
    case 'player-ranking':
        // Get player's rank for a game
        $game = (string)($_GET['game'] ?? '');
        $username = (string)($_GET['username'] ?? '');
        
        if (!is_valid_game_slug($game) || !is_valid_username($username)) {
            json_response(['error' => 'Invalid parameters'], 400);
        }
        
        $output = [
            'status' => 'ok',
            'ranking' => get_player_ranking($game, $username)
        ];
        break;
    
    default:
        json_response(['error' => 'Unknown action'], 404);
}

json_response($output);
