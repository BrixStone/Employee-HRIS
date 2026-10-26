<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$role = $_SESSION['role'] ?? null;

if (!in_array($role, ['admin', 'hr'], true)) {
    respond($role === null ? 401 : 403, [
        'success' => false,
        'message' => $role === null
            ? 'Authentication required'
            : 'Insufficient permissions'
    ]);
}

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/Employee.php';

    $model = new Employee($pdo);
    $employees = $model->getAll();

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
