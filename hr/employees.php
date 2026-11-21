<?php

require_once __DIR__ . '/../authentication/guard.php';
require_access('employee_records', 'view', 'all');

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/Employee.php';
require_once __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../classes/DSA/Searching.php';
require __DIR__ . '/../classes/DSA/Sorting.php';
require __DIR__ . '/../classes/DSA/BinarySearchTree.php';
require __DIR__ . '/../classes/DSA/HashTable.php';
$employeeModel = new Employee($pdo);
$employees     = $employeeModel->getAll();

// ── DSA: Hash Table — department and position cache ──────────
$departmentCache = new HashTable(32);
$positionCache   = new HashTable(32);

try {
  $deptRows = $pdo->query("SELECT id, name FROM departments")
    ->fetchAll(PDO::FETCH_ASSOC);
  foreach ($deptRows as $d) {
    $departmentCache->put((string)$d['id'], $d['name']);
  }

  $posRows = $pdo->query("SELECT id, title FROM positions")
    ->fetchAll(PDO::FETCH_ASSOC);
  foreach ($posRows as $p) {
    $positionCache->put((string)$p['id'], $p['title']);
  }
} catch (Throwable $e) {
  error_log('Hash table cache build failed: ' . $e->getMessage());
}

$q          = trim($_GET['q'] ?? '');
$rawSort    = $_GET['sort_by'] ?? '';
$dir        = ($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
$statusView = strtolower(trim($_GET['status_view'] ?? 'active'));
if (!in_array($statusView, ['active', 'non_active', 'all'], true)) {
  $statusView = 'active';
}

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

foreach ($employees as &$emp) {
  $emp['name'] = emp_full_name($emp);
}
unset($emp);

// Filter by Active vs Non-Active employee status
if ($statusView === 'active') {
  $employees = array_values(array_filter($employees, static function (array $e): bool {
    return strcasecmp(trim((string)($e['employment_status'] ?? '')), 'Active') === 0;
  }));
} elseif ($statusView === 'non_active') {
  $employees = array_values(array_filter($employees, static function (array $e): bool {
    return strcasecmp(trim((string)($e['employment_status'] ?? '')), 'Active') !== 0;
  }));
}

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
    $found = $employeeBST->search(strtoupper($q));
    if ($found) {
      $found['name'] = emp_full_name($found);
      $employees = [$found];
    } else {
      $employees = [];
    }
  } else {
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

if ($sortBy !== '') {
  $employees = Sorting::bubbleSort($employees, $sortBy, $dir);
} else {
  $employees = Sorting::bubbleSort($employees, 'name', 'asc');
}

require __DIR__ . '/../templates/hrTemplates/header.php';

$totalShown  = count($employees);
$activeShown = 0;
foreach ($employees as $__e) {
  if (strcasecmp((string)($__e['employment_status'] ?? ''), 'Active') === 0) {
    $activeShown++;
  }
}
$inactiveShown = $totalShown - $activeShown;

function build_status_view_url(string $view, string $q, string $rawSort, string $dir): string
{
  $params = ['status_view' => $view];
  if ($q !== '') $params['q'] = $q;
  if ($rawSort !== '') $params['sort_by'] = $rawSort;
  if ($dir !== 'asc') $params['dir'] = $dir;
  return '?' . http_build_query($params);
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<style>
  .status-switcher {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px;
    border-radius: 10px;
    background: rgba(15, 23, 42, 0.75);
    border: 1px solid rgba(148, 163, 184, 0.22);
    margin-bottom: 14px;
    flex-wrap: wrap;
  }
  .status-tab-btn {
    padding: 7px 14px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    color: #94a3b8;
    text-decoration: none;
    transition: background 0.15s ease, color 0.15s ease;
  }
  .status-tab-btn:hover {
    color: #f8fafc;
    background: rgba(59, 130, 246, 0.14);
  }
  .status-tab-btn.is-active {
    background: #2563eb;
    color: #ffffff;
  }

  .dt-table-wrap {
    padding: 14px 16px 18px;
    overflow-x: auto;
  }
  .dataTables_wrapper {
    color: #e2e8f0;
    font-size: 13.5px;
  }
  .dataTables_wrapper .dataTables_length,
  .dataTables_wrapper .dataTables_filter {
    margin-bottom: 12px;
    color: #94a3b8;
  }
  .dataTables_wrapper .dataTables_filter input,
  .dataTables_wrapper .dataTables_length select {
    background: rgba(15, 23, 42, 0.75);
    color: #f8fafc;
    border: 1px solid rgba(148, 163, 184, 0.28);
    border-radius: 8px;
    padding: 5px 10px;
    margin-left: 6px;
  }
  .dataTables_wrapper .dataTables_info,
  .dataTables_wrapper .dataTables_paginate {
    margin-top: 12px;
    color: #94a3b8 !important;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button {
    color: #e2e8f0 !important;
    border-radius: 6px !important;
    border: 1px solid transparent !important;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button.current,
  .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
    background: #2563eb !important;
    color: #ffffff !important;
    border-color: rgba(96, 165, 250, 0.5) !important;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    background: rgba(59, 130, 246, 0.18) !important;
    color: #ffffff !important;
  }
  table.dataTable {
    width: 100% !important;
    border-collapse: collapse !important;
  }
  table.dataTable thead th {
    border-bottom: 1px solid rgba(148, 163, 184, 0.22) !important;
    color: #94a3b8;
    text-align: left;
  }
  table.dataTable tbody td {
    border-bottom: 1px solid rgba(148, 163, 184, 0.12) !important;
    vertical-align: middle;
  }
  .export-action-btn {
    background: none;
    border: none;
    padding: 0;
    color: #60a5fa;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
  }
  .export-action-btn:hover {
    text-decoration: underline;
    color: #93c5fd;
  }

  /* Professional Company Document Modal */
  .co-sheet-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(5, 8, 22, 0.84);
    backdrop-filter: blur(4px);
    z-index: 9999;
    overflow-y: auto;
    padding: 24px 16px 40px;
  }
  .co-sheet-backdrop.is-open {
    display: block;
  }
  .co-sheet-container {
    max-width: 820px;
    margin: 0 auto;
  }
  .co-sheet-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    margin-bottom: 14px;
    border-radius: 12px;
    background: #141b33;
    border: 1px solid rgba(148, 163, 184, 0.22);
    color: #e2e8f0;
  }
  .co-sheet-toolbar-left,
  .co-sheet-toolbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .co-status-badge {
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
    background: rgba(99, 102, 241, 0.2);
    color: #c7d2fe;
    border: 1px solid rgba(129, 140, 248, 0.4);
  }
  .co-btn-ghost {
    padding: 7px 12px;
    border-radius: 8px;
    border: 1px solid rgba(148, 163, 184, 0.28);
    background: rgba(30, 41, 59, 0.75);
    color: #f8fafc;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
  }
  .co-btn-print {
    padding: 7px 14px;
    border-radius: 8px;
    border: none;
    background: #2563eb;
    color: #ffffff;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
  }
  .co-btn-print:hover {
    background: #1d4ed8;
  }

  .co-paper {
    background: #ffffff;
    color: #1e293b;
    border-radius: 10px;
    padding: 32px 36px;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.55);
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  }
  .co-paper-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    padding-bottom: 18px;
    border-bottom: 1.5px solid #cbd5e1;
  }
  .co-brand-name {
    margin: 0;
    font-size: 20px;
    font-weight: 800;
    color: #0f172a;
  }
  .co-brand-sub {
    margin: 2px 0 0;
    font-size: 12.5px;
    color: #64748b;
  }
  .co-doc-meta {
    text-align: right;
    font-size: 12px;
    line-height: 1.5;
    color: #475569;
  }
  .co-doc-title {
    margin: 0 0 2px;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.04em;
    color: #0f172a;
    text-transform: uppercase;
  }

  .co-info-box {
    margin-top: 18px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 14px 18px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px 24px;
    background: #f8fafc;
  }
  .co-field-cell .co-lbl {
    display: block;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #64748b;
  }
  .co-field-cell .co-val {
    display: block;
    margin-top: 2px;
    font-size: 13px;
    font-weight: 700;
    color: #0f172a;
    word-break: break-word;
  }

  .co-section-bar {
    margin-top: 18px;
    padding: 6px 12px;
    background: #f1f5f9;
    border-radius: 4px;
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #334155;
  }
  .co-detail-columns {
    margin-top: 12px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
  }
  @media (max-width: 680px) {
    .co-paper { padding: 20px 18px; }
    .co-paper-header { flex-direction: column; }
    .co-doc-meta { text-align: left; }
    .co-info-box, .co-detail-columns { grid-template-columns: 1fr; }
  }
  .co-subcard {
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    overflow: hidden;
  }
  .co-subcard-head {
    background: #f8fafc;
    padding: 8px 12px;
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #334155;
    border-bottom: 1px solid #e2e8f0;
  }
  .co-kv-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
  }
  .co-kv-table tr {
    border-bottom: 1px solid #f1f5f9;
  }
  .co-kv-table tr:last-child {
    border-bottom: none;
  }
  .co-kv-table td {
    padding: 7px 12px;
    vertical-align: top;
  }
  .co-kv-table td.k {
    color: #64748b;
    width: 42%;
  }
  .co-kv-table td.v {
    color: #0f172a;
    font-weight: 600;
    text-align: right;
    word-break: break-word;
  }

  .co-summary-banner {
    margin-top: 16px;
    background: #182238;
    color: #ffffff;
    border-radius: 8px;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
  }
  .co-summary-banner small {
    display: block;
    color: #cbd5e1;
    font-size: 11px;
  }
  .co-summary-banner strong {
    font-size: 14px;
    letter-spacing: 0.04em;
  }
  .co-summary-main {
    font-size: 18px;
    font-weight: 800;
    color: #ffffff;
  }

  .co-remarks-box {
    margin-top: 12px;
    padding: 9px 12px;
    border-radius: 6px;
    background: #fef9c3;
    border: 1px solid #fde047;
    color: #713f12;
    font-size: 11.5px;
  }

  .co-sig-grid {
    margin-top: 34px;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 24px;
    text-align: center;
  }
  @media (max-width: 640px) {
    .co-sig-grid { grid-template-columns: 1fr; gap: 20px; }
  }
  .co-sig-line {
    border-top: 1px solid #64748b;
    padding-top: 6px;
    font-size: 11px;
    font-weight: 600;
    color: #334155;
  }
  .co-sig-sub {
    font-size: 10.5px;
    color: #64748b;
    margin-top: 2px;
  }
  .co-paper-foot {
    margin-top: 22px;
    text-align: center;
    font-size: 10.5px;
    color: #94a3b8;
  }
</style>

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

<!-- Choose Active Employee or Non Active Employee -->
<div class="status-switcher" role="tablist" aria-label="Employee Status Filter">
  <a
    href="<?= htmlspecialchars(build_status_view_url('active', $q, $rawSort, $dir)) ?>"
    class="status-tab-btn <?= $statusView === 'active' ? 'is-active' : '' ?>"
  >Active Employee</a>
  <a
    href="<?= htmlspecialchars(build_status_view_url('non_active', $q, $rawSort, $dir)) ?>"
    class="status-tab-btn <?= $statusView === 'non_active' ? 'is-active' : '' ?>"
  >Non Active Employee</a>
  <a
    href="<?= htmlspecialchars(build_status_view_url('all', $q, $rawSort, $dir)) ?>"
    class="status-tab-btn <?= $statusView === 'all' ? 'is-active' : '' ?>"
  >All Employees</a>
</div>

<div class="metric-strip">
  <article class="metric-card metric-teal">
    <div class="metric-top"><span class="metric-ico">♟</span><span class="metric-chip"><?= $q !== '' ? 'Filtered' : strtoupper(str_replace('_', ' ', $statusView)) ?></span></div>
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
    <span class="metric-title">Non Active</span>
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
      <input type="hidden" name="status_view" value="<?= htmlspecialchars($statusView) ?>">
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
        <a class="button" href="/hr/employees.php?status_view=<?= htmlspecialchars($statusView) ?>">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="panel table-card">
  <div class="panel-head">
    <h2>
      <?php if ($statusView === 'non_active'): ?>
        Non Active Employee Directory
      <?php elseif ($statusView === 'all'): ?>
        All Employees Directory
      <?php else: ?>
        Active Employee Directory
      <?php endif; ?>
    </h2>
    <span class="muted"><?= number_format($totalShown) ?> record<?= $totalShown === 1 ? '' : 's' ?></span>
  </div>
  <div class="dt-table-wrap">
    <table id="employeeDirectoryTable" class="display">
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
        <?php if (!empty($employees)): ?>
          <?php foreach ($employees as $employee): ?>
            <?php
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
              trim((string)($employee['employment_status'] ?? '')),
              'Active'
            ) === 0;

            $pillClass = $isActive
              ? 'pill-active'
              : (($employee['employment_status'] ?? '') !== ''
                ? 'pill-inactive'
                : 'pill-neutral');

            $exportPayload = $employee;
            $exportPayload['name'] = emp_full_name($employee);
            $exportPayload['department_name'] = $deptName;
            $exportPayload['position_title'] = $posTitle;
            $exportJson = htmlspecialchars(json_encode($exportPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            ?>
            <tr>
              <td><strong><?= htmlspecialchars($employee['employee_id']) ?></strong></td>
              <td><?= $fullName ?></td>
              <td><?= htmlspecialchars($deptName, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($posTitle, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($employee['date_hired']) ?></td>
              <td><span class="pill <?= $pillClass ?>"><?= htmlspecialchars($employee['employment_status'] ?: 'Non Active') ?></span></td>
              <td>
                <span class="row-actions">
                  <a href="view_employee.php?id=<?= $employee['id'] ?>">View</a>
                  <?php if ($isActive): ?>
                    <span class="sep">|</span>
                    <a href="edit_employee.php?id=<?= $employee['id'] ?>">Edit</a>
                  <?php endif; ?>
                  <span class="sep">|</span>
                  <button type="button" class="export-action-btn btn-export-emp" data-employee="<?= $exportJson ?>">Export</button>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Professional Company Employee Information Sheet Modal -->
<div class="co-sheet-backdrop" id="coEmployeeSheetModal" role="dialog" aria-modal="true" aria-labelledby="coSheetDocTitle">
  <div class="co-sheet-container">
    <div class="co-sheet-toolbar">
      <div class="co-sheet-toolbar-left">
        <button type="button" class="co-btn-ghost" id="coCloseSheetBtn">← Back to Directory</button>
        <span class="co-status-badge" id="coSheetStatusBadge">Employee Record</span>
      </div>
      <div class="co-sheet-toolbar-right">
        <button type="button" class="co-btn-ghost" id="coDownloadCsvBtn">⬇ Export CSV</button>
        <button type="button" class="co-btn-print" id="coPrintPdfBtn">🖨 Print / Save PDF</button>
      </div>
    </div>

    <div class="co-paper" id="coPrintablePaper">
      <div class="co-paper-header">
        <div>
          <h2 class="co-brand-name">HRIS Company</h2>
          <p class="co-brand-sub">Human Resource Information System — Official Employee Record</p>
        </div>
        <div class="co-doc-meta">
          <div class="co-doc-title" id="coSheetDocTitle">EMPLOYEE PROFILE SHEET</div>
          <div id="coDocRefCode">Employee ID: —</div>
          <div id="coDocIssuedDate">Date Hired: —</div>
        </div>
      </div>

      <div class="co-info-box">
        <div class="co-field-cell">
          <span class="co-lbl">Employee Name</span>
          <span class="co-val" id="coValName">—</span>
        </div>
        <div class="co-field-cell">
          <span class="co-lbl">Employee ID</span>
          <span class="co-val" id="coValEmpId">—</span>
        </div>
        <div class="co-field-cell">
          <span class="co-lbl">Department</span>
          <span class="co-val" id="coValDept">—</span>
        </div>
        <div class="co-field-cell">
          <span class="co-lbl">Position</span>
          <span class="co-val" id="coValPos">—</span>
        </div>
        <div class="co-field-cell">
          <span class="co-lbl">Employment Status</span>
          <span class="co-val" id="coValStatus">—</span>
        </div>
        <div class="co-field-cell">
          <span class="co-lbl">Date Hired</span>
          <span class="co-val" id="coValDateHired">—</span>
        </div>
      </div>

      <div class="co-section-bar">COMPLETE EMPLOYEE INFORMATION</div>

      <div class="co-detail-columns">
        <div class="co-subcard">
          <div class="co-subcard-head">Personal &amp; Name Details</div>
          <table class="co-kv-table" id="coPersonalTable"></table>
        </div>
        <div class="co-subcard">
          <div class="co-subcard-head">Employment, Contact &amp; Record Details</div>
          <table class="co-kv-table" id="coEmploymentTable"></table>
        </div>
      </div>

      <div class="co-summary-banner">
        <div>
          <strong>EMPLOYEE RECORD SUMMARY</strong>
          <small id="coBannerSub">Verified HRIS Personnel File</small>
        </div>
        <div class="co-summary-main" id="coBannerMain">—</div>
      </div>

      <div class="co-remarks-box" id="coRemarksBox">
        Official HRIS employee record export containing complete profile details from name through date hired.
      </div>

      <div class="co-sig-grid">
        <div>
          <div class="co-sig-line" id="coSigEmployee">Employee Name</div>
          <div class="co-sig-sub">Employee Signature / Date</div>
        </div>
        <div>
          <div class="co-sig-line">Human Resources</div>
          <div class="co-sig-sub">HR Officer / Date</div>
        </div>
        <div>
          <div class="co-sig-line">Management</div>
          <div class="co-sig-sub">Authorized By / Date</div>
        </div>
      </div>

      <div class="co-paper-foot" id="coPaperFooter">
        Generated by HRIS • Official Employee Information Sheet
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
  $(function () {
    $('#employeeDirectoryTable').DataTable({
      pageLength: 10,
      lengthMenu: [10, 25, 50, 100],
      order: [],
      columnDefs: [
        { orderable: false, targets: -1 }
      ],
      language: {
        emptyTable: 'No employees found<?= $q !== '' ? ' for “' . addslashes(htmlspecialchars($q)) . '”' : '' ?>.',
        search: 'Filter table:',
        lengthMenu: 'Show _MENU_ entries'
      }
    });

    const modal = document.getElementById('coEmployeeSheetModal');
    let currentRecord = null;
    let currentOrderedEntries = [];

    function formatLabel(key) {
      const customLabels = {
        name: 'Employee Name',
        first_name: 'First Name',
        middle_name: 'Middle Name',
        last_name: 'Last Name',
        suffix: 'Suffix',
        employee_id: 'Employee ID',
        department_name: 'Department',
        position_title: 'Position',
        employment_status: 'Employment Status',
        employment_type: 'Employment Type',
        date_hired: 'Date Hired',
        date_of_birth: 'Date of Birth',
        birth_date: 'Birth Date',
        email: 'Email Address',
        phone: 'Phone Number',
        contact_number: 'Contact Number',
        address: 'Address',
        gender: 'Gender',
        rank: 'Rank'
      };
      if (customLabels[key]) return customLabels[key];
      return String(key)
        .replace(/_/g, ' ')
        .replace(/\b\w/g, c => c.toUpperCase());
    }

    function getOrderedEmployeeEntries(emp) {
      if (!emp || typeof emp !== 'object') return [];

      const preferredOrder = [
        'name',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'employee_id',
        'department_name',
        'position_title',
        'employment_status',
        'employment_type',
        'date_hired',
        'email',
        'phone',
        'contact_number',
        'gender',
        'date_of_birth',
        'birth_date',
        'address',
        'rank'
      ];

      const skipInternalKeys = new Set(['id', 'department_id', 'position_id', 'password', 'password_hash']);
      const entries = [];
      const seen = new Set();

      for (const key of preferredOrder) {
        if (key in emp && !skipInternalKeys.has(key)) {
          const val = emp[key] === null || emp[key] === undefined || String(emp[key]).trim() === ''
            ? '—'
            : (typeof emp[key] === 'object' ? JSON.stringify(emp[key]) : String(emp[key]));
          entries.push([formatLabel(key), val]);
          seen.add(key);
        }
      }

      for (const [key, rawVal] of Object.entries(emp)) {
        if (seen.has(key) || skipInternalKeys.has(key)) continue;
        const val = rawVal === null || rawVal === undefined || String(rawVal).trim() === ''
          ? '—'
          : (typeof rawVal === 'object' ? JSON.stringify(rawVal) : String(rawVal));
        entries.push([formatLabel(key), val]);
      }

      return entries;
    }

    function buildRowsHtml(pairs) {
      if (!pairs.length) {
        return `<tr><td class="k">Details</td><td class="v">—</td></tr>`;
      }
      return pairs.map(([label, val]) => {
        const safeLbl = $('<div>').text(label).html();
        const safeVal = $('<div>').text(val).html();
        return `<tr><td class="k">${safeLbl}</td><td class="v">${safeVal}</td></tr>`;
      }).join('');
    }

    function openCompanySheet(emp) {
      currentRecord = emp || {};
      currentOrderedEntries = getOrderedEmployeeEntries(currentRecord);

      const fullName = currentRecord.name || [currentRecord.first_name, currentRecord.middle_name, currentRecord.last_name, currentRecord.suffix].filter(Boolean).join(' ') || '—';
      const empCode  = currentRecord.employee_id || '—';
      const dept     = currentRecord.department_name || '—';
      const pos      = currentRecord.position_title || '—';
      const status   = currentRecord.employment_status || 'Non Active';
      const hired    = currentRecord.date_hired || '—';
      const nowStr   = new Date().toLocaleString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
      });

      $('#coSheetStatusBadge').text(status);
      $('#coDocRefCode').text(`Employee ID: ${empCode}`);
      $('#coDocIssuedDate').text(`Date Hired: ${hired}`);

      $('#coValName').text(fullName);
      $('#coValEmpId').text(empCode);
      $('#coValDept').text(dept);
      $('#coValPos').text(pos);
      $('#coValStatus').text(status);
      $('#coValDateHired').text(hired);

      const personalLabels = new Set([
        'Employee Name', 'First Name', 'Middle Name', 'Last Name', 'Suffix',
        'Gender', 'Date of Birth', 'Birth Date', 'Civil Status', 'Nationality', 'Address'
      ]);

      const personalPairs = currentOrderedEntries.filter(([lbl]) => personalLabels.has(lbl));
      const employmentPairs = currentOrderedEntries.filter(([lbl]) => !personalLabels.has(lbl));

      $('#coPersonalTable').html(buildRowsHtml(personalPairs));
      $('#coEmploymentTable').html(buildRowsHtml(employmentPairs));

      $('#coBannerSub').text(`${dept} • ${pos} • Hired: ${hired}`);
      $('#coBannerMain').text(empCode);
      $('#coSigEmployee').text(fullName);
      $('#coPaperFooter').text(`Generated: ${nowStr} • Status: ${status}`);

      modal.classList.add('is-open');
    }

    $(document).on('click', '.btn-export-emp', function () {
      const raw = $(this).attr('data-employee');
      try {
        openCompanySheet(JSON.parse(raw));
      } catch (_) {}
    });

    function closeSheet() {
      modal.classList.remove('is-open');
    }

    $('#coCloseSheetBtn').on('click', closeSheet);
    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeSheet();
    });

    $('#coPrintPdfBtn').on('click', function () {
      const paperEl = document.getElementById('coPrintablePaper');
      if (!paperEl) return;

      const printWin = window.open('', '_blank', 'width=900,height=700');
      if (!printWin) {
        window.print();
        return;
      }

      printWin.document.open();
      printWin.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset="utf-8">
          <title>${$('<div>').text($('#coValName').text() + ' - Employee Record').html()}</title>
          <style>
            * { box-sizing: border-box; }
            body {
              margin: 0;
              padding: 24px;
              background: #ffffff;
              color: #1e293b;
              font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .co-paper-header {
              display: flex;
              justify-content: space-between;
              align-items: flex-start;
              gap: 20px;
              padding-bottom: 16px;
              border-bottom: 1.5px solid #cbd5e1;
            }
            .co-brand-name { margin: 0; font-size: 20px; font-weight: 800; color: #0f172a; }
            .co-brand-sub { margin: 3px 0 0; font-size: 12px; color: #64748b; }
            .co-doc-meta { text-align: right; font-size: 12px; line-height: 1.5; color: #475569; }
            .co-doc-title { font-size: 13px; font-weight: 800; color: #0f172a; text-transform: uppercase; }
            .co-info-box {
              margin-top: 16px;
              border: 1px solid #cbd5e1;
              border-radius: 8px;
              padding: 14px 18px;
              display: grid;
              grid-template-columns: repeat(2, 1fr);
              gap: 12px 24px;
              background: #f8fafc;
            }
            .co-field-cell .co-lbl { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; color: #64748b; }
            .co-field-cell .co-val { display: block; margin-top: 2px; font-size: 13px; font-weight: 700; color: #0f172a; }
            .co-section-bar {
              margin-top: 16px;
              padding: 6px 12px;
              background: #f1f5f9;
              border-radius: 4px;
              font-size: 10.5px;
              font-weight: 800;
              text-transform: uppercase;
              color: #334155;
            }
            .co-detail-columns {
              margin-top: 12px;
              display: grid;
              grid-template-columns: repeat(2, 1fr);
              gap: 16px;
            }
            .co-subcard { border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
            .co-subcard-head {
              background: #f8fafc;
              padding: 8px 12px;
              font-size: 10.5px;
              font-weight: 800;
              text-transform: uppercase;
              color: #334155;
              border-bottom: 1px solid #e2e8f0;
            }
            .co-kv-table { width: 100%; border-collapse: collapse; font-size: 12px; }
            .co-kv-table tr { border-bottom: 1px solid #f1f5f9; }
            .co-kv-table tr:last-child { border-bottom: none; }
            .co-kv-table td { padding: 6px 12px; vertical-align: top; }
            .co-kv-table td.k { color: #64748b; width: 42%; }
            .co-kv-table td.v { color: #0f172a; font-weight: 600; text-align: right; }
            .co-summary-banner {
              margin-top: 16px;
              background: #182238;
              color: #ffffff;
              border-radius: 8px;
              padding: 14px 18px;
              display: flex;
              align-items: center;
              justify-content: space-between;
            }
            .co-summary-banner small { display: block; color: #cbd5e1; font-size: 11px; }
            .co-summary-main { font-size: 18px; font-weight: 800; color: #ffffff; }
            .co-remarks-box {
              margin-top: 12px;
              padding: 9px 12px;
              border-radius: 6px;
              background: #fef9c3;
              border: 1px solid #fde047;
              color: #713f12;
              font-size: 11.5px;
            }
            .co-sig-grid {
              margin-top: 32px;
              display: grid;
              grid-template-columns: repeat(3, 1fr);
              gap: 24px;
              text-align: center;
            }
            .co-sig-line { border-top: 1px solid #64748b; padding-top: 6px; font-size: 11px; font-weight: 600; color: #334155; }
            .co-sig-sub { font-size: 10.5px; color: #64748b; margin-top: 2px; }
            .co-paper-foot { margin-top: 20px; text-align: center; font-size: 10.5px; color: #94a3b8; }
            @page { margin: 12mm; }
          </style>
        </head>
        <body>
          ${paperEl.outerHTML}
          <script>
            window.onload = function () {
              setTimeout(function () {
                window.print();
                window.close();
              }, 150);
            };
          <\/script>
        </body>
        </html>
      `);
      printWin.document.close();
    });

    $('#coDownloadCsvBtn').on('click', function () {
      if (!currentRecord) return;
      const rows = [
        ['Field', 'Value'],
        ...currentOrderedEntries
      ];
      const csvBody = rows
        .map(cols => cols.map(cell => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(','))
        .join('\r\n');

      const blob = new Blob(['\uFEFF' + csvBody], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      const code = (currentRecord.employee_id || 'employee').toString().replace(/[^a-zA-Z0-9_-]/g, '_');
      a.href = url;
      a.download = `${code}_employee_information.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    });
  });
</script>
