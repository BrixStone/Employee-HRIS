<?php

require_once __DIR__ . '/../authentication/guard.php';
require_access('employee_records', 'view', 'all');

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/Employee.php';
require __DIR__ . '/../classes/DSA/Searching.php';
require __DIR__ . '/../classes/DSA/Sorting.php';
require __DIR__ . '/../classes/DSA/BinarySearchTree.php';
require __DIR__ . '/../classes/DSA/HashTable.php';
$employeeModel = new Employee($pdo);
$employees     = $employeeModel->getAll();

// ── DSA: Hash Table — department and position cache ──────────
// Hash department_id → department name  O(1) lookup
// Hash position_id   → position title   O(1) lookup
// Avoids repeated DB queries or array scans per employee row.

$departmentCache = new HashTable(32);
$positionCache   = new HashTable(32);

try {
  // Load all departments into hash table once
  $deptRows = $pdo->query("SELECT id, name FROM departments")
    ->fetchAll(PDO::FETCH_ASSOC);
  foreach ($deptRows as $d) {
    $departmentCache->put((string)$d['id'], $d['name']);
  }

  // Load all positions into hash table once
  $posRows = $pdo->query("SELECT id, title FROM positions")
    ->fetchAll(PDO::FETCH_ASSOC);
  foreach ($posRows as $p) {
    $positionCache->put((string)$p['id'], $p['title']);
  }
} catch (Throwable $e) {
  error_log('Hash table cache build failed: ' . $e->getMessage());
}



$q      = trim($_GET['q'] ?? '');
$rawSort = $_GET['sort_by'] ?? '';
$dir    = ($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc';

$sortKeyMap = [
  ''                  => '',
  'name'              => 'name',
  'department_name'   => 'department_name',
  'position_title'    => 'position_title',
  'date_hired'        => 'date_hired',
  'employment_status' => 'employment_status',
  'employee_id'       => 'employee_id',
];

$sortBy  = $sortKeyMap[$rawSort] ?? '';
$allowed = array_values($sortKeyMap);
if (!in_array($sortBy, $allowed, true)) {
  $sortBy = '';
}

function emp_full_name(array $e): string
{
  $m = !empty($e['middle_name']) ? $e['middle_name'] . ' ' : '';
  $s = !empty($e['suffix'])      ? ' ' . $e['suffix']      : '';
  return trim(($e['first_name'] ?? '') . ' ' . $m . ($e['last_name'] ?? '') . $s);
}

// Add computed 'name' key so Sorting and Searching can target it directly
foreach ($employees as &$emp) {
  $emp['name'] = emp_full_name($emp);
}
unset($emp);

$employeeBST = new BinarySearchTree();
foreach ($employees as $emp) {
  $key = strtoupper(trim($emp['employee_id'] ?? ''));
  if ($key !== '') {
    $employeeBST->insert($key, $emp);
  }
}

if ($q !== '') {

  $isIdSearch = preg_match('/^EMP-\d+$/i', $q);

  if ($isIdSearch) {

    // BST Search — O(log n) average
    $found = $employeeBST->search(strtoupper($q));

    if ($found) {
      // Add computed full name to BST result
      $found['name'] = emp_full_name($found);

      $employees = [$found];
    } else {
      $employees = [];
    }
  } else {

    // Linear Search — O(n)
    $employees = Searching::linearSearch($employees, $q, [
      'name',
      'first_name',
      'last_name',
      'email',
      'employee_id',
      'department_name',
      'position_title',
    ]);
  }
}

// ── DSA: Bubble Sort ─────────────────────────────────────────────
// Sorts by the chosen column using Sorting::bubbleSort() — O(n²)
// Falls back to sorting by name ascending when no sort key chosen
if ($sortBy !== '') {
  $employees = Sorting::bubbleSort($employees, $sortBy, $dir);
} else {
  $employees = Sorting::bubbleSort($employees, 'name', 'asc');
}

require __DIR__ . '/../templates/hrTemplates/header.php';

// Lightweight counts for the stat strip (based on the filtered result set)
$totalShown    = count($employees);
$activeShown   = 0;
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
    <strong class="metric-number purple" style="font-size:20px;margin-top:14px"><?= $sortBy !== '' ? ucfirst(str_replace('_', ' ', $sortBy)) : 'Unsorted' ?></strong>
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
          <option value="" <?= $rawSort === ''                ? 'selected' : '' ?>>No sorting</option>
          <option value="name" <?= $rawSort === 'name'            ? 'selected' : '' ?>>Name</option>
          <option value="department_name" <?= $rawSort === 'department_name' ? 'selected' : '' ?>>Department</option>
          <option value="position_title" <?= $rawSort === 'position_title'  ? 'selected' : '' ?>>Position</option>
          <option value="date_hired" <?= $rawSort === 'date_hired'      ? 'selected' : '' ?>>Date Hired</option>
          <option value="employment_status" <?= $rawSort === 'employment_status' ? 'selected' : '' ?>>Status</option>
          <option value="employee_id" <?= $rawSort === 'employee_id'     ? 'selected' : '' ?>>Employee ID</option>
        </select>
      </div>
      <div class="field">
        <label for="dir">Direction</label>
        <select name="dir" id="dir">
          <option value="asc" <?= $dir === 'asc'  ? 'selected' : '' ?>>Ascending</option>
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
          // Hash Table: O(1) average lookup
          $deptName = $departmentCache->get(
            (string)($employee['department_id'] ?? '')
          ) ?? ($employee['department_name'] ?? '—');

          $posTitle = $positionCache->get(
            (string)($employee['position_id'] ?? '')
          ) ?? ($employee['position_title'] ?? '—');

          $fullName = htmlspecialchars(
            emp_full_name($employee),
            ENT_QUOTES,
            'UTF-8'
          );

          $isActive = strcasecmp(
            (string)($employee['employment_status'] ?? ''),
            'Active'
          ) === 0;

          $pillClass = $isActive
            ? 'pill-active'
            : (($employee['employment_status'] ?? '') !== ''
              ? 'pill-inactive'
              : 'pill-neutral');
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($employee['employee_id']) ?></strong></td>

            <td><?= $fullName ?></td>

            <!-- Hash Table: Department lookup -->
            <td><?= htmlspecialchars($deptName, ENT_QUOTES, 'UTF-8') ?></td>

            <!-- Hash Table: Position lookup -->
            <td><?= htmlspecialchars($posTitle, ENT_QUOTES, 'UTF-8') ?></td>

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