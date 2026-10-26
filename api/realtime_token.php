<?php
/**
 * GET /api/realtime_token.php
 * Returns a short-lived WebSocket token for the logged-in user, used by
 * asset/js/realtime.js. Employees only get their own channel; HR/admin get "hr".
 */
declare(strict_types=1);

require_once __DIR__ . '/../classes/Realtime.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit(json_encode(['enabled' => false, 'error' => 'Method not allowed']));
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$session = $_SESSION;
session_write_close(); // do not hold the session lock while the page loads

if (empty($session['user_id']) || empty($session['role'])) {
    http_response_code(401);
    exit(json_encode(['enabled' => false, 'error' => 'Not logged in']));
}

if (!Realtime::enabled()) {
    http_response_code(503);
    exit(json_encode(['enabled' => false, 'error' => 'Realtime server is not configured']));
}

$token = Realtime::tokenForSession($session);
if ($token === null) {
    http_response_code(403);
    exit(json_encode(['enabled' => false, 'error' => 'No realtime channel for this account']));
}

echo json_encode([
    'enabled'    => true,
    'url'        => Realtime::publicUrl($_SERVER),
    'token'      => $token,
    'role'       => strtolower((string)$session['role']),
    'expires_in' => 300,
], JSON_UNESCAPED_SLASHES);
