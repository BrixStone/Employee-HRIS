<?php
/**
 * GET /api/leave_requests.php?status=Pending&employee_id=5       HR / admin / manager
 * Leave requests, newest first. Both filters are optional.
 * HR and admin see everyone's; a manager only sees their own team's.
 */
require_once __DIR__ . '/_api.php';

api_methods(['GET']);
$session = api_require_role(); // any signed-in role; the permission matrix decides

require_once __DIR__ . '/../classes/LeaveRequest.php';

$status = api_query_enum('status', LeaveRequest::STATUSES);
$employeeId = api_query_id('employee_id');

try {
    require_once __DIR__ . '/../config/database.php';

    $account = api_require_account($pdo, $session);
    $scope = api_require_permission($pdo, $account, 'leave_requests', 'view', ['all', 'team']);
    $model = new LeaveRequest($pdo);

    if ($scope === 'team') {
        $team = Rbac::teamEmployeeIds($pdo, $account['employee_id']);
        // Asking for someone outside the team just returns nothing.
        $ids = $employeeId !== null ? array_values(array_intersect([$employeeId], $team)) : $team;
        $requests = $model->searchForEmployees($ids, $status);
    } else {
        $requests = $model->search($status, $employeeId);
    }

    respond(200, [
        'success' => true,
        'count' => count($requests),
        'data' => $requests
    ]);
} catch (Throwable $e) {
    error_log('Leave requests API error: ' . $e->getMessage());
    api_error(500, 'Internal server error');
}
