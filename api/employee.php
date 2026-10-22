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

    $model = new Employee($pdo);
    $employee = $model->findById($id);

    if ($employee === null) {
        respond(404, [
            'success' => false,
            'message' => 'Employee not found'
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