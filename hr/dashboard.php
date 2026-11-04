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

// Reports are embedded directly in the dashboard (no redirect to report.php).
// Disable the CSV export branch here so it can't exit() the dashboard.
$reportDataReady = false;
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $REPORT_ALLOW_EXPORT = false;
        require_once __DIR__ . '/_report_data.php';
        $reportDataReady = true;
    }
} catch (Throwable $e) { /* leave reports hidden if data layer is unavailable */ }

require __DIR__ . '/../templates/hrTemplates/header.php';
function dashboardMetric($value): string { return $value === null ? '—' : number_format($value); }
?>
<?php
$activePct = ($employeeCount && $activeEmployeeCount !== null && $employeeCount > 0)
    ? round(($activeEmployeeCount / $employeeCount) * 100)
    : null;
?>
<?php
  $hour = (int) date('G');
  $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
  // Friendly name from the session if available, else a generic label.
  $hrName = trim((string)($_SESSION['username'] ?? ''));
  if ($hrName === '') { $hrName = 'HR'; }
  $hrName = ucfirst($hrName);
?>
<section class="hero-banner">
  <div class="hero-content">
    <p class="eyebrow">OVERVIEW</p>
    <h1 class="welcome-hero">
      <span class="w-part"><?= htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') ?>,</span>
      <span class="w-part welcome-name"><?= htmlspecialchars($hrName, ENT_QUOTES, 'UTF-8') ?></span>
      <span class="w-part welcome-wave" aria-hidden="true">👋</span>
    </h1>
    <p class="muted welcome-sub">Welcome back to your HR workspace. Manage your people, organization, and leave workflows from one calm, connected place.</p>
    <div class="hero-actions">
      <a class="primary-button" href="/hr/add_employee.php">＋ Add Employee</a>
      <a class="button" href="#reports-section">View reports</a>
    </div>
  </div>
  <div class="hero-orb" aria-hidden="true"></div>
</section>

<div class="metric-strip">
  <article class="metric-card metric-teal">
    <div class="metric-top"><span class="metric-ico">♟</span><span class="metric-chip">Directory</span></div>
    <span class="metric-title">Total Employees</span>
    <strong class="metric-number teal"><?= dashboardMetric($employeeCount) ?></strong>
    <span class="metric-description">Employee records in the system</span>
  </article>
  <article class="metric-card metric-green">
    <div class="metric-top"><span class="metric-ico">✓</span><?php if ($activePct !== null): ?><span class="metric-chip up"><?= $activePct ?>% active</span><?php endif; ?></div>
    <span class="metric-title">Active Employees</span>
    <strong class="metric-number green"><?= dashboardMetric($activeEmployeeCount) ?></strong>
    <div class="metric-bar"><span style="width:<?= $activePct !== null ? (int)$activePct : 0 ?>%"></span></div>
  </article>
  <article class="metric-card metric-violet">
    <div class="metric-top"><span class="metric-ico">▦</span><span class="metric-chip">Org</span></div>
    <span class="metric-title">Departments</span>
    <strong class="metric-number purple"><?= dashboardMetric($departmentCount) ?></strong>
    <span class="metric-description">Organization departments</span>
  </article>
  <article class="metric-card metric-blue">
    <div class="metric-top"><span class="metric-ico">▣</span><span class="metric-chip">Roles</span></div>
    <span class="metric-title">Positions</span>
    <strong class="metric-number blue"><?= dashboardMetric($positionCount) ?></strong>
    <span class="metric-description">Defined position records</span>
  </article>
</div>

<div class="dashboard-grid">
 <article class="card quick-actions-card">
   <div class="card-heading"><div><p class="eyebrow">WORKSPACE</p><h2>Quick actions</h2></div></div>
   <div class="shortcut-grid">
    <a class="shortcut sc-teal" href="/hr/employees.php"><span class="shortcut-icon">♟</span><strong>Employee directory</strong><span>Search, sort, view, and update employee records.</span><b>Open directory →</b></a>
    <a class="shortcut sc-violet" href="/hr/department.php"><span class="shortcut-icon">▦</span><strong>Departments</strong><span>Create and manage organizational departments.</span><b>Manage departments →</b></a>
    <a class="shortcut sc-blue" href="/hr/position.php"><span class="shortcut-icon">▣</span><strong>Positions</strong><span>Maintain position records and descriptions.</span><b>Manage positions →</b></a>
    <a class="shortcut sc-green" href="/hr/leave_management.php"><span class="shortcut-icon">◷</span><strong>Leave management</strong><span>Manage leave types, balances, and requests.</span><b>Review leave →</b></a>
    <a class="shortcut sc-moon" href="/hr/report.php"><span class="shortcut-icon">▥</span><strong>Reports &amp; audits</strong><span>Open the HR reports and audit logs.</span><b>View reports →</b></a>
    <a class="shortcut sc-teal" href="/hr/add_employee.php"><span class="shortcut-icon">＋</span><strong>Onboard employee</strong><span>Add a new person to the organization.</span><b>Add employee →</b></a>
   </div>
 </article>

 <aside class="side-col">
   <article class="card org-card">
     <p class="eyebrow">ORGANIZATION</p>
     <h2>At a glance</h2>
     <ul class="stat-list">
       <li><span class="dot teal"></span><span class="sl-label">Active workforce</span><span class="sl-value"><?= dashboardMetric($activeEmployeeCount) ?></span></li>
       <li><span class="dot violet"></span><span class="sl-label">Departments</span><span class="sl-value"><?= dashboardMetric($departmentCount) ?></span></li>
       <li><span class="dot blue"></span><span class="sl-label">Positions</span><span class="sl-value"><?= dashboardMetric($positionCount) ?></span></li>
       <li><span class="dot moon"></span><span class="sl-label">Total records</span><span class="sl-value"><?= dashboardMetric($employeeCount) ?></span></li>
     </ul>
   </article>

   <article class="card activity-card">
     <div class="card-heading"><div><p class="eyebrow">SYSTEM</p><h2>Workspace status</h2></div></div>
     <div class="feed">
       <div class="feed-item"><span class="status-dot"></span><div><strong>Navigation connected</strong><small>All HR pages linked through the sidebar.</small></div></div>
       <div class="feed-item"><span class="status-dot"></span><div><strong>Data layer ready</strong><small>Metrics read live when the database is available.</small></div></div>
       <div class="feed-item"><span class="status-dot"></span><div><strong>Forms &amp; actions retained</strong><small>Existing HR workflows are untouched.</small></div></div>
     </div>
   </article>
 </aside>
</div>

<?php if ($reportDataReady): ?>
<section id="reports-section" class="dashboard-reports">
  <div class="card-heading" style="margin-top:8px">
    <div>
      <p class="eyebrow">ANALYTICS</p>
      <h2>Reports &amp; Audits</h2>
    </div>
    <a class="button" href="/hr/report.php">Open full reports →</a>
  </div>
  <?php
    // Embedded variant: renders the same stats + headcount table inline.
    $reportEmbedded = true;
    require __DIR__ . '/_report_section.php';
  ?>
</section>
<?php endif; ?>
