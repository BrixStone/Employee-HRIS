<?php
$title = 'Dashboard';
$employeeCount = $activeEmployeeCount = $departmentCount = $positionCount = null;
// Dashboard-only read queries. Fail gracefully if the project's database file/schema
// is not present in the supplied archive; this does not change any HR workflow.
try {
    require_once __DIR__ . '/../config/database.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        try { $employeeCount = (int)$pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn(); } catch (Throwable $e) {}
        try { $activeEmployeeCount = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status = 'Active'")->fetchColumn(); } catch (Throwable $e) {}
        try { $departmentCount = (int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn(); } catch (Throwable $e) {}
        try { $positionCount = (int)$pdo->query('SELECT COUNT(*) FROM positions')->fetchColumn(); } catch (Throwable $e) {}
    }
} catch (Throwable $e) { /* Supporting config is part of the original application, not this upload. */ }
require __DIR__ . '/../templates/hrTemplates/header.php';
function dashboardMetric($value): string { return $value === null ? '—' : number_format($value); }
?>
<div class="welcome-row"><div><p class="eyebrow">OVERVIEW</p><h1>Welcome to your HR dashboard</h1><p class="muted">Manage your people, organization, and leave workflows from one place.</p></div><a class="primary-button" href="/hr/add_employee.php">＋ Add Employee</a></div>
<div class="metric-strip">
  <article class="metric-card"><span class="metric-title">Total Employees</span><strong class="metric-number blue"><?= dashboardMetric($employeeCount) ?></strong><span class="metric-description">Employee records in the system</span></article>
  <article class="metric-card"><span class="metric-title">Active Employees</span><strong class="metric-number green"><?= dashboardMetric($activeEmployeeCount) ?></strong><span class="metric-description">Currently marked active</span></article>
  <article class="metric-card"><span class="metric-title">Departments</span><strong class="metric-number purple"><?= dashboardMetric($departmentCount) ?></strong><span class="metric-description">Organization departments</span></article>
  <article class="metric-card"><span class="metric-title">Positions</span><strong class="metric-number blue"><?= dashboardMetric($positionCount) ?></strong><span class="metric-description">Defined position records</span></article>
</div>
<div class="dashboard-grid">
 <article class="card quick-actions-card"><div class="card-heading"><div><p class="eyebrow">WORKSPACE</p><h2>Quick actions</h2></div></div><div class="shortcut-grid">
  <a class="shortcut" href="/hr/employees.php"><span class="shortcut-icon">♙</span><strong>Employee directory</strong><span>Search, sort, view, and update employee records.</span><b>Open directory →</b></a>
  <a class="shortcut" href="/hr/department.php"><span class="shortcut-icon">▤</span><strong>Departments</strong><span>Create and manage organizational departments.</span><b>Manage departments →</b></a>
  <a class="shortcut" href="/hr/position.php"><span class="shortcut-icon">▣</span><strong>Positions</strong><span>Maintain position records and descriptions.</span><b>Manage positions →</b></a>
  <a class="shortcut" href="/hr/leave_management.php"><span class="shortcut-icon">◷</span><strong>Leave management</strong><span>Manage leave types, balances, and requests.</span><b>Review leave →</b></a>
  <a class="shortcut" href="/hr/report.php"><span class="shortcut-icon">▥</span><strong>Reports &amp; audits</strong><span>Open the HR reports page.</span><b>View reports →</b></a>
 </div></article>
 <aside class="card status-card"><p class="eyebrow">SYSTEM SUMMARY</p><h2>Your HR workspace</h2><p class="muted">The dashboard metrics are read from the HR database when the expected database connection and tables are available.</p><div class="status-line"><span class="status-dot"></span><span>Navigation connected to existing HR pages</span></div><div class="status-line"><span class="status-dot"></span><span>Existing forms and actions retained</span></div></aside>
</div>
