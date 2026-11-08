<?php
// Shared HRIS-inspired shell. This file only renders navigation and styles;
// the pages that include it keep their own request handling and database logic.
//
// Used by the HR pages, the admin pages and the manager pages. The sidebar is
// built from the permission matrix (classes/Rbac.php), so each role only sees
// the links it is allowed to open.
require_once __DIR__ . '/../../classes/Rbac.php';

$currentPage = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php', '.php');
$navRole = Rbac::normalizeRole($_SESSION['role'] ?? '');
$navPdo  = (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;

/** Does the current role hold $ability on $module with exactly this scope? */
$navCan = static function (string $module, string $ability, string $scope) use ($navPdo, $navRole): bool {
    return $navPdo !== null
        && Rbac::can($navPdo, $navRole, $module, $ability)
        && Rbac::scope($navPdo, $navRole, $module) === $scope;
};

$isHrSide = in_array($navRole, ['admin', 'hr'], true);
$isManager = $navRole === 'manager';

$pageTitles = [
 'dashboard' => $isManager ? 'Manager Dashboard' : 'HR Dashboard', 'employees' => 'Employee Management',
 'add_employee' => 'Add Employee', 'edit_employee' => 'Edit Employee',
 'view_employee' => 'Employee Profile', 'department' => 'Department Management',
 'position' => 'Position Management', 'leave_management' => 'Leave Management',
 'report' => 'Reports & Audits',
 'payroll' => 'Payroll Management', 'payslip_view' => 'Payslip',
 'users' => 'User Management', 'create_user' => 'Create User', 'permissions' => 'System Settings',
 'team' => 'My Team', 'leave_requests' => 'Team Leave Requests', 'attendance' => 'Team Attendance'
];
$pageTitle = $pageTitles[$currentPage] ?? 'Human Resources';
$cssPage = $cssPageOverride ?? preg_replace('/[^a-z0-9_-]/', '', $currentPage);

// [link, label, icon, pages that highlight it, visible to this role?]
$navItems = [
 ['/hr/dashboard.php', 'Dashboard', '▦', ['dashboard'], $isHrSide && $navCan('dashboard', 'view', 'all')],
 ['/hr/employees.php', 'Employees', '♙', ['employees','add_employee','edit_employee','view_employee','employee_deactivate'], $isHrSide && $navCan('employee_records', 'view', 'all')],
 ['/hr/leave_management.php', 'Leave Management', '◷', ['leave_management'], $isHrSide && $navCan('leave_requests', 'edit', 'all')],
 ['/hr/department.php', 'Departments', '▤', ['department'], $isHrSide && $navCan('employee_records', 'edit', 'all')],
 ['/hr/position.php', 'Positions', '▣', ['position'], $isHrSide && $navCan('employee_records', 'edit', 'all')],
 ['/hr/payroll.php', 'Payroll', '₱', ['payroll','payslip_view'], $isHrSide && $navCan('payslips', 'edit', 'all')],
 ['/hr/report.php', 'Reports &amp; Audits', '▥', ['report'], $isHrSide && $navCan('employee_records', 'view', 'all')],
 ['/admin/users.php', 'User Management', '☺', ['users','create_user'], $isHrSide && $navCan('user_management', 'view', 'all')],
 ['/admin/permissions.php', 'System Settings', '⚙', ['permissions'], $isHrSide && $navCan('system_settings', 'view', 'all')],
 // Manager portal: their direct reports only
 ['/manager/dashboard.php', 'Dashboard', '▦', ['dashboard'], $isManager && $navCan('dashboard', 'view', 'all')],
 ['/manager/team.php', 'My Team', '♙', ['team'], $isManager && $navCan('employee_records', 'view', 'team')],
 ['/manager/leave_requests.php', 'Team Leave', '◷', ['leave_requests'], $isManager && $navCan('leave_requests', 'view', 'team')],
 ['/manager/attendance.php', 'Team Attendance', '▥', ['attendance'], $isManager && $navCan('attendance', 'view', 'team')],
];

$roleLabels = [
 'admin'   => ['AD', 'Administrator', 'System Admin', 'ADMINISTRATION', 'Admin Workspace', 'Admin Control'],
 'manager' => ['MG', 'Manager', 'Team Management', 'MANAGER PORTAL', 'Manager Workspace', 'Manager Portal'],
];
[$navInitials, $navName, $navSub, $navEyebrow, $navStatus, $navTagline] = $roleLabels[$navRole]
    ?? ['HR', 'HR Administration', 'Human Resources', 'HUMAN RESOURCES', 'HR Workspace', 'Admin Control'];
$navHome = function_exists('role_home') ? role_home($navRole) : '/hr/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | HRIS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/hris-base.css">
<link rel="stylesheet" href="/assets/css/welcome.css">
<link rel="stylesheet" href="/assets/css/pages/<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>.css">
<script src="/asset/js/page-transition.js" defer></script>
</head>
<body class="hris-app page-<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>">
<aside class="sidebar">
  <a class="logo-group" href="<?= htmlspecialchars($navHome, ENT_QUOTES, 'UTF-8') ?>" aria-label="HRIS home">
    <span class="logo-mark">H</span><span class="logo-text"><strong>HRIS</strong><small><?= htmlspecialchars($navTagline, ENT_QUOTES, 'UTF-8') ?></small></span>
  </a>
  <nav class="nav-list" aria-label="Main navigation">
<?php foreach ($navItems as [$href, $label, $icon, $pages, $visible]): ?>
<?php if ($visible): ?>
    <a class="nav-item <?= in_array($currentPage, $pages, true) ? 'active' : '' ?>" href="<?= $href ?>"><span class="nav-icon"><?= $icon ?></span><span><?= $label ?></span></a>
<?php endif; ?>
<?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <div class="footer-line"></div>
    <div class="admin-profile"><span class="avatar"><?= htmlspecialchars($navInitials, ENT_QUOTES, 'UTF-8') ?></span><span class="admin-info"><strong><?= htmlspecialchars($navName, ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars($navSub, ENT_QUOTES, 'UTF-8') ?></span></span></div>
    <a class="logout-btn" href="/authentication/logout.php">
      <svg class="logout-ico" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <span>Log Out</span>
    </a>
  </div>
</aside>
<main class="main">
  <header class="topbar">
    <div class="topbar-title"><span class="eyebrow"><?= htmlspecialchars($navEyebrow, ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div class="topbar-actions"><span class="system-status"><span class="status-dot"></span> <?= htmlspecialchars($navStatus, ENT_QUOTES, 'UTF-8') ?></span></div>
  </header>
  <section class="content">
