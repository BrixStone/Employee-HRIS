<?php
/**
 * GET   /api/me/profile.php   The signed-in employee's own profile   employee
 * PATCH /api/me/profile.php   Update own phone and/or address         employee
 *
 * Name, email and employment details are managed by HR
 * (PATCH /api/employee.php) and are refused here with 403.
 */
require_once __DIR__ . '/../_api.php';
require_once __DIR__ . '/../_employee.php';

$method  = api_methods(['GET', 'PATCH']);
$session = api_require_role(['employee']);

/** Public view of the employee's own record (no internal columns). */
function profile_view(array $employee, array $account): array
{
    return [
        'id'                => (int)$employee['id'],
        'employee_id'       => $employee['employee_id'],
        'first_name'        => $employee['first_name'],
        'middle_name'       => $employee['middle_name'],
        'last_name'         => $employee['last_name'],
        'suffix'            => $employee['suffix'],
        'full_name'         => fullName($employee),
        'email'             => $employee['email'],
        'phone'             => $employee['phone'],
        'address'           => $employee['address'],
        'gender'            => $employee['gender'],
        'date_of_birth'     => $employee['date_of_birth'],
        'department_name'   => $employee['department_name'],
        'position_title'    => $employee['position_title'],
        'date_hired'        => $employee['date_hired'],
        'employment_type'   => $employee['employment_type'],
        'employment_status' => $employee['employment_status'],
        'avatar_url'        => Avatar::url($employee),
        'account'           => [
            'username' => $account['username'],
            'role'     => $account['role'],
            'status'   => $account['status'],
        ],
        'editable_fields'   => EMPLOYEE_SELF_FIELDS,
    ];
}

try {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../classes/Employee.php';
    require_once __DIR__ . '/../../classes/Avatar.php';

    $account = api_require_employee($pdo, $session);
    api_require_permission($pdo, $account, 'employee_records', $method === 'PATCH' ? 'edit' : 'view', ['own']);
    $employeeId = $account['employee_id'];
    $model = new Employee($pdo);

    $employee = $model->findById($employeeId);
    if ($employee === null) {
        api_error(404, 'Employee record not found');
    }

    if ($method === 'PATCH') {
        $input = api_json_body();
        if ($input === []) {
            api_error(422, 'Send at least one field to update');
        }

        // Reject HR/org-only fields outright with a clear message, before
        // validating the rest. Employee ID and Employment Status are the ones
        // the user most expects to be locked, so name them explicitly.
        $locked = array_values(array_diff(
            array_intersect(array_keys($input), array_merge(EMPLOYEE_FIELDS, ['id'])),
            EMPLOYEE_SELF_FIELDS
        ));
        if ($locked) {
            api_error(403, 'These fields are managed by HR and cannot be changed here: '
                . implode(', ', $locked) . '. You can update your own personal details only.',
                ['fields' => $locked]);
        }

        [$changes, $errors] = employee_self_input($pdo, $input, $employee);
        if ($errors) {
            api_error(422, 'Validation failed', ['errors' => $errors]);
        }
        if (!$changes) {
            api_error(422, 'Send at least one field to update');
        }

        // Column names come only from the fixed EMPLOYEE_SELF_FIELDS allow-list.
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($changes)));
        $st = $pdo->prepare("UPDATE employees SET {$set}, updated_at = NOW() WHERE id = :id");
        $st->execute($changes + ['id' => $employeeId]);

        // Re-read so the response reflects the saved values.
        $employee = $model->findById($employeeId);
    }

    $body = ['success' => true];
    if ($method === 'PATCH') {
        $body['message'] = 'Profile updated';
    }
    $body['data'] = profile_view($employee, $account);

    respond(200, $body);
} catch (Throwable $e) {
    error_log('Profile API error: ' . $e->getMessage());
    api_error(500, 'Internal server error');
}
