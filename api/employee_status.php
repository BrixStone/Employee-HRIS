<?php
/**
 * POST /api/employee_status.php                                  HR / admin
 * Body: {"id": 12, "status": "Inactive"}   (or "Active" to reactivate)
 *
 * Deactivates (or reactivates) an employee and every login account linked
 * to them, in one transaction. Inactive accounts cannot sign in
 * (authentication/login.php) and their open sessions are refused by the API.
 */
require_once __DIR__ . '/_api.php';

api_methods(['POST']);
$session = api_require_role(); // any signed-in role; the permission matrix decides

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/Employee.php';
    require_once __DIR__ . '/../classes/User.php';

    $account = api_require_account($pdo, $session);
    api_require_permission($pdo, $account, 'employee_records', 'edit', ['all']);
    $input = api_json_body();

    $errors = [];
    api_reject_unknown($input, ['id', 'status'], $errors);
    $id = api_clean_id($input, 'id', $errors, true);
    $status = api_clean_enum($input, 'status', ['Active', 'Inactive'], $errors, true);
    if ($errors) {
        api_error(422, 'Validation failed', ['errors' => $errors]);
    }

    $model = new Employee($pdo);
    $employee = $model->findById($id);
    if ($employee === null) {
        api_error(404, 'Employee not found');
    }

    if ($status === 'Inactive' && $account['employee_id'] === $id) {
        api_error(409, 'You cannot deactivate your own account');
    }

    $pdo->beginTransaction();
    $status === 'Inactive' ? $model->deactivate($id) : $model->activate($id);
    $logins = (new User($pdo))->setStatusForEmployee($id, $status);
    $pdo->commit();

    respond(200, [
        'success' => true,
        'message' => $status === 'Inactive'
            ? 'Employee and linked login accounts deactivated'
            : 'Employee and linked login accounts reactivated',
        'data' => [
            'id' => $id,
            'employee_id' => $employee['employee_id'],
            'employment_status' => $status,
            'login_status' => $status,
            'login_accounts_updated' => $logins
        ]
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Employee status API error: ' . $e->getMessage());
    api_error(500, 'Internal server error');
}
