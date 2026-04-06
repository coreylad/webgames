<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_admin_token();

$store = read_tip_store();
$tips = $store['tips'];

usort($tips, static function (array $a, array $b): int {
    return strtotime((string)($b['createdAt'] ?? '')) <=> strtotime((string)($a['createdAt'] ?? ''));
});

json_response(['tips' => $tips]);
