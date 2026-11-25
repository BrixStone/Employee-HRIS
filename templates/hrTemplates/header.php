<?php
// templates/hrTemplates/header.php

require_once __DIR__ . '/../../classes/Rbac.php';

$currentPage = basename(
    $_SERVER['PHP_SELF'] ?? 'dashboard.php',
    '.php'
);

// ==================================================
// ACCOUNT INFORMATION
// ==================================================

$navRole = Rbac::normalizeRole($_SESSION['role'] ?? '');
$navRank = Rbac::normalizeRank($_SESSION['rank'] ?? 'employee');

$navPdo = (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;

$isHrSide = in_array($navRole, ['admin', 'hr'], true);

$isManager = $navRole === 'employee'
    && in_array($navRank, ['manager', 'team_leader'], true);

// ==================================================
// PERMISSION HELPERS
// ==================================================

$navCan = static function (
    string $module,
    string $ability = 'view',
    string $scope = 'all'
) use ($navPdo, $navRole, $navRank): bool {

    if ($navPdo === null) {
        return false;
    }

    return Rbac::can(
        $navPdo,
        $navRole,
        $module,
        $ability,
        $scope,
        $navRank
    );
};

$navModuleEnabled = static function (
    string $module
) use ($navPdo, $navRole, $navRank): bool {

    if ($navPdo === null) {
        return false;
    }

    return Rbac::moduleEnabled(
        $navPdo,
        $navRole,
        $module,
        $navRank
    );
};

// ==================================================
// PAGE TITLES
// ==================================================

$pageTitles = [

    'dashboard' => $isManager
        ? ($navRank === 'manager' ? 'Manager Dashboard' : 'Team Leader Dashboard')
        : ($isHrSide ? 'HR Dashboard' : 'Dashboard'),

    'employees' => 'Employee Management',
    'add_employee' => 'Add Employee',
    'edit_employee' => 'Edit Employee',
    'view_employee' => 'Employee Profile',
    'employee_deactivate' => 'Deactivate Employee',

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
    'leave_requests' => 'Team Leave Requests',

    'report' => 'Reports & Audits',
    'payroll' => 'Payroll Management',
    'payslip_view' => 'Payslip',

    'users' => 'User Management',
    'create_user' => 'Create User',
    'permissions' => 'System Settings',

    'team' => 'My Team',
    'promotion_requests' => 'Promotion Approvals'
];

$pageTitle = $pageTitles[$currentPage] ?? 'Human Resources';

$defaultCssSlug = in_array(
    $currentPage,
    [
        'daily_attendance',
        'attendance_records',
        'late_absence_monitoring',
        'attendance_reports'
    ],
    true
) ? 'attendance' : $currentPage;

$cssPage = $cssPageOverride
    ?? preg_replace('/[^a-z0-9_-]/', '', $defaultCssSlug);

// ==================================================
// NAVIGATION GROUPS
// ==================================================

$attendancePages = [
    'attendance',
    'daily_attendance',
    'attendance_records',
    'late_absence_monitoring',
    'attendance_reports',
    'schedule_management'
];

$isAttendanceGroupActive = in_array(
    $currentPage,
    $attendancePages,
    true
);

$isOnboardingGroupActive = in_array(
    $currentPage,
    ['onboarding', 'onboarding_documents'],
    true
);

$canViewOnboarding = $isHrSide
    && $navModuleEnabled('employee_onboarding');

// Current query parameters.
$curTab = $_GET['tab'] ?? '';
$curRange = $_GET['range'] ?? '';
$curQ = $_GET['q'] ?? '';

// ==================================================
// SIDEBAR NAVIGATION
// ==================================================

$navItems = [

    // HR / ADMIN

    [
        '/hr/dashboard.php',
        'Dashboard',
        '▦',
        ['dashboard'],
        $isHrSide && $navModuleEnabled('dashboard')
    ],

    [
        '/hr/employees.php',
        'Employees',
        '♙',
        [
            'employees',
            'add_employee',
            'edit_employee',
            'view_employee',
            'employee_deactivate'
        ],
        $isHrSide && $navModuleEnabled('employee_records')
    ],

    [
        '/hr/daily_attendance.php',
        'Attendance',
        '🕒',
        $attendancePages,
        $isHrSide && $navModuleEnabled('attendance')
    ],

    [
        '/hr/recruitment.php',
        'Recruitment',
        '❖',
        ['recruitment'],
        $isHrSide && $navModuleEnabled('recruitment')
    ],

    [
        '/hr/leave_management.php',
        'Leave Management',
        '◷',
        ['leave_management'],
        $isHrSide && $navCan('leave_requests', 'view', 'all')
    ],

    [
        '/hr/onboarding.php',
        'Onboarding',
        '✦',
        ['onboarding', 'onboarding_documents'],
        $canViewOnboarding
    ],

    [
        '/hr/department.php',
        'Departments',
        '▤',
        ['department'],
        $isHrSide && $navCan('employee_records', 'edit', 'all')
    ],

    [
        '/hr/position.php',
        'Positions',
        '▣',
        ['position'],
        $isHrSide && $navCan('employee_records', 'edit', 'all')
    ],

    [
        '/hr/payroll.php',
        'Payroll',
        '₱',
        ['payroll', 'payslip_view'],
        $isHrSide && $navModuleEnabled('payroll')
    ],

    [
        '/hr/promotion_requests.php',
        'Promotion Approvals',
        '↗',
        ['promotion_requests'],
        $isHrSide && $navCan('promotion_management', 'approve', 'all')
    ],

    [
        '/hr/report.php',
        'Reports & Audits',
        '▥',
        ['report'],
        $isHrSide && $navModuleEnabled('reports')
    ],

    [
        '/admin/users.php',
        'User Management',
        '☺',
        ['users', 'create_user'],
        $isHrSide && $navModuleEnabled('user_management')
    ],

    [
        '/admin/permissions.php',
        'System Settings',
        '⚙',
        ['permissions'],
        $isHrSide && $navModuleEnabled('system_settings')
    ],

    // MANAGER / TEAM LEADER

    [
        '/manager/dashboard.php',
        'Dashboard',
        '▦',
        ['dashboard'],
        $isManager && $navModuleEnabled('dashboard')
    ],

    [
        '/manager/team.php',
        'My Team',
        '♙',
        ['team'],
        $isManager && $navCan('employee_records', 'view', 'team')
    ],

    [
        '/manager/leave_requests.php',
        'Team Leave',
        '◷',
        ['leave_requests'],
        $isManager && $navCan('leave_requests', 'view', 'team')
    ],

    [
        '/manager/attendance.php',
        'Team Attendance',
        '▥',
        ['attendance'],
        $isManager && $navCan('attendance', 'view', 'team')
    ]
];

// ==================================================
// SIDEBAR PROFILE
// ==================================================

$roleLabels = [

    'admin' => [
        'AD',
        'Administrator',
        'System Admin',
        'ADMINISTRATION',
        'Admin Workspace',
        'Admin Control'
    ],

    'hr' => [
        'HR',
        'HR Administration',
        'Human Resources',
        'HUMAN RESOURCES',
        'HR Workspace',
        'HR Portal'
    ],

    'employee' => $navRank === 'manager'
        ? [
            'MG',
            'Manager',
            'Department Management',
            'MANAGER PORTAL',
            'Manager Workspace',
            'Manager Portal'
        ]
        : (
            $navRank === 'team_leader'
                ? [
                    'TL',
                    'Team Leader',
                    'Team Management',
                    'TEAM LEADER',
                    'Team Workspace',
                    'Team Portal'
                ]
                : [
                    'EM',
                    'Employee',
                    'Employee Workspace',
                    'EMPLOYEE',
                    'Employee Workspace',
                    'Employee Portal'
                ]
        )
];

[
    $navInitials,
    $navName,
    $navSub,
    $navEyebrow,
    $navStatus,
    $navTagline
] = $roleLabels[$navRole] ?? [
    'HR',
    'HR Administration',
    'Human Resources',
    'HUMAN RESOURCES',
    'HR Workspace',
    'HR Portal'
];

$navHome = function_exists('role_home')
    ? role_home($navRole)
    : ($isManager ? '/manager/dashboard.php' : '/hr/dashboard.php');

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | HRIS
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="/assets/css/hris-base.css">
    <link rel="stylesheet" href="/assets/css/welcome.css">

    <link
        rel="stylesheet"
        href="/assets/css/pages/<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>.css">

    <script src="/asset/js/page-transition.js" defer></script>

    <style>
        /* =========================================
           SIDEBAR
        ========================================= */

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
            transition: transform .28s cubic-bezier(.4, 0, .2, 1);
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
            background: rgba(0, 0, 0, .55);
            z-index: 299;
        }

        .sidebar-backdrop.active {
            display: block;
        }

        /* =========================================
           MAIN CONTENT
        ========================================= */

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

        .hris-app .topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            width: 100%;
            min-width: 0;
            gap: 12px;
        }

        .topbar-title {
            display: flex;
            flex-direction: column;
            min-width: 0;
            gap: 6px;
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

        /* =========================================
           SIDEBAR TOGGLE
        ========================================= */

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
            background: rgba(255, 255, 255, .06);
            border: 1px solid rgba(255, 255, 255, .12);
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
            background: rgba(139, 92, 246, .2);
            border-color: rgba(139, 92, 246, .4);
        }

        /* =========================================
           NAVIGATION SUBMENUS
        ========================================= */

        .hris-app .nav-list {
            display: grid;
            overflow: visible;
        }

        .hris-app .sidebar-footer {
            display: block;
        }

        .nav-group {
            display: flex;
            flex-direction: column;
            gap: 3px;
            margin: 2px 0 6px 14px;
            padding-left: 10px;
            border-left: 1px solid rgba(148, 163, 184, .22);
        }

        .nav-group-title {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
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
            transition: background .15s ease, color .15s ease;
        }

        .nav-link:hover {
            background: rgba(30, 41, 59, .75);
            color: #f8fafc;
        }

        .nav-link.active {
            background: rgba(124, 58, 237, .24);
            color: #ddd6fe;
            border: 1px solid rgba(139, 92, 246, .4);
        }

        /* =========================================
           SCROLLBARS
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
                flex-wrap: wrap;
            }

            .hris-app .topbar-actions {
                display: none;
            }

            .hris-app .content {
                padding: 22px 16px 32px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .hris-app .sidebar {
                transition: none;
            }
        }
    </style>
</head>

<body class="hris-app page-<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>">

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- =========================================
         SIDEBAR
    ========================================== -->

    <aside class="sidebar hidden" id="hrSidebar">

        <a
            class="logo-group"
            href="<?= htmlspecialchars($navHome, ENT_QUOTES, 'UTF-8') ?>"
            aria-label="HRIS home">

            <span class="logo-mark">H</span>

            <span class="logo-text">
                <strong>HRIS</strong>
                <small><?= htmlspecialchars($navTagline, ENT_QUOTES, 'UTF-8') ?></small>
            </span>

        </a>

        <nav class="nav-list" aria-label="Main navigation">

            <?php foreach ($navItems as [$href, $label, $icon, $pages, $visible]): ?>

                <?php if ($visible): ?>

                    <?php if ($href === '/hr/daily_attendance.php'): ?>

                        <a
                            class="nav-item <?= in_array($currentPage, $pages, true) ? 'active' : '' ?>"
                            href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
                            id="navAttendanceToggle"
                            style="justify-content:space-between;">

                            <span style="display:inline-flex;align-items:center;gap:10px;">
                                <span class="nav-icon"><?= $icon ?></span>
                                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                            </span>

                            <span id="navAttendanceCaret" style="font-size:11px;opacity:.8;">
                                <?= $isAttendanceGroupActive ? '▾' : '▸' ?>
                            </span>

                        </a>

                        <div
                            class="nav-group <?= $isAttendanceGroupActive ? 'is-open' : '' ?>"
                            id="navAttendanceSubMenu"
                            style="<?= $isAttendanceGroupActive ? '' : 'display:none;' ?>">

                            <div class="nav-group-title">
                                Attendance Management
                            </div>

                            <a
                                href="/hr/schedule_management.php"
                                class="nav-link <?= $currentPage === 'schedule_management' ? 'active' : '' ?>">
                                📅 Schedule Management
                            </a>

                            <a
                                href="/hr/daily_attendance.php"
                                class="nav-link <?= (
                                    $currentPage === 'daily_attendance'
                                    || ($currentPage === 'attendance' && $curTab === 'logs' && $curRange === 'daily')
                                ) ? 'active' : '' ?>">
                                🕒 Daily Attendance
                            </a>

                            <a
                                href="/hr/attendance_records.php"
                                class="nav-link <?= (
                                    $currentPage === 'attendance_records'
                                    || ($currentPage === 'attendance' && $curTab === 'logs' && $curRange === 'monthly')
                                ) ? 'active' : '' ?>">
                                📄 Attendance Records
                            </a>

                            <a
                                href="/hr/late_absence_monitoring.php"
                                class="nav-link <?= (
                                    $currentPage === 'late_absence_monitoring'
                                    || ($currentPage === 'attendance' && $curTab === 'logs' && $curQ === 'Late')
                                ) ? 'active' : '' ?>">
                                ⚠️ Late and Absence Monitoring
                            </a>

                            <a
                                href="/hr/attendance.php?tab=logs"
                                class="nav-link <?= (
                                    $currentPage === 'attendance'
                                    && $curTab === 'logs'
                                    && $curRange === ''
                                    && $curQ === ''
                                ) ? 'active' : '' ?>">
                                ⏱️ Overtime Management
                            </a>

                            <a
                                href="/hr/attendance_reports.php"
                                class="nav-link <?= (
                                    $currentPage === 'attendance_reports'
                                    || ($currentPage === 'attendance' && $curTab === 'reports')
                                ) ? 'active' : '' ?>">
                                📊 Attendance Reports
                            </a>

                        </div>

                    <?php elseif ($href === '/hr/onboarding.php'): ?>

                        <a
                            class="nav-item <?= in_array($currentPage, $pages, true) ? 'active' : '' ?>"
                            href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
                            id="navOnboardingToggle"
                            style="justify-content:space-between;">

                            <span style="display:inline-flex;align-items:center;gap:10px;">
                                <span class="nav-icon"><?= $icon ?></span>
                                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                            </span>

                            <span id="navOnboardingCaret" style="font-size:11px;opacity:.8;">
                                <?= $isOnboardingGroupActive ? '▾' : '▸' ?>
                            </span>

                        </a>

                        <div
                            class="nav-sub-group <?= $isOnboardingGroupActive ? 'is-open' : '' ?>"
                            id="navOnboardingSubMenu">

                            <a
                                class="nav-sub-item <?= $currentPage === 'onboarding_documents' ? 'active' : '' ?>"
                                href="/hr/onboarding_documents.php">

                                <span>↳</span>
                                <span>Pre-Employment Docs</span>

                            </a>

                        </div>

                    <?php else: ?>

                        <a
                            class="nav-item <?= in_array($currentPage, $pages, true) ? 'active' : '' ?>"
                            href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">

                            <span class="nav-icon"><?= $icon ?></span>
                            <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>

                        </a>

                    <?php endif; ?>

                <?php endif; ?>

            <?php endforeach; ?>

        </nav>

        <!-- SIDEBAR FOOTER -->

        <div class="sidebar-footer">

            <div class="footer-line"></div>

            <div class="admin-profile">

                <span class="avatar">
                    <?= htmlspecialchars($navInitials, ENT_QUOTES, 'UTF-8') ?>
                </span>

                <span class="admin-info">
                    <strong><?= htmlspecialchars($navName, ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= htmlspecialchars($navSub, ENT_QUOTES, 'UTF-8') ?></span>
                </span>

            </div>

            <a class="logout-btn" href="/authentication/logout.php">

                <svg
                    class="logout-ico"
                    viewBox="0 0 24 24"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                    aria-hidden="true">

                    <path
                        d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round" />

                </svg>

                <span>Log Out</span>

            </a>

        </div>

    </aside>

    <!-- =========================================
         MAIN CONTENT
    ========================================== -->

    <main class="main">

        <header class="topbar">

            <div class="topbar-title">

                <span class="eyebrow">
                    <?= htmlspecialchars($navEyebrow, ENT_QUOTES, 'UTF-8') ?>
                </span>

                <div class="title-row">

                    <button
                        type="button"
                        class="sidebar-toggle"
                        id="sidebarToggle"
                        aria-label="Open navigation"
                        aria-expanded="false"
                        aria-controls="hrSidebar">

                        <span class="hb-line"></span>
                        <span class="hb-line"></span>
                        <span class="hb-line"></span>

                    </button>

                    <strong>
                        <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>
                    </strong>

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

    // =========================================
    // SIDEBAR CONTROLS
    // =========================================

    const sidebar = document.getElementById('hrSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggle = document.getElementById('sidebarToggle');

    if (!sidebar || !backdrop || !toggle) {
        return;
    }

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

    // Close sidebar when navigating to another page.
    sidebar.querySelectorAll('.nav-item, .nav-link, .nav-sub-item')
        .forEach(function (link) {

            link.addEventListener('click', function () {

                const isAttendance = link.id === 'navAttendanceToggle';
                const isOnboarding = link.id === 'navOnboardingToggle';

                if (!isAttendance && !isOnboarding) {
                    closeSidebar();
                }

            });

        });

    // =========================================
    // ATTENDANCE SUBMENU
    // =========================================

    const attToggle = document.getElementById('navAttendanceToggle');
    const attMenu = document.getElementById('navAttendanceSubMenu');
    const attCaret = document.getElementById('navAttendanceCaret');

    if (attToggle && attMenu) {

        attToggle.addEventListener('click', function (event) {

            const currentPath = window.location.pathname;

            const isAttendancePage =
                currentPath.endsWith('/hr/attendance.php') ||
                currentPath.endsWith('/hr/daily_attendance.php') ||
                currentPath.endsWith('/hr/attendance_records.php') ||
                currentPath.endsWith('/hr/late_absence_monitoring.php') ||
                currentPath.endsWith('/hr/attendance_reports.php') ||
                currentPath.endsWith('/hr/schedule_management.php');

            if (isAttendancePage) {

                event.preventDefault();

                const isOpen = attMenu.style.display !== 'none';

                attMenu.style.display = isOpen ? 'none' : 'flex';
                attMenu.classList.toggle('is-open', !isOpen);

                if (attCaret) {
                    attCaret.textContent = !isOpen ? '▾' : '▸';
                }

            }

        });

    }

    // =========================================
    // ONBOARDING SUBMENU
    // =========================================

    const onboardingToggle = document.getElementById('navOnboardingToggle');
    const onboardingMenu = document.getElementById('navOnboardingSubMenu');
    const onboardingCaret = document.getElementById('navOnboardingCaret');

    if (onboardingToggle && onboardingMenu) {

        onboardingToggle.addEventListener('click', function (event) {

            if (window.location.pathname.endsWith('/hr/onboarding.php')) {

                event.preventDefault();

                const isOpen = onboardingMenu.classList.toggle('is-open');

                if (onboardingCaret) {
                    onboardingCaret.textContent = isOpen ? '▾' : '▸';
                }

            }

        });

    }

})();
</script>
