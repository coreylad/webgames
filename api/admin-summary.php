<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_admin_token();

$store = read_tip_store();
$tips = $store['tips'];

$usernames = [];
$totalPaidCents = 0;

foreach ($tips as $tip) {
    $username = trim((string)($tip['username'] ?? ''));
    if ($username !== '') {
        $usernames[$username] = true;
    }

    if (($tip['status'] ?? '') === 'paid') {
        $totalPaidCents += (int)($tip['amountCents'] ?? 0);
    }
}

json_response([
    'uniqueUsernames' => array_keys($usernames),
    'totalTips' => count($tips),
    'totalPaidCents' => $totalPaidCents
]);
