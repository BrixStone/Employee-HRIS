
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
  'attendance' => 'Attendance',
  'daily_attendance' => 'Daily Attendance',
  'attendance_records' => 'Attendance Records',
  'late_absence_monitoring' => 'Late and Absence Monitoring',
  'attendance_reports' => 'Attendance Reports',
  'schedule_management' => 'Schedule Management',
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
];

$pageTitle = $pageTitles[$currentPage] ?? 'Human Resources';

$defaultCssSlug = in_array($currentPage, ['daily_attendance', 'attendance_records', 'late_absence_monitoring', 'attendance_reports'], true)
  ? 'attendance'
  : $currentPage;
$cssPage = $cssPageOverride ?? preg_replace('/[^a-z0-9_-]/', '', $defaultCssSlug);

$canViewOnboarding = $isHrSide && $navCan('employee_records', 'view', 'all');
$isOnboardingGroupActive = in_array($currentPage, ['onboarding', 'onboarding_documents'], true);
$isAttendanceGroupActive = in_array($currentPage, ['attendance', 'daily_attendance', 'attendance_records', 'late_absence_monitoring', 'attendance_reports', 'schedule_management'], true);

$reqUri = $_SERVER['REQUEST_URI'] ?? '';
$curTab = $_GET['tab'] ?? '';
$curRange = $_GET['range'] ?? '';
$curQ = $_GET['q'] ?? '';

$navItems = [
  ['/hr/dashboard.php', 'Dashboard', '▦', ['dashboard'], $isHrSide && $navCan('dashboard', 'view', 'all')],
  ['/hr/employees.php', 'Employees', '♙', ['employees', 'add_employee', 'edit_employee', 'view_employee', 'employee_deactivate'], $isHrSide && $navCan('employee_records', 'view', 'all')],
  ['/hr/daily_attendance.php', 'Attendance', '🕒', ['attendance', 'daily_attendance', 'attendance_records', 'late_absence_monitoring', 'attendance_reports', 'schedule_management'], $isHrSide],
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
    'admin' => ['AD', 'Administrator', 'System Admin', 'ADMINISTRATION', 'Admin Workspace', 'Admin Control'],
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

    <style>
        /* Sidebar overlay */
        .hris-app .sidebar {
            position: fixed !important;
            top: 0;
            left: 0;
            bottom: 0;
            width: min(264px, 85vw);
            height: 100vh;
            height: 100dvh;
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 300;
            transform: translateX(-100%);
            transition: transform .28s cubic-bezier(.4,0,.2,1);
        }

        .hris-app .sidebar:not(.hidden) {
            transform: translateX(0);
        }

        .hris-app .sidebar.hidden {
            transform: translateX(-100%);
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.55);
            z-index: 299;
        }

        .sidebar-backdrop.active {
            display: block;
        }

        .hris-app .main {
            margin-left: 0 !important;
            width: 100%;
            min-width: 0;
            max-width: 100%;
        }

        .hris-app .topbar {
            min-width: 0;
            width: 100%;
            gap: 12px;
        }

        .topbar-title {
            display: flex;
            flex-direction: column;
            min-width: 0;
            gap: 2px;
        }

        .title-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .title-row strong {
            font-size: 18px;
            color: #fff;
            overflow-wrap: anywhere;
        }

        .sidebar-toggle {
            display: inline-flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 5px;
            width: 36px;
            height: 36px;
            flex-shrink: 0;
            padding: 7px 6px;
            margin-right: 0;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 8px;
            cursor: pointer;
        }

        .sidebar-toggle .hb-line {
            display: block;
            width: 18px;
            height: 2px;
            border-radius: 2px;
            background: #e2e0ea;
        }

        .sidebar-toggle:hover {
            background: rgba(139,92,246,.2);
            border-color: rgba(139,92,246,.4);
        }

        .hris-app .nav-list {
            display: grid;
            overflow: visible;
        }

        .hris-app .sidebar-footer {
            display: block;
        }

        .hris-app .content {
            width: 100%;
            min-width: 0;
            max-width: 100%;
            margin: 0;
        }

        html, body {
            max-width: 100%;
            overflow-x: hidden;
        }

        @media(max-width:760px) {
            .hris-app .sidebar {
                position: fixed !important;
                width: min(264px, 85vw);
                height: 100dvh;
            }

            .hris-app .topbar {
                height: auto;
                min-height: 68px;
                padding: 12px 16px;
                flex-wrap: wrap;
            }

            .hris-app .topbar-actions {
                display: none;
            }

            .hris-app .content {
                padding: 22px 16px 32px;
            }
        }

        @media(prefers-reduced-motion:reduce) {
            .hris-app .sidebar {
                transition: none;
            }
        }
        
/* =========================================
   HR HEADER - MATCH ADMIN SIDEBAR
========================================= */

.hris-app .sidebar {
    position: fixed !important;
    top: 0;
    left: 0;
    bottom: 0;
    width: 264px;
    max-width: 85vw;
    height: 100vh;
    height: 100dvh;
    overflow-y: auto;
    overflow-x: hidden;
    z-index: 300;
    transform: translateX(-100%);
    transition: transform 0.28s cubic-bezier(.4,0,.2,1);
}

.hris-app .sidebar.hidden {
    transform: translateX(-100%);
}

.hris-app .sidebar:not(.hidden) {
    transform: translateX(0);
}

/* Backdrop */
.sidebar-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 299;
}

.sidebar-backdrop.active {
    display: block;
}

/* Full-width main */
.hris-app .main {
    margin-left: 0 !important;
    width: 100% !important;
    max-width: 100vw !important;
    min-width: 0;
    overflow-x: hidden;
}

.hris-app .content {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    margin: 0;
    overflow-x: hidden;
}

/* Topbar */
.hris-app .topbar {
    position: sticky;
    top: 0;
    z-index: 100;
    width: 100%;
    min-width: 0;
}

/* =========================================
   HIDE SCROLLBARS
========================================= */

html,
body {
    max-width: 100%;
    overflow-x: hidden;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

html::-webkit-scrollbar,
body::-webkit-scrollbar,
.sidebar::-webkit-scrollbar,
.content::-webkit-scrollbar {
    display: none;
    width: 0;
    height: 0;
}

/* =========================================
   MOBILE
========================================= */

@media (max-width: 760px) {
    .hris-app .sidebar {
        position: fixed !important;
        width: min(264px, 85vw);
        height: 100dvh;
    }

    .hris-app .nav-list {
        display: grid;
        overflow: visible;
    }

    .hris-app .sidebar-footer {
        display: block;
    }

    .hris-app .topbar {
        padding: 12px 16px;
        min-height: 68px;
        height: auto;
    }

    .hris-app .topbar-actions {
        display: none;
    }
<<<<<<< HEAD

    .hris-app .content {
        padding: 22px 16px 32px;
    }
}

    </style>
</head>

<body class="hris-app page-<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>">
=======
    .nav-group {
      display: flex;
      flex-direction: column;
      gap: 3px;
      margin: 2px 0 6px 14px;
      padding-left: 10px;
      border-left: 1px solid rgba(148, 163, 184, 0.22);
    }
    .nav-group-title {
      font-size: 10.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #64748b;
      padding: 4px 10px 2px;
    }
    .nav-link {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 7px 11px;
      border-radius: 9px;
      font-size: 12.5px;
      font-weight: 600;
      color: #94a3b8;
      text-decoration: none;
      transition: background 0.15s ease, color 0.15s ease;
    }
    .nav-link:hover {
      background: rgba(30, 41, 59, 0.75);
      color: #f8fafc;
    }
    .nav-link.active {
      background: rgba(124, 58, 237, 0.24);
      color: #ddd6fe;
      border: 1px solid rgba(139, 92, 246, 0.4);
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
          <?php if ($href === '/hr/daily_attendance.php'): ?>
            <a
              class="nav-item <?= in_array($currentPage, $pages, true) ? 'active' : '' ?>"
              href="<?= $href ?>"
              id="navAttendanceToggle"
              style="justify-content:space-between;"
            >
              <span style="display:inline-flex; align-items:center; gap:10px;">
                <span class="nav-icon"><?= $icon ?></span>
                <span><?= $label ?></span>
              </span>
              <span id="navAttendanceCaret" style="font-size:11px; opacity:0.8;"><?= $isAttendanceGroupActive ? '▾' : '▸' ?></span>
            </a>
            <div class="nav-group <?= $isAttendanceGroupActive ? 'is-open' : '' ?>" id="navAttendanceSubMenu" style="<?= $isAttendanceGroupActive ? '' : 'display:none;' ?>">
              <div class="nav-group-title">Attendance Management</div>
              <a href="/hr/schedule_management.php" class="nav-link <?= $currentPage === 'schedule_management' ? 'active' : '' ?>">📅 Schedule Management</a>
              <a href="/hr/daily_attendance.php" class="nav-link <?= ($currentPage === 'daily_attendance' || ($currentPage === 'attendance' && $curTab === 'logs' && $curRange === 'daily')) ? 'active' : '' ?>">🕒 Daily Attendance</a>
              <a href="/hr/attendance_records.php" class="nav-link <?= ($currentPage === 'attendance_records' || ($currentPage === 'attendance' && $curTab === 'logs' && $curRange === 'monthly')) ? 'active' : '' ?>">📄 Attendance Records</a>
              <a href="/hr/late_absence_monitoring.php" class="nav-link <?= ($currentPage === 'late_absence_monitoring' || ($currentPage === 'attendance' && $curTab === 'logs' && $curQ === 'Late')) ? 'active' : '' ?>">⚠️ Late and Absence Monitoring</a>
              <a href="/hr/attendance.php?tab=logs" class="nav-link <?= ($currentPage === 'attendance' && $curTab === 'logs' && $curRange === '' && $curQ === '') ? 'active' : '' ?>">⏱️ Overtime Management</a>
              <a href="/hr/attendance_reports.php" class="nav-link <?= ($currentPage === 'attendance_reports' || ($currentPage === 'attendance' && $curTab === 'reports')) ? 'active' : '' ?>">📊 Attendance Reports</a>
            </div>
          <?php elseif ($href === '/hr/onboarding.php'): ?>
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
      const attToggle = document.getElementById('navAttendanceToggle');
      const attMenu = document.getElementById('navAttendanceSubMenu');
      const attCaret = document.getElementById('navAttendanceCaret');
      if (attToggle && attMenu) {
        attToggle.addEventListener('click', function (e) {
          const p = window.location.pathname;
          if (
            p.endsWith('/hr/attendance.php') ||
            p.endsWith('/hr/daily_attendance.php') ||
            p.endsWith('/hr/attendance_records.php') ||
            p.endsWith('/hr/late_absence_monitoring.php') ||
            p.endsWith('/hr/attendance_reports.php')
          ) {
            e.preventDefault();
            const isOpen = attMenu.style.display !== 'none';
            attMenu.style.display = isOpen ? 'none' : 'flex';
            attMenu.classList.toggle('is-open', !isOpen);
            if (attCaret) attCaret.textContent = !isOpen ? '▾' : '▸';
          }
        });
      }

      const toggle = document.getElementById('navOnboardingToggle');
      const subMenu = document.getElementById('navOnboardingSubMenu');
      const caret = document.getElementById('navOnboardingCaret');
      if (!toggle || !subMenu) return;
>>>>>>> 717d4fc (modified: some features in templates)

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<aside class="sidebar hidden" id="hrSidebar">

    <a class="logo-group" href="<?= htmlspecialchars($navHome, ENT_QUOTES, 'UTF-8') ?>" aria-label="HRIS home">
        <span class="logo-mark">H</span>
        <span class="logo-text">
            <strong>HRIS</strong>
            <small><?= htmlspecialchars($navTagline, ENT_QUOTES, 'UTF-8') ?></small>
        </span>
    </a>

    <nav class="nav-list" aria-label="Main navigation">

        <?php foreach ($navItems as [$href, $label, $icon, $pages, $visible]): ?>
            <?php if ($visible): ?>

                <?php if ($href === '/hr/onboarding.php'): ?>

                    <a class="nav-item <?= in_array($currentPage, $pages, true) ? 'active' : '' ?>"
                       href="<?= $href ?>"
                       id="navOnboardingToggle"
                       style="justify-content:space-between;">

                        <span style="display:inline-flex;align-items:center;gap:10px;">
                            <span class="nav-icon"><?= $icon ?></span>
                            <span><?= $label ?></span>
                        </span>

                        <span id="navOnboardingCaret" style="font-size:11px;opacity:.8;">
                            <?= $isOnboardingGroupActive ? '▾' : '▸' ?>
                        </span>
                    </a>

                    <div class="nav-sub-group <?= $isOnboardingGroupActive ? 'is-open' : '' ?>"
                         id="navOnboardingSubMenu">

                        <a class="nav-sub-item <?= $currentPage === 'onboarding_documents' ? 'active' : '' ?>"
                           href="/hr/onboarding_documents.php">
                            <span>↳</span>
                            <span>Pre-Employment Docs</span>
                        </a>

                    </div>

                <?php else: ?>

                    <a class="nav-item <?= in_array($currentPage, $pages, true) ? 'active' : '' ?>"
                       href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
                        <span class="nav-icon"><?= $icon ?></span>
                        <span><?= $label ?></span>
                    </a>

                <?php endif; ?>

            <?php endif; ?>
        <?php endforeach; ?>

    </nav>

    <div class="sidebar-footer">
        <div class="footer-line"></div>

        <div class="admin-profile">
            <span class="avatar"><?= htmlspecialchars($navInitials, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="admin-info">
                <strong><?= htmlspecialchars($navName, ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= htmlspecialchars($navSub, ENT_QUOTES, 'UTF-8') ?></span>
            </span>
        </div>

        <a class="logout-btn" href="/authentication/logout.php">
            <svg class="logout-ico" viewBox="0 0 24 24" fill="none"
                 xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
                      stroke="currentColor" stroke-width="2"
                      stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span>Log Out</span>
        </a>
    </div>

</aside>

<main class="main">

    <header class="topbar">

        <div class="topbar-title">
            <span class="eyebrow"><?= htmlspecialchars($navEyebrow, ENT_QUOTES, 'UTF-8') ?></span>

            <div class="title-row">

                <button type="button"
                        class="sidebar-toggle"
                        id="sidebarToggle"
                        aria-label="Open navigation"
                        aria-expanded="false"
                        aria-controls="hrSidebar">

                    <span class="hb-line"></span>
                    <span class="hb-line"></span>
                    <span class="hb-line"></span>

                </button>

                <strong><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></strong>

            </div>
        </div>

        <div class="topbar-actions">
            <span class="system-status">
                <span class="status-dot"></span>
                <?= htmlspecialchars($navStatus, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

    </header>

    <section class="content">

<script>
(function () {
    const sidebar = document.getElementById('hrSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggle = document.getElementById('sidebarToggle');

    if (!sidebar || !backdrop || !toggle) return;

    function openSidebar() {
        sidebar.classList.remove('hidden');
        backdrop.classList.add('active');
        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'Close navigation');
    }

    function closeSidebar() {
        sidebar.classList.add('hidden');
        backdrop.classList.remove('active');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open navigation');
    }

    toggle.addEventListener('click', function () {
        if (sidebar.classList.contains('hidden')) {
            openSidebar();
        } else {
            closeSidebar();
        }
    });

    backdrop.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });

    sidebar.querySelectorAll('.nav-item').forEach(function (link) {
        link.addEventListener('click', function (event) {
            const isOnboarding = link.id === 'navOnboardingToggle';

            if (!isOnboarding) {
                closeSidebar();
            }
        });
    });

    // Preserve the existing onboarding submenu behavior.
    const onboardingToggle = document.getElementById('navOnboardingToggle');
    const subMenu = document.getElementById('navOnboardingSubMenu');
    const caret = document.getElementById('navOnboardingCaret');

    if (onboardingToggle && subMenu) {
        onboardingToggle.addEventListener('click', function (event) {
            if (window.location.pathname.endsWith('/hr/onboarding.php')) {
                event.preventDefault();
                const isOpen = subMenu.classList.toggle('is-open');

                if (caret) {
                    caret.textContent = isOpen ? '▾' : '▸';
                }
            }
        });
    }
})();
</script>
