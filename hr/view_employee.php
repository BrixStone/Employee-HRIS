<?php

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/Employee.php';

$employeeModel = new Employee($pdo);

$id = isset($_GET['id']) && ctype_digit($_GET['id']) 
    ? (int) $_GET['id'] 
    : 0;
if ($id <= 0) {
    header('Location: /hr/employees.php');
    exit;
}

try {
    $emp = $employeeModel->findById($id);
} catch (\Throwable $th) {
    header('Location: /hr/employees.php');
    exit;
}

if (!$emp) {
    header('Location: /hr/employees.php');
    exit;
}

function v($x): string {
    $s = ($x !== null && $x !== '')
    ? (string) $x
    : 'N/A';
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$fullname = trim(
    ($emp['first_name'] ?? '') . ' ' . 
    ((isset($emp['middle_name']) && $emp['middle_name'] !== '') 
    ? $emp['middle_name'] . ' ' 
    : '') . 
    ($emp['last_name'] ?? '') .
    ((isset($emp['suffix']) && $emp['suffix'] !== '') 
    ? ' ' . $emp['suffix'] 
    : '')

);

$dob = !empty($emp['date_of_birth'])
    ? date('F j, Y', strtotime($emp['date_of_birth']))
    : null;
$hired = !empty($emp['date_hired'])
    ? date('F j, Y', strtotime($emp['date_hired']))
    : null;

require __DIR__ . '/../templates/hrTemplates/header.php';
?>

<h1>Employee Profile</h1>

<p>
    <strong><?= v($emp['employee_id']) ?></strong>
    <?= v($fullname) ?>
    <em><?= v($emp['employment_status']) ?></em>
</p>

<h2>Personal Information</h2>
<ul>
    <li>Fullname:      <?= v($fullname) ?></li>
    <li>Date of Birth: <?= v($dob) ?></li>
    <li>Gender:        <?= v($emp['gender']) ?></li>
    <li>Phone:         <?= v($emp['phone']) ?></li>
    <li>Email:         <?=  v($emp['email']) ?></li>
    <li>Address:       <?= v($emp['address']) ?></li>
</ul>

<h2>Employment Information</h2>
<ul>
    <li>Department:      <?= v($emp['department_name']?? null) ?></li>
    <li>Postion:         <?= v($emp['position_title'] ?? null) ?></li>
    <li>Date Hired:      <?= v($hired) ?></li>
    <li>Employment Type: <?= v($emp['employment_type'] ?? null) ?></li>
    <li>Status:          <?= v($emp['employment_status'] ?? null) ?></li>
</ul>
<?php if (($emp['employment_status'] ?? '') !== 'Inactive'): ?>
  <form action="/hr/employee_deactivate.php" method="POST" onsubmit="return confirm('Mark this employee as Inactive?');" style="display:inline;">
    <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
    <button type="submit">Deactivate This Employee?</button>
  </form>
<?php endif; ?>
<p>
    <a href="/hr/edit_employee.php?id=<?=  (int) $emp['id'] ?>">Edit</a>
    |
    <a href="/hr/employees.php">Back to Employees</a>
</p>