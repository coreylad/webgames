<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_admin_token();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

ignore_user_abort(true);
set_time_limit(35);

$since = (int)($_GET['since'] ?? 0);

$emit = static function (string $eventType, array $payload, int $id): void {
    echo 'id: ' . $id . "\n";
    echo 'event: ' . $eventType . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
};

$start = time();
$timeoutSeconds = 30;

while ((time() - $start) < $timeoutSeconds) {
    if (connection_aborted()) {
        exit;
    }

    $signalStore = read_admin_signal_store();
    $latest = is_array($signalStore['latestEvent'] ?? null) ? $signalStore['latestEvent'] : null;
    if ($latest !== null) {
        $latestId = (int)($latest['id'] ?? 0);
        if ($latestId > $since) {
            $emit((string)($latest['type'] ?? 'message'), $latest, $latestId);
            exit;
        }
    }

    usleep(500000);
}

$emit('heartbeat', ['ok' => true, 'ts' => now_iso()], $since);
