<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

function normalize_entries(array $entries): array
{
    $normalized = [];

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $username = trim((string)($entry['username'] ?? ''));
        $score = (int)($entry['score'] ?? 0);

        if (!is_valid_username($username) || $score < 0) {
            continue;
        }

        $normalized[] = [
            'username' => $username,
            'score' => $score,
            'createdAt' => (string)($entry['createdAt'] ?? now_iso()),
            'updatedAt' => (string)($entry['updatedAt'] ?? now_iso())
        ];
    }

    usort($normalized, static function (array $a, array $b): int {
        if ($a['score'] === $b['score']) {
            return strcmp($a['updatedAt'], $b['updatedAt']);
        }
        return $b['score'] <=> $a['score'];
    });

    return array_slice($normalized, 0, 100);
}

function rate_limit_submit(string $game): array
{
    $ip = client_ip_address();
    $key = $ip . '|' . $game;
    $now = time();

    $store = read_leaderboard_rate_store();
    $attempts = $store['attempts'][$key] ?? [];
    if (!is_array($attempts)) {
        $attempts = [];
    }

    $attempts = array_values(array_filter($attempts, static fn($ts): bool => is_int($ts) && $ts >= $now - 3600));

    if (!empty($attempts) && ($now - (int)end($attempts)) < 4) {
        return ['ok' => false, 'error' => 'Rate limited. Wait a few seconds.'];
    }

    if (count($attempts) >= 60) {
        return ['ok' => false, 'error' => 'Rate limited. Too many submissions this hour.'];
    }

    $attempts[] = $now;
    $store['attempts'][$key] = $attempts;
    write_leaderboard_rate_store($store);

    return ['ok' => true];
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $game = trim((string)($_GET['game'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 10);

    if (!is_valid_game_slug($game)) {
        json_response(['error' => 'Unknown game slug.'], 400);
    }

    $limit = max(1, min(50, $limit));

    $store = read_leaderboard_store();
    $entries = $store['games'][$game] ?? [];
    if (!is_array($entries)) {
        $entries = [];
    }

    $entries = normalize_entries($entries);

    json_response([
        'game' => $game,
        'entries' => array_slice($entries, 0, $limit)
    ]);
}

if ($method === 'POST') {
    $body = read_json_input();
    $game = trim((string)($body['game'] ?? ''));
    $username = trim((string)($body['username'] ?? ''));
    $score = (int)($body['score'] ?? -1);

    if (!is_valid_game_slug($game)) {
        json_response(['error' => 'Unknown game slug.'], 400);
    }

    if (!is_valid_username($username)) {
        json_response(['error' => 'Username must be 3-24 chars (letters, numbers, _ or -).'], 400);
    }

    if ($score < 0 || $score > 1000000000) {
        json_response(['error' => 'Score must be between 0 and 1,000,000,000.'], 400);
    }

    $rate = rate_limit_submit($game);
    if (!($rate['ok'] ?? false)) {
        json_response(['error' => (string)($rate['error'] ?? 'Rate limited')], 429);
    }

    $store = read_leaderboard_store();
    $entries = $store['games'][$game] ?? [];
    if (!is_array($entries)) {
        $entries = [];
    }

    $existingIndex = -1;
    foreach ($entries as $index => $entry) {
        if (is_array($entry) && (($entry['username'] ?? '') === $username)) {
            $existingIndex = $index;
            break;
        }
    }

    if ($existingIndex >= 0) {
        $oldScore = (int)($entries[$existingIndex]['score'] ?? 0);
        if ($score > $oldScore) {
            $entries[$existingIndex]['score'] = $score;
            $entries[$existingIndex]['updatedAt'] = now_iso();
        }
    } else {
        $entries[] = [
            'username' => $username,
            'score' => $score,
            'createdAt' => now_iso(),
            'updatedAt' => now_iso()
        ];
    }

    $entries = normalize_entries($entries);
    $store['games'][$game] = $entries;
    write_leaderboard_store($store);

    json_response([
        'ok' => true,
        'game' => $game,
        'entries' => array_slice($entries, 0, 10)
    ]);
}

json_response(['error' => 'Method not allowed'], 405);
