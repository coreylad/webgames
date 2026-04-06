<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_admin_token();
ensure_bootstrap_admin();

$body = read_json_input();
$username = normalize_admin_username((string)($body['username'] ?? ''));
$token = trim((string)($body['token'] ?? ''));

if (!is_valid_admin_username($username)) {
    json_response(['error' => 'Username must be 3-24 chars: lowercase letters, numbers, _ or -.'], 400);
}

if (strlen($token) < 8) {
    json_response(['error' => 'Token must be at least 8 characters.'], 400);
}

$store = read_admin_store();
foreach ($store['admins'] as $admin) {
    if (($admin['username'] ?? '') === $username) {
        json_response(['error' => 'Admin username already exists.'], 409);
    }
}

$record = [
    'id' => generate_id(),
    'username' => $username,
    'tokenHash' => password_hash($token, PASSWORD_DEFAULT),
    'createdAt' => now_iso()
];

$store['admins'][] = $record;
write_admin_store($store);

json_response([
    'ok' => true,
    'admin' => [
        'id' => $record['id'],
        'username' => $record['username'],
        'createdAt' => $record['createdAt']
    ]
]);
