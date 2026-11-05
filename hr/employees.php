<?php

require __DIR__ . '/../config/database.php';
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

// Lightweight counts for the stat strip (based on the loaded result set).
$totalShown = count($employees);
$activeShown = 0;
foreach ($employees as $__e) {
    if (strcasecmp((string)($__e['employment_status'] ?? ''), 'Active') === 0) {
        $activeShown++;
    }
}
$inactiveShown = $totalShown - $activeShown;
?>

<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">PEOPLE</p>
    <h1>Employee Management</h1>
    <p>Search, sort, and manage every employee record in one place.</p>
  </div>
  <div class="ph-actions">
    <a class="primary-button" href="add_employee.php">＋ Add Employee</a>
  </div>
</section>

<div class="metric-strip">
  <article class="metric-card metric-teal">
    <div class="metric-top"><span class="metric-ico">♟</span><span class="metric-chip"><?= $q !== '' ? 'Filtered' : 'All' ?></span></div>
    <span class="metric-title">Employees Shown</span>
    <strong class="metric-number teal"><?= number_format($totalShown) ?></strong>
  </article>
  <article class="metric-card metric-green">
    <div class="metric-top"><span class="metric-ico">✓</span></div>
    <span class="metric-title">Active</span>
    <strong class="metric-number green"><?= number_format($activeShown) ?></strong>
  </article>
  <article class="metric-card metric-blue">
    <div class="metric-top"><span class="metric-ico">✕</span></div>
    <span class="metric-title">Inactive</span>
    <strong class="metric-number blue"><?= number_format($inactiveShown) ?></strong>
  </article>
  <article class="metric-card metric-violet">
    <div class="metric-top"><span class="metric-ico">⇅</span><span class="metric-chip"><?= $sortBy !== '' ? strtoupper($dir) : 'None' ?></span></div>
    <span class="metric-title">Sorting</span>
    <strong class="metric-number purple" style="font-size:20px;margin-top:14px"><?= $sortBy !== '' ? ucfirst(str_replace('_',' ',$sortBy)) : 'Unsorted' ?></strong>
  </article>
</div>

<div class="panel">
  <div class="panel-body">
    <form action="" method="get" class="toolbar">
      <div class="field grow">
        <label for="q">Search</label>
        <input type="text" id="q" name="q" placeholder="Search by name, department, position…" value="<?= htmlspecialchars($q) ?>">
      </div>
      <div class="field">
        <label for="sort_by">Sort by</label>
        <select name="sort_by" id="sort_by">
          <option value=""                  <?= $rawSort === ''                ? 'selected' : '' ?>>No sorting</option>
          <option value="name"              <?= $rawSort === 'name'            ? 'selected' : '' ?>>Name</option>
          <option value="department_name"   <?= $rawSort === 'department_name' ? 'selected' : '' ?>>Department</option>
          <option value="position_title"    <?= $rawSort === 'position_title'  ? 'selected' : '' ?>>Position</option>
          <option value="date_hired"        <?= $rawSort === 'date_hired'      ? 'selected' : '' ?>>Date Hired</option>
          <option value="employment_status" <?= $rawSort === 'employment_status' ? 'selected' : '' ?>>Status</option>
          <option value="employee_id"       <?= $rawSort === 'employee_id'     ? 'selected' : '' ?>>Employee ID</option>
        </select>
      </div>
      <div class="field">
        <label for="dir">Direction</label>
        <select name="dir" id="dir">
          <option value="asc"  <?= $dir === 'asc'  ? 'selected' : '' ?>>Ascending</option>
          <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
        </select>
      </div>
      <div class="tb-actions">
        <button class="primary-button" type="submit">Apply</button>
        <a class="button" href="/hr/employees.php">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="panel table-card">
  <div class="panel-head">
    <h2>Employee Directory</h2>
    <span class="muted"><?= number_format($totalShown) ?> record<?= $totalShown === 1 ? '' : 's' ?></span>
  </div>
  <table>
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
            <div class="empty-state">
              <span class="es-ico">♟</span>
              No employees found<?= $q !== '' ? ' for “' . htmlspecialchars($q) . '”' : '' ?>.
            </div>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($employees as $employee): ?>
          <?php
            $fullName = htmlspecialchars(
              $employee['first_name'] . ' ' .
              ($employee['middle_name'] ? $employee['middle_name'] . ' ' : '') .
              $employee['last_name'] .
              ($employee['suffix'] ? ' ' . $employee['suffix'] : '')
            );
            $isActive = strcasecmp((string)($employee['employment_status'] ?? ''), 'Active') === 0;
            $pillClass = $isActive ? 'pill-active' : (($employee['employment_status'] ?? '') !== '' ? 'pill-inactive' : 'pill-neutral');
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($employee['employee_id']) ?></strong></td>
            <td><?= $fullName ?></td>
            <td><?= htmlspecialchars($employee['department_name'] ?? 'N/A') ?></td>
            <td><?= htmlspecialchars($employee['position_title'] ?? 'N/A') ?></td>
            <td><?= htmlspecialchars($employee['date_hired']) ?></td>
            <td><span class="pill <?= $pillClass ?>"><?= htmlspecialchars($employee['employment_status'] ?: 'Unknown') ?></span></td>
            <td>
              <span class="row-actions">
                <a href="view_employee.php?id=<?= $employee['id'] ?>">View</a>
                <span class="sep">|</span>
                <a href="edit_employee.php?id=<?= $employee['id'] ?>">Edit</a>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
