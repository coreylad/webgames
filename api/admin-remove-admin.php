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

if (!is_valid_admin_username($username)) {
    json_response(['error' => 'Invalid admin username.'], 400);
}

$store = read_admin_store();
$admins = $store['admins'];

$indexToRemove = -1;
foreach ($admins as $index => $admin) {
    if (($admin['username'] ?? '') === $username) {
        $indexToRemove = $index;
        break;
    }
}

if ($indexToRemove === -1) {
    json_response(['error' => 'Admin user not found.'], 404);
}

if (count($admins) <= 1) {
    json_response(['error' => 'Cannot remove the last admin.'], 400);
}

array_splice($admins, $indexToRemove, 1);
$store['admins'] = array_values($admins);
write_admin_store($store);

json_response(['ok' => true]);
