<?php
/**
 * GET  /api/employees.php   List employees (search + sort)   HR / admin
 * POST /api/employees.php   Create an employee (JSON body)     HR / admin
 */
require_once __DIR__ . '/_api.php';

$method  = api_methods(['GET', 'POST']);
$session = api_require_role(); // any signed-in role; the permission matrix decides

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/Employee.php';

    $account = api_require_account($pdo, $session);
    // Everyone's records (HR/admin) or just the manager's team to read; only
    // everyone's records to create.
    $scope = api_require_permission(
        $pdo, $account, 'employee_records',
        $method === 'POST' ? 'edit' : 'view',
        $method === 'POST' ? ['all'] : ['all', 'team']
    );
    $model = new Employee($pdo);

    if ($method === 'POST') {
        require_once __DIR__ . '/_employee.php';

        [$data, $errors] = employee_input($pdo, api_json_body());
        if ($errors) {
            api_error(422, 'Validation failed', ['errors' => $errors]);
        }

        try {
            $model->create($data);
        } catch (PDOException $e) {
            // Two creates at the same moment can generate the same EMP-###.
            if (api_is_duplicate_key($e)) {
                api_error(409, 'Could not assign a unique employee ID. Please retry.');
            }
            throw $e;
        }

        $id = (int)$pdo->lastInsertId();
        header('Location: /api/employee.php?id=' . $id);
        respond(201, [
            'success' => true,
            'message' => 'Employee created',
            'data' => $model->findById($id)
        ]);
    }

    $employees = $model->getAll();

    if ($scope === 'team') {
        $team = Rbac::teamEmployeeIds($pdo, $account['employee_id']);
        $employees = array_values(array_filter(
            $employees,
            static fn (array $e): bool => in_array((int)$e['id'], $team, true)
        ));
    }

    $query = trim($_GET['q'] ?? '');

    if ($query !== '') {
        $employees = array_values(array_filter(
            $employees,
            static function (array $e) use ($query): bool {
                $name = trim(
                    ($e['first_name'] ?? '') . ' ' .
                    ($e['middle_name'] ?? '') . ' ' .
                    ($e['last_name'] ?? '') . ' ' .
                    ($e['suffix'] ?? '')
                );

                $text = ($e['employee_id'] ?? '') . ' ' .
                    $name . ' ' . ($e['email'] ?? '');

                return stripos($text, $query) !== false;
            }
        ));
    }

    $fields = [
        'employee_id' => 'employee_id',
        'name' => 'last_name',
        'department_name' => 'department_name',
        'position_title' => 'position_title',
        'date_hired' => 'date_hired',
        'employment_status' => 'employment_status'
    ];

    $sort = $_GET['sort_by'] ?? '';
    $dir = strtolower($_GET['dir'] ?? 'asc');

    if ($sort !== '' && !isset($fields[$sort])) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid sort_by parameter'
        ]);
    }

    if (!in_array($dir, ['asc', 'desc'], true)) {
        respond(400, [
            'success' => false,
            'message' => 'dir must be asc or desc'
        ]);
    }

    if ($sort !== '') {
        $field = $fields[$sort];

        usort($employees, static function (
            array $a,
            array $b
        ) use ($field, $dir): int {
            $result = strnatcasecmp(
                (string) ($a[$field] ?? ''),
                (string) ($b[$field] ?? '')
            );

            return $dir === 'desc' ? -$result : $result;
        });
    }

    respond(200, [
        'success' => true,
        'count' => count($employees),
        'data' => $employees
    ]);
} catch (Throwable $e) {
    error_log('Employee API error: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
