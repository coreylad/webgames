<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_admin_token();
ensure_bootstrap_admin();

$store = read_admin_store();
$admins = array_map(static function (array $admin): array {
    return [
        'id' => (string)($admin['id'] ?? ''),
        'username' => (string)($admin['username'] ?? ''),
        'createdAt' => (string)($admin['createdAt'] ?? '')
    ];
}, $store['admins']);

usort($admins, static fn(array $a, array $b): int => strcmp($a['username'], $b['username']));

json_response(['admins' => $admins]);
