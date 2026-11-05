<?php
/**
 * GET /api/leave_requests.php?status=Pending&employee_id=5       HR / admin
 * Every employee's leave requests, newest first. Both filters are optional.
 */
require_once __DIR__ . '/_api.php';

api_methods(['GET']);
$session = api_require_role(['admin', 'hr']);

require_once __DIR__ . '/../classes/LeaveRequest.php';

$status = api_query_enum('status', LeaveRequest::STATUSES);
$employeeId = api_query_id('employee_id');

try {
    require_once __DIR__ . '/../config/database.php';

    api_require_account($pdo, $session);
    $requests = (new LeaveRequest($pdo))->search($status, $employeeId);

    respond(200, [
        'success' => true,
        'count' => count($requests),
        'data' => $requests
    ]);
} catch (Throwable $e) {
    error_log('Leave requests API error: ' . $e->getMessage());
    api_error(500, 'Internal server error');
}
