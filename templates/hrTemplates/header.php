<?php
// templates/hrTemplates/header.php
require_once __DIR__ . '/../../classes/Rbac.php';

$currentPage = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php', '.php');
$navRole = Rbac::normalizeRole($_SESSION['role'] ?? '');
$navPdo  = (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;

$navCan = static function (string $module, string $ability, string $scope) use ($navPdo, $navRole): bool {
  return $navPdo !== null
    && Rbac::can($navPdo, $navRole, $module, $ability)
    && Rbac::scope($navPdo, $navRole, $module) === $scope;
};

$isHrSide = in_array($navRole, ['admin', 'hr'], true);
$isManager = $navRole === 'manager';

$pageTitles = [
  'dashboard' => $isManager ? 'Manager Dashboard' : 'HR Dashboard',
  'employees' => 'Employee Management',
  'add_employee' => 'Add Employee',
  'edit_employee' => 'Edit Employee',
  'view_employee' => 'Employee Profile',
  'recruitment' => 'Recruitment',
  'onboarding' => 'Onboarding',
  'onboarding_documents' => 'Pre-Employment Documents',
  'department' => 'Department Management',
  'position' => 'Position Management',
  'leave_management' => 'Leave Management',
  'report' => 'Reports & Audits',
  'payroll' => 'Payroll Management',
  'payslip_view' => 'Payslip',
  'users' => 'User Management',
  'create_user' => 'Create User',
  'permissions' => 'System Settings',
  'team' => 'My Team',
  'leave_requests' => 'Team Leave Requests',
  'attendance' => 'Team Attendance'
];
$pageTitle = $pageTitles[$currentPage] ?? 'Human Resources';
$cssPage = $cssPageOverride ?? preg_replace('/[^a-z0-9_-]/', '', $currentPage);

$canViewOnboarding = $isHrSide && $navCan('employee_records', 'view', 'all');
$isOnboardingGroupActive = in_array($currentPage, ['onboarding', 'onboarding_documents'], true);

// [link, label, icon, pages that highlight it, visible to this role?]
$navItems = [
  ['/hr/dashboard.php', 'Dashboard', '▦', ['dashboard'], $isHrSide && $navCan('dashboard', 'view', 'all')],
  ['/hr/employees.php', 'Employees', '♙', ['employees', 'add_employee', 'edit_employee', 'view_employee', 'employee_deactivate'], $isHrSide && $navCan('employee_records', 'view', 'all')],
  ['/hr/recruitment.php', 'Recruitment', '❖', ['recruitment'], $isHrSide && $navCan('employee_records', 'view', 'all')],
  ['/hr/leave_management.php', 'Leave Management', '◷', ['leave_management'], $isHrSide && $navCan('leave_requests', 'edit', 'all')],
  ['/hr/onboarding.php', 'Onboarding', '✦', ['onboarding', 'onboarding_documents'], $canViewOnboarding],
  ['/hr/department.php', 'Departments', '▤', ['department'], $isHrSide && $navCan('employee_records', 'edit', 'all')],
  ['/hr/position.php', 'Positions', '▣', ['position'], $isHrSide && $navCan('employee_records', 'edit', 'all')],
  ['/hr/payroll.php', 'Payroll', '₱', ['payroll', 'payslip_view'], $isHrSide && $navCan('payslips', 'edit', 'all')],
  [
    '/hr/promotion_requests.php',
    'Promotion Approvals',
    '↗',
    ['promotion_requests'],
    $isHrSide && in_array($navRole, ['admin', 'hr'], true)
  ],
  ['/hr/report.php', 'Reports &amp; Audits', '▥', ['report'], $isHrSide && $navCan('employee_records', 'view', 'all')],

  ['/admin/users.php', 'User Management', '☺', ['users', 'create_user'], $isHrSide && $navCan('user_management', 'view', 'all')],
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
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | HRIS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/hris-base.css">
  <link rel="stylesheet" href="/assets/css/welcome.css">
  <link rel="stylesheet" href="/assets/css/pages/<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>.css">
  <script src="/asset/js/page-transition.js" defer></script>
  <style>
    .nav-sub-group {
      display: none;
      flex-direction: column;
      gap: 4px;
      margin: 2px 0 6px 18px;
      padding-left: 12px;
      border-left: 1px solid rgba(148, 163, 184, 0.25);
    }
    .nav-sub-group.is-open {
      display: flex;
    }
    .nav-sub-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 10px;
      font-size: 12.5px;
      font-weight: 600;
      color: #94a3b8;
      text-decoration: none;
      transition: background 0.15s ease, color 0.15s ease;
    }
    .nav-sub-item:hover {
      background: rgba(30, 41, 59, 0.7);
      color: #f8fafc;
    }
    .nav-sub-item.active {
      background: rgba(59, 130, 246, 0.2);
      color: #93c5fd;
      border: 1px solid rgba(59, 130, 246, 0.35);
    }
  </style>
</head>

<body class="hris-app page-<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>">
  <aside class="sidebar">
    <a class="logo-group" href="<?= htmlspecialchars($navHome, ENT_QUOTES, 'UTF-8') ?>" aria-label="HRIS home">
      <span class="logo-mark">H</span><span class="logo-text"><strong>HRIS</strong><small><?= htmlspecialchars($navTagline, ENT_QUOTES, 'UTF-8') ?></small></span>
    </a>
    <nav class="nav-list" aria-label="Main navigation">
      <?php foreach ($navItems as [$href, $label, $icon, $pages, $visible]): ?>
        <?php if ($visible): ?>
          <?php if ($href === '/hr/onboarding.php'): ?>
            <a
              class="nav-item <?= in_array($currentPage, $pages, true) ? 'active' : '' ?>"
              href="<?= $href ?>"
              id="navOnboardingToggle"
              style="justify-content:space-between;"
            >
              <span style="display:inline-flex; align-items:center; gap:10px;">
                <span class="nav-icon"><?= $icon ?></span>
                <span><?= $label ?></span>
              </span>
              <span id="navOnboardingCaret" style="font-size:11px; opacity:0.8;"><?= $isOnboardingGroupActive ? '▾' : '▸' ?></span>
            </a>
            <div class="nav-sub-group <?= $isOnboardingGroupActive ? 'is-open' : '' ?>" id="navOnboardingSubMenu">
              <a
                class="nav-sub-item <?= $currentPage === 'onboarding_documents' ? 'active' : '' ?>"
                href="/hr/onboarding_documents.php"
              >
                <span>↳</span>
                <span>Pre-Employment Docs</span>
              </a>
            </div>
          <?php else: ?>
            <a class="nav-item <?= in_array($currentPage, $pages, true) ? 'active' : '' ?>" href="<?= $href ?>"><span class="nav-icon"><?= $icon ?></span><span><?= $label ?></span></a>
          <?php endif; ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <div class="footer-line"></div>
      <div class="admin-profile"><span class="avatar"><?= htmlspecialchars($navInitials, ENT_QUOTES, 'UTF-8') ?></span><span class="admin-info"><strong><?= htmlspecialchars($navName, ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars($navSub, ENT_QUOTES, 'UTF-8') ?></span></span></div>
      <a class="logout-btn" href="/authentication/logout.php">
        <svg class="logout-ico" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span>Log Out</span>
      </a>
    </div>
  </aside>
  <script>
    (function () {
      const toggle = document.getElementById('navOnboardingToggle');
      const subMenu = document.getElementById('navOnboardingSubMenu');
      const caret = document.getElementById('navOnboardingCaret');
      if (!toggle || !subMenu) return;

      toggle.addEventListener('click', function (e) {
        if (window.location.pathname.endsWith('/hr/onboarding.php')) {
          e.preventDefault();
          const open = subMenu.classList.toggle('is-open');
          if (caret) caret.textContent = open ? '▾' : '▸';
        }
      });
    })();
  </script>
  <main class="main">
    <header class="topbar">
      <div class="topbar-title"><span class="eyebrow"><?= htmlspecialchars($navEyebrow, ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></strong></div>
      <div class="topbar-actions"><span class="system-status"><span class="status-dot"></span> <?= htmlspecialchars($navStatus, ENT_QUOTES, 'UTF-8') ?></span></div>
    </header>
    <section class="content">