<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$config = read_runtime_config_store();

json_response([
    'status' => 'ok',
    'games' => $config['games'] ?? []
]);
