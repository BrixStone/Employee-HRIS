<?php
/**
 * GET /api/realtime_token.php
 * Returns a short-lived WebSocket token for the logged-in user, used by
 * asset/js/realtime.js. Employees only get their own channel; HR/admin get "hr".
 *
 * RBAC: the role and channels are taken from a FRESH database read of the
 * account (status + current role), not just the PHP session. A user who was
 * deactivated or whose role changed after signing in is refused here, so the
 * realtime feature enforces the same access rules as the rest of the API.
 * realtime.js re-requests a token on every (re)connect, so access is revoked
 * within one reconnect of the change.
 */
declare(strict_types=1);

require_once __DIR__ . '/_api.php';                 // api_* helpers (fresh DB re-check)
require_once __DIR__ . '/../classes/Realtime.php';

// Reuse the shared helpers' JSON error shape would be {success,message};
// keep this endpoint's existing {enabled,error} shape that realtime.js expects.
function realtime_fail(int $status, string $error): never
{
    http_response_code($status);
    exit(json_encode(['enabled' => false, 'error' => $error], JSON_UNESCAPED_SLASHES));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    realtime_fail(405, 'Method not allowed');
}

$session = api_session(); // reads + closes the session without holding the lock

if (empty($session['user_id']) || empty($session['role'])) {
    realtime_fail(401, 'Not logged in');
}

if (!Realtime::enabled()) {
    realtime_fail(503, 'Realtime server is not configured');
}

try {
    require_once __DIR__ . '/../config/database.php';

    // Fresh DB check: 401 if the account is inactive or its role changed.
    // api_require_account() normally emits {success,message}; translate its
    // outcome into this endpoint's {enabled,error} shape instead.
    $role = strtolower(trim((string)$session['role']));
    $st = $pdo->prepare("
        SELECT u.id AS user_id, u.role, u.status, e.id AS employee_id
        FROM users AS u
        LEFT JOIN employees AS e ON e.id = u.employee_id
        WHERE u.id = :id
        LIMIT 1
    ");
    $st->execute(['id' => (int)$session['user_id']]);
    $account = $st->fetch(PDO::FETCH_ASSOC);

    if (!$account || strcasecmp((string)$account['status'], 'Active') !== 0) {
        realtime_fail(401, 'This account is inactive or no longer exists. Please sign in again.');
    }
    if (strtolower(trim((string)$account['role'])) !== $role) {
        realtime_fail(401, 'Your account role has changed. Please sign in again.');
    }

    // Build the token from the VERIFIED role/employee_id, not the session, and
    // choose the channels from the permission matrix: HR/admin hear everything,
    // a manager hears their own team, an employee hears only themselves.
    $employeeId = $account['employee_id'] !== null ? (int)$account['employee_id'] : null;
    $token = Realtime::tokenForAccount([
        'user_id'     => (int)$account['user_id'],
        'role'        => $account['role'],
        'employee_id' => $employeeId,
        'channels'    => Realtime::channelsForAccount($pdo, (string)$account['role'], $employeeId),
    ]);

    if ($token === null) {
        realtime_fail(403, 'No realtime channel for this account');
    }

    echo json_encode([
        'enabled'    => true,
        'url'        => Realtime::publicUrl($_SERVER),
        'token'      => $token,
        'role'       => strtolower((string)$account['role']),
        'expires_in' => 300,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('realtime_token error: ' . $e->getMessage());
    realtime_fail(500, 'Internal server error');
}
