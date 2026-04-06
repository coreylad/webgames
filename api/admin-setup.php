<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

// One-time admin setup endpoint.
// Only works when data/admins.json contains zero admin records.
// Once any admin exists this endpoint returns 403 on POST.

$store = read_admin_store();
$has_admins = count($store['admins']) > 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['needsSetup' => !$has_admins]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

if ($has_admins) {
    json_response(['error' => 'Setup already completed. Use the login page.'], 403);
}

$body    = read_json_input();
$username = normalize_admin_username((string)($body['username'] ?? ''));
$password = (string)($body['password'] ?? '');

if (!is_valid_admin_username($username)) {
    json_response(['error' => 'Username must be 3–24 characters: letters, numbers, _ or -.'], 400);
}

if (strlen($password) < 8) {
    json_response(['error' => 'Password must be at least 8 characters.'], 400);
}

$record = [
    'id'        => generate_id(),
    'username'  => $username,
    'tokenHash' => password_hash($password, PASSWORD_DEFAULT),
    'createdAt' => now_iso(),
];

$store['admins'][] = $record;
write_admin_store($store);

// Immediately create a session so we return a token the client can use.
$ip      = client_ip_address();
$session = create_admin_session($record['id'], $record['username'], $ip);

json_response([
    'status'       => 'ok',
    'message'      => 'Admin account created.',
    'sessionToken' => $session['token'],
    'admin'        => ['username' => $record['username']],
]);
