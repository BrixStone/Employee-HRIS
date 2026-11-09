<?php
/**
 * GET   /api/employee.php?id=N   One employee                         HR / admin
 * PATCH /api/employee.php?id=N   Update only the fields in the body   HR / admin
 */
require_once __DIR__ . '/_api.php';

$method  = api_methods(['GET', 'PATCH']);
$session = api_require_role(); // any signed-in role; the permission matrix decides

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    respond(400, [
        'success' => false,
        'message' => 'A valid positive employee ID is required'
    ]);
}

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/Employee.php';

    $account = api_require_account($pdo, $session);
    $scope = api_require_permission(
        $pdo, $account, 'employee_records',
        $method === 'PATCH' ? 'edit' : 'view',
        $method === 'PATCH' ? ['all'] : ['all', 'team']
    );
    $model = new Employee($pdo);
    $employee = $model->findById($id);

    // A manager asking for someone outside their team gets the same answer as
    // for a record that does not exist, so ids cannot be probed.
    if ($employee !== null && $scope === 'team'
        && !Rbac::isTeamMember($pdo, $account['employee_id'], (int)$employee['id'])) {
        $employee = null;
    }

    if ($employee === null) {
        respond(404, [
            'success' => false,
            'message' => 'Employee not found'
        ]);
    }

    if ($method === 'PATCH') {
        require_once __DIR__ . '/_employee.php';

        $input = api_json_body();
        if ($input === []) {
            api_error(422, 'Send at least one field to update');
        }

        [$changes, $errors] = employee_input($pdo, $input, $employee);
        if ($errors) {
            api_error(422, 'Validation failed', ['errors' => $errors]);
        }

        // Employee::update() writes every column: start from the current row.
        $data = array_replace(
            array_intersect_key($employee, array_flip(EMPLOYEE_FIELDS)),
            $changes
        );

        try {
            $model->update($id, $data);
        } catch (PDOException $e) {
            if (api_is_duplicate_key($e)) {
                api_error(409, 'Employee ID already exists. Please use a different one.');
            }
            throw $e;
        }

        respond(200, [
            'success' => true,
            'message' => 'Employee updated',
            'data' => $model->findById($id)
        ]);
    }

    respond(200, [
        'success' => true,
        'data' => $employee
    ]);
} catch (Throwable $e) {
    error_log('Employee API error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
