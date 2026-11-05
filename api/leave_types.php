<?php
/**
 * GET /api/leave_types.php                           any signed-in account
 * Active leave types, the same list the "File leave" form offers.
 */
require_once __DIR__ . '/_api.php';

api_methods(['GET']);
$session = api_require_role();

try {
    require_once __DIR__ . '/../config/database.php';

    api_require_account($pdo, $session);

    $types = $pdo->query("
        SELECT id, leave_name, description, default_days
        FROM leave_types
        WHERE status = 'Active' OR status IS NULL
        ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'count' => count($types),
        'data' => $types
    ]);
} catch (Throwable $e) {
    error_log('Leave types API error: ' . $e->getMessage());
    api_error(500, 'Internal server error');
}
