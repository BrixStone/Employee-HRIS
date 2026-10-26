<?php

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/Employee.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /hr/employees.php');
    exit;
}

$id = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    header('Location: /hr/employees.php');
    exit;
}

$employeeModel = new Employee($pdo);
$employeeModel->deactivate($id);


header('Location: /hr/view_employee.php?id=' . $id);
exit;