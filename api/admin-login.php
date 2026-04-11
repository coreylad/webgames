<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/analytics.php';

// Session functions now live in common.php.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$action = (string)($_GET['action'] ?? '');

if ($action === 'login') {
    // Login endpoint
    $data = read_json_input();
    $username = trim((string)($data['username'] ?? ''));
    $password = trim((string)($data['password'] ?? ''));
    
    if ($username === '' || $password === '') {
        json_response(['error' => 'Username and password required'], 400);
    }
    
    // Find admin user
    ensure_bootstrap_admin();
    $store = read_admin_store();
    
    $admin = null;
    foreach ($store['admins'] as $candidate) {
        if (normalize_admin_username($candidate['username'] ?? '') === normalize_admin_username($username)) {
            $admin = $candidate;
            break;
        }
    }
    
    if ($admin === null) {
        // Generic error to prevent username enumeration
        json_response(['error' => 'Invalid credentials'], 401);
    }
    
    // Verify password
    $tokenHash = (string)($admin['tokenHash'] ?? '');
    if (!password_verify($password, $tokenHash)) {
        json_response(['error' => 'Invalid credentials'], 401);
    }
    
    // Check for legacy token match
    $legacyToken = env_value('ADMIN_DASHBOARD_TOKEN', '');
    if ($legacyToken !== '' && hash_equals($legacyToken, $password)) {
        // Legacy token is valid
    } elseif ($tokenHash === '' || !password_verify($password, $tokenHash)) {
        json_response(['error' => 'Invalid credentials'], 401);
    }
    
    // Create session
    $ip = client_ip_address();
    $session = create_admin_session(
        (string)($admin['id'] ?? ''),
        (string)($admin['username'] ?? ''),
        $ip
    );
    
    track_event('admin_login', $username, 'system', ['ip' => $ip]);
    
    json_response([
        'status' => 'ok',
        'message' => 'Logged in successfully',
        'sessionToken' => $session['token'],
        'admin' => [
            'username' => $session['username']
        ]
    ]);

} elseif ($action === 'validate') {
    // Validate session
    $body = read_json_input();
    $token = trim((string)($body['token'] ?? ($_POST['token'] ?? '')));
    
    $session = get_admin_session($token);
    
    if ($session === null) {
        json_response(['error' => 'Invalid or expired session'], 401);
    }
    
    json_response([
        'status' => 'ok',
        'valid' => true,
        'admin' => [
            'username' => $session['username']
        ],
        'expiresAt' => $session['expiresAt']
    ]);

} elseif ($action === 'logout') {
    // Logout
    $body = read_json_input();
    $token = trim((string)($body['token'] ?? ($_POST['token'] ?? '')));
    
    if ($token !== '') {
        destroy_admin_session($token);
    }
    
    json_response([
        'status' => 'ok',
        'message' => 'Logged out'
    ]);

} elseif ($action === 'list-sessions') {
    // List admin's active sessions
    $body = read_json_input();
    $token = trim((string)($body['token'] ?? ($_POST['token'] ?? '')));
    
    $session = get_admin_session($token);
    if ($session === null) {
        json_response(['error' => 'Invalid session'], 401);
    }
    
    $activeSessions = get_active_admin_sessions($session['adminId']);
    
    $sanitized = array_map(fn($s) => [
        'id' => $s['id'],
        'ip' => $s['ip'],
        'userAgent' => $s['userAgent'],
        'createdAt' => $s['createdAt'],
        'lastActivityAt' => $s['lastActivityAt'],
        'expiresAt' => $s['expiresAt'],
        'isCurrentSession' => $s['token'] === $token
    ], $activeSessions);
    
    json_response([
        'status' => 'ok',
        'sessions' => $sanitized
    ]);

} else {
    json_response(['error' => 'Unknown action'], 404);
}
