<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

// Achievement & Badge system

const ACHIEVEMENTS = [
    // Snake achievements
    'snake_first_blood' => [
        'game' => 'snake',
        'name' => 'First Bite',
        'description' => 'Eat your first fruit in Snake',
        'points' => 10,
        'rarity' => 'common'
    ],
    'snake_combo_master' => [
        'game' => 'snake',
        'name' => 'Combo Master',
        'description' => 'Get a 5+ combo chain in Snake',
        'points' => 50,
        'rarity' => 'rare'
    ],
    'snake_speed_demon' => [
        'game' => 'snake',
        'name' => 'Speed Demon',
        'description' => 'Reach speed tier 8+ in Snake',
        'points' => 75,
        'rarity' => 'rare'
    ],
    'snake_survival' => [
        'game' => 'snake',
        'name' => 'Survival Instinct',
        'description' => 'Score 500+ in Snake',
        'points' => 100,
        'rarity' => 'epic'
    ],
    
    // Pong achievements
    'pong_first_volley' => [
        'game' => 'pong',
        'name' => 'First Volley',
        'description' => 'Win your first Pong match',
        'points' => 10,
        'rarity' => 'common'
    ],
    'pong_shutout' => [
        'game' => 'pong',
        'name' => 'Perfect Defense',
        'description' => 'Win a Pong match without letting opponent score',
        'points' => 75,
        'rarity' => 'epic'
    ],
    
    // Racer (Turbo Lane) achievements
    'racer_first_run' => [
        'game' => 'racer',
        'name' => 'First Lap',
        'description' => 'Survive 30 seconds in Turbo Lane',
        'points' => 10,
        'rarity' => 'common'
    ],
    'racer_nitro_junkie' => [
        'game' => 'racer',
        'name' => 'Nitro Junkie',
        'description' => 'Use 10+ nitro boosts in a single run',
        'points' => 50,
        'rarity' => 'rare'
    ],
    'racer_near_miss' => [
        'game' => 'racer',
        'name' => 'Daredevil',
        'description' => 'Get 5+ near-miss dodges in one run',
        'points' => 60,
        'rarity' => 'rare'
    ],
    
    // Meteor achievements
    'meteor_first_shot' => [
        'game' => 'meteor',
        'name' => 'First Strike',
        'description' => 'Destroy your first asteroid in Meteor Drift',
        'points' => 10,
        'rarity' => 'common'
    ],
    'meteor_wave_master' => [
        'game' => 'meteor',
        'name' => 'Wave Master',
        'description' => 'Survive 5+ waves in Meteor Drift',
        'points' => 75,
        'rarity' => 'epic'
    ],
    'meteor_dash_expert' => [
        'game' => 'meteor',
        'name' => 'Dash Expert',
        'description' => 'Use dash 15+ times in a single match',
        'points' => 50,
        'rarity' => 'rare'
    ],
    
    // Cross-game achievements
    'leaderboard_debut' => [
        'game' => null,
        'name' => 'Leaderboard Debut',
        'description' => 'Appear on a game\'s leaderboard',
        'points' => 25,
        'rarity' => 'uncommon'
    ],
    'top_scorer' => [
        'game' => null,
        'name' => 'Top Scorer',
        'description' => 'Reach #1 on any game\'s leaderboard',
        'points' => 200,
        'rarity' => 'legendary'
    ],
    'multi_master' => [
        'game' => null,
        'name' => 'Multi-Master',
        'description' => 'Appear on leaderboards for 5+ different games',
        'points' => 150,
        'rarity' => 'epic'
    ],
    'supporter' => [
        'game' => null,
        'name' => 'Supporter',
        'description' => 'Send a tip to webgames.lol',
        'points' => 100,
        'rarity' => 'rare'
    ]
];

function ensure_achievements_store(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'achievements.json';
    if (!is_file($file)) {
        file_put_contents($file, json_encode([
            'earned' => [],
            'progress' => []
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $file;
}

function read_achievements_store(): array
{
    $file = ensure_achievements_store();
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return ['earned' => [], 'progress' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['earned' => [], 'progress' => []];
    }

    return $decoded;
}

function write_achievements_store(array $store): void
{
    $file = ensure_achievements_store();
    file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function earn_achievement(string $username, string $achievementId): bool
{
    if (!isset(ACHIEVEMENTS[$achievementId])) {
        return false;
    }

    $store = read_achievements_store();
    
    foreach ($store['earned'] as $earned) {
        if ($earned['username'] === $username && $earned['achievementId'] === $achievementId) {
            return false;
        }
    }

    $store['earned'][] = [
        'id' => generate_id(),
        'username' => $username,
        'achievementId' => $achievementId,
        'earnedAt' => now_iso(),
        'points' => ACHIEVEMENTS[$achievementId]['points'] ?? 0
    ];

    write_achievements_store($store);
    track_event('achievement_earned', $username, 'system', ['achievementId' => $achievementId]);
    
    return true;
}

function get_player_achievements(string $username): array
{
    $store = read_achievements_store();
    
    $achievements = [];
    $totalPoints = 0;
    
    foreach ($store['earned'] as $earned) {
        if ($earned['username'] !== $username) {
            continue;
        }
        
        $achievementId = $earned['achievementId'] ?? '';
        $baseData = ACHIEVEMENTS[$achievementId] ?? null;
        
        if ($baseData === null) {
            continue;
        }
        
        $achievements[] = array_merge($baseData, [
            'id' => $achievementId,
            'earnedAt' => $earned['earnedAt'],
            'points' => $earned['points'] ?? 0
        ]);
        
        $totalPoints += ($earned['points'] ?? 0);
    }
    
    usort($achievements, fn($a, $b) => strcmp($b['earnedAt'], $a['earnedAt']));
    
    return [
        'username' => $username,
        'totalAchievements' => count($achievements),
        'totalPoints' => $totalPoints,
        'achievements' => $achievements
    ];
}

function get_achievement_leaderboard(int $limit = 50): array
{
    $store = read_achievements_store();
    $byPlayer = [];
    
    foreach ($store['earned'] as $earned) {
        $username = $earned['username'] ?? 'unknown';
        if (!isset($byPlayer[$username])) {
            $byPlayer[$username] = ['count' => 0, 'points' => 0];
        }
        $byPlayer[$username]['count']++;
        $byPlayer[$username]['points'] += ($earned['points'] ?? 0);
    }
    
    $entries = [];
    foreach ($byPlayer as $username => $data) {
        $entries[] = [
            'username' => $username,
            'achievementCount' => $data['count'],
            'totalPoints' => $data['points']
        ];
    }
    
    usort($entries, fn($a, $b) => $b['totalPoints'] <=> $a['totalPoints']);
    
    return array_slice($entries, 0, $limit);
}

function suggest_next_achievements(string $username, string $game): array
{
    $earned = get_player_achievements($username);
    $earnedIds = array_map(fn($a) => $a['id'], $earned['achievements']);
    
    $available = [];
    foreach (ACHIEVEMENTS as $id => $achievement) {
        if (in_array($id, $earnedIds, true)) {
            continue;
        }
        
        if ($achievement['game'] === null || $achievement['game'] === $game) {
            $available[] = array_merge($achievement, ['id' => $id]);
        }
    }
    
    usort($available, fn($a, $b) => $a['points'] <=> $b['points']);
    
    return array_slice($available, 0, 5);
}
