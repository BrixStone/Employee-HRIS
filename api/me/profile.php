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
    $employeeId = $account['employee_id'];
    $model = new Employee($pdo);

    if ($method === 'PATCH') {
        $input = api_json_body();
        if ($input === []) {
            api_error(422, 'Send phone and/or address to update');
        }

        $hrManaged = array_values(array_diff(
            array_intersect(array_keys($input), array_merge(EMPLOYEE_FIELDS, ['id'])),
            EMPLOYEE_SELF_FIELDS
        ));
        if ($hrManaged) {
            api_error(403, 'Employees can only update phone and address. Contact HR to change: '
                . implode(', ', $hrManaged), ['fields' => $hrManaged]);
        }

        $errors = [];
        api_reject_unknown($input, EMPLOYEE_SELF_FIELDS, $errors);
        $changes = [];
        if (array_key_exists('phone', $input)) {
            $changes['phone'] = employee_clean_phone($input, $errors);
        }
        if (array_key_exists('address', $input)) {
            $changes['address'] = employee_clean_address($input, $errors);
        }
        if ($errors) {
            api_error(422, 'Validation failed', ['errors' => $errors]);
        }

        // Column names come from the fixed EMPLOYEE_SELF_FIELDS list above.
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($changes)));
        $st = $pdo->prepare("UPDATE employees SET {$set}, updated_at = NOW() WHERE id = :id");
        $st->execute($changes + ['id' => $employeeId]);
    }

    $employee = $model->findById($employeeId);
    if ($employee === null) {
        api_error(404, 'Employee record not found');
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
