<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/achievements.php';
require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/leaderboard-advanced.php';

// Achievements API - allows games and clients to interact with achievement system

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = (string)($_GET['action'] ?? '');
    
    switch ($action) {
        case 'suggest':
            // Get suggested achievements for player
            $username = (string)($_GET['username'] ?? '');
            $game = (string)($_GET['game'] ?? '');
            
            if (!is_valid_username($username) || !is_valid_game_slug($game)) {
                json_response(['error' => 'Invalid parameters'], 400);
            }
            
            $suggestions = suggest_next_achievements($username, $game);
            json_response([
                'status' => 'ok',
                'username' => $username,
                'game' => $game,
                'suggestions' => $suggestions
            ]);
            break;
        
        case 'player':
            // Get player's achievements
            $username = (string)($_GET['username'] ?? '');
            
            if (!is_valid_username($username)) {
                json_response(['error' => 'Invalid username'], 400);
            }
            
            $achievements = get_player_achievements($username);
            json_response([
                'status' => 'ok',
                'achievements' => $achievements
            ]);
            break;
        
        case 'leaderboard':
            // Get achievement leaderboard
            $limit = min((int)($_GET['limit'] ?? 50), 100);
            
            $lb = get_achievement_leaderboard($limit);
            json_response([
                'status' => 'ok',
                'leaderboard' => $lb
            ]);
            break;
        
        case 'all':
            // Get all available achievements
            json_response([
                'status' => 'ok',
                'achievements' => ACHIEVEMENTS
            ]);
            break;
        
        default:
            json_response(['error' => 'Unknown action'], 404);
    }
} elseif ($method === 'POST') {
    $data = read_json_input();
    $action = (string)($_GET['action'] ?? '');
    
    switch ($action) {
        case 'earn':
            // Award an achievement to a player
            $username = (string)($data['username'] ?? '');
            $achievementId = (string)($data['achievementId'] ?? '');
            $game = (string)($data['game'] ?? '');
            
            if (!is_valid_username($username) || !is_valid_game_slug($game)) {
                json_response(['error' => 'Invalid parameters'], 400);
            }
            
            if (!isset(ACHIEVEMENTS[$achievementId])) {
                json_response(['error' => 'Invalid achievement'], 400);
            }
            
            $earned = earn_achievement($username, $achievementId);
            
            if ($earned) {
                // Track analytics
                track_event('achievement', $username, $game, ['achievementId' => $achievementId]);
                
                json_response([
                    'status' => 'ok',
                    'message' => 'Achievement earned!',
                    'achievementId' => $achievementId,
                    'points' => ACHIEVEMENTS[$achievementId]['points'] ?? 0
                ]);
            } else {
                json_response([
                    'status' => 'already_earned',
                    'message' => 'Achievement already earned'
                ], 409);
            }
            break;
        
        default:
            json_response(['error' => 'Unknown action'], 404);
    }
} else {
    json_response(['error' => 'Method not allowed'], 405);
}
