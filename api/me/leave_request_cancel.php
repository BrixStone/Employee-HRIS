<?php
/**
 * POST /api/me/leave_request_cancel.php                            employee
 * Body: {"id": 42}
 *
 * Withdraws one of the employee's own Pending requests (same as the Cancel
 * button in employee/leave_history.php). Pushes a realtime
 * "leave.cancelled" event to the employee and to HR.
 */
require_once __DIR__ . '/../_api.php';

api_methods(['POST']);
$session = api_require_role(['employee']);

try {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../classes/LeaveRequest.php';

    $account = api_require_employee($pdo, $session);
    api_require_permission($pdo, $account, 'leave_requests', 'submit', ['own']);
    $employeeId = $account['employee_id'];
    $input = api_json_body();

    $errors = [];
    api_reject_unknown($input, ['id'], $errors);
    $id = api_clean_id($input, 'id', $errors, true);
    if ($errors) {
        api_error(422, 'Validation failed', ['errors' => $errors]);
    }

    $model = new LeaveRequest($pdo);
    $request = $model->findById($id);

    // Another employee's request is reported as missing, not as forbidden.
    if ($request === null || (int)$request['employee_id'] !== $employeeId) {
        api_error(404, 'Leave request not found');
    }
    if ($request['status'] !== 'Pending') {
        api_error(409, "Only Pending requests can be cancelled (this one is {$request['status']})");
    }

    $actor = [
        'id' => $employeeId,
        'name' => $account['name'],
        'email' => $account['email'],
        'role' => 'employee',
    ];
    if (!$model->cancel($id, $employeeId, $actor)) {
        api_error(409, 'This request was reviewed by HR just now and can no longer be cancelled');
    }

    respond(200, [
        'success' => true,
        'message' => "Leave request #{$id} cancelled",
        'realtime_event' => 'leave.cancelled',
        'data' => $model->findById($id)
    ]);
} catch (Throwable $e) {
    error_log('Cancel leave API error: ' . $e->getMessage());
    api_error(500, 'Internal server error');
}
