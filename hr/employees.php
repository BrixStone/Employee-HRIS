<?php

require __DIR__ . '/../config/supabase.php';
require __DIR__ . '/../classes/Employee.php';
require __DIR__ . '/../algorithms/linear_search.php';
require __DIR__ . '/../algorithms/bubble_sort.php';

$employeeModel = new Employee($pdo);
$employees = $employeeModel->getAll();

$q = trim($_GET['q'] ?? '');

$rawSort = $_GET['sort_by']?? '';

$dir = ($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc';

$sortKeyMap = [
    '' => '',
    'name' => 'name',
    'department_name'   => 'department_name',
    'position_title'    => 'position_title',
    'date_hired'        => 'date_hired',
    'employment_status' => 'employment_status',
    'employee_id'       => 'employee_id',
];

$sortBy = $sortKeyMap[$rawSort] ?? '';

$allowed = array_values($sortKeyMap);

if (!in_array($sortBy, $allowed, true)) {
    $sortBy = '';
}

function emp_full_name(array $e): string {
    $m = !empty($e['middle_name']) ? $e['middle_name'] . ' ' : '';
    $s = !empty($e['suffix']) ? ' ' . $e['suffix'] : '';
    return trim(
        ($e['first_name'] ?? '') . ' ' . $m.($e['last_name'] ?? '').$s);
}

function sort_key_value(array $e, string $key) {
    if ($key === 'name') {
        return strtolower(emp_full_name($e));
    }
    $v = $e[$key] ?? '';
    return is_string($v) ? strtolower($v) : $v;
}



function contains_i(?string $haystack, string $needle): bool {
    if ($haystack === null) return false;

    return stripos($haystack, $needle) !== false;
}

$employees = linear_search_employees($employees, $q);

if ($sortBy !== '') {
    bubble_sort_employees($employees, $sortBy, $dir);
}
require __DIR__ . '/../templates/hrTemplates/header.php';
?>

<h1>Employee Management</h1>

<a href="add_employee.php">Add Employee</a>

<form action="" method="get">
    <strong>Search: </strong>
    <input 
    type="text"
    name="q"
    value="<?= htmlspecialchars($q) ?>"
    >

    <select name="sort_by" id="">
        <option value=""                    <?= $rawSort=== ''? 'selected': '' ?>>No sorting</option>
        <option value="name"                <?= $rawSort=== 'name' ? 'selected': '' ?>>Name</option>
        <option value="department_name"     <?= $rawSort=== 'department_name' ? 'selected' : '' ?>>Department</option>
        <option value="position_title"      <?= $rawSort=== 'position_title' ? 'selected' : '' ?>>Position</option>
        <option value="date_hired"          <?= $rawSort=== 'date_hired' ? 'selected' : '' ?>>Date Hired</option>
        <option value="employment_status"   <?= $rawSort=== 'employment_status' ? 'selected' : '' ?>>Status</option>
        <option value="employee_id"         <?= $rawSort=== 'employee_id' ? 'selected' : '' ?>>Employee ID</option>
    </select>

    <select name="dir" id="">
        <option value="asc"     <?= $dir=== 'asc' ? 'selected' : '' ?>>ASC</option>
        <option value="desc"    <?= $dir=== 'desc' ? 'selected' : '' ?>>DESC</option>
    </select>

    <button type="submit">Apply</button>
    <a href="/hr/employees.php">Reset</a>
</form>
<table border="1" cellpadding="8" cellspacing="0">
    <thead>
        <tr>
            <th>Employee ID</th>
            <th>Name</th>
            <th>Department</th>
            <th>Position</th>
            <th>Date Hired</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>

    <tbody>

        <?php if (empty($employees)): ?>

            <tr>
                <td colspan="7">
                    No employees found.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($employees as $employee): ?>

                <tr>

                    <td>
                        <?= htmlspecialchars($employee['employee_id']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            $employee['first_name'] . ' ' .
                            ($employee['middle_name'] 
                            ? $employee['middle_name'] . ' ' 
                            : '') .
                            $employee['last_name'] .
                            ($employee['suffix'] 
                            ? ' ' . $employee['suffix'] 
                            : '')
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($employee['department_name'] ?? 'N/A') ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($employee['position_title'] ?? 'N/A') ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($employee['date_hired']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($employee['employment_status']) ?>
                    </td>

                    <td>
                        <a href="view_employee.php?id=<?= $employee['id'] ?>">
                            View
                        </a>

                        |

                        <a href="edit_employee.php?id=<?= $employee['id'] ?>">
                            Edit
                        </a>
                    </td>

                </tr>

            <?php endforeach; ?>

        <?php endif; ?>

    </tbody>
</table>
