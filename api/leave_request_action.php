<?php
/**
 * POST /api/leave_request_action.php                             HR / admin / manager
 * Body: {"id": 42, "action": "approve"}   (or "reject")
 * A manager can only review requests from their own team.
 *
 * Approving deducts the days from the employee's leave balance. Both actions
 * are written to the leave audit log, which pushes a realtime event
 * (leave.approved / leave.rejected) to the employee and to HR.
 */
require_once __DIR__ . '/_api.php';

api_methods(['POST']);
$session = api_require_role(); // any signed-in role; the permission matrix decides

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/LeaveRequest.php';
    require_once __DIR__ . '/../classes/LeaveBalance.php';

    $account = api_require_account($pdo, $session);
    $scope = api_require_permission($pdo, $account, 'leave_requests', 'approve', ['all', 'team']);
    $input = api_json_body();

    $errors = [];
    api_reject_unknown($input, ['id', 'action'], $errors);
    $id = api_clean_id($input, 'id', $errors, true);
    $action = api_clean_enum($input, 'action', ['approve', 'reject'], $errors, true);
    if ($errors) {
        api_error(422, 'Validation failed', ['errors' => $errors]);
    }

    $model = new LeaveRequest($pdo);
    $request = $model->findById($id);
    // A manager reviewing someone outside their team gets the same answer as
    // for a request that does not exist.
    if ($request !== null && $scope === 'team'
        && !Rbac::isTeamMember($pdo, $account['employee_id'], (int)$request['employee_id'])) {
        $request = null;
    }
    if ($request === null) {
        api_error(404, 'Leave request not found');
    }
    if ($request['status'] !== 'Pending') {
        api_error(409, "Only Pending requests can be reviewed (this one is {$request['status']})");
    }

    // Reviewer recorded in the audit log, same as hr/leave_request_action.php.
    $actor = ['id' => $account['user_id'], 'role' => $account['role']];

    try {
        if ($action === 'approve') {
            $model->approve($id, $account['user_id'], $actor);
        } elseif (!$model->reject($id, $account['user_id'], $actor)) {
            api_error(409, 'This request was reviewed by someone else just now');
        }
    } catch (PDOException $e) {
        throw $e; // database failure: handled below as a 500
    } catch (RuntimeException $e) {
        // LeaveRequest::approve() reports rule failures (balance, multi-year
        // leave, already reviewed) as RuntimeException.
        $message = $e->getMessage();
        api_error($message === 'Request is not Pending' ? 409 : 422, $message);
    }

    respond(200, [
        'success' => true,
        'message' => $action === 'approve' ? 'Leave request approved' : 'Leave request rejected',
        'realtime_event' => $action === 'approve' ? 'leave.approved' : 'leave.rejected',
        'data' => $model->findById($id)
    ]);
} catch (Throwable $e) {
    error_log('Leave action API error: ' . $e->getMessage());
    api_error(500, 'Internal server error');
}
