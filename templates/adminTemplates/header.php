<?php

$currentPage = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php', '.php');

require_once __DIR__ . '/../../classes/Rbac.php';

/*
|--------------------------------------------------------------------------
| Logged-in account information
|--------------------------------------------------------------------------
*/

$navRole = Rbac::normalizeRole($_SESSION['role'] ?? '');

$navRank = Rbac::normalizeRank(
  $_SESSION['rank'] ?? 'employee'
);

$navPdo = (isset($pdo) && $pdo instanceof PDO)
  ? $pdo
  : null;

/*
|--------------------------------------------------------------------------
| Permission checker
|--------------------------------------------------------------------------
*/

$navCan = static function (
  string $module,
  string $ability = 'view'
) use ($navPdo, $navRole, $navRank): bool {

  if ($navPdo === null) {
    return false;
  }

  return Rbac::can(
    $navPdo,
    $navRole,
    $module,
    $ability,
    $navRank
  );
};

/*
|--------------------------------------------------------------------------
| Module visibility checker
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Sidebar navigation
|--------------------------------------------------------------------------
*/

$navItems = [

  [
    '/admin/dashboard.php',
    'Dashboard',
    "\u{2756}",
    ['dashboard'],
    $navModuleEnabled('dashboard')
  ],

  [
    '/admin/users.php',
    'User Management',
    "\u{25C9}",
    ['users'],
    $navModuleEnabled('user_management')
  ],

  [
    '/admin/audit_logs.php',
    'Audit Trail',
    "\u{1F4CB}",
    ['audit_logs'],
    $navModuleEnabled('audit_logs')
  ],

  [
    '/admin/permissions.php',
    'System Permissions',
    "\u{2699}",
    ['permissions'],
    $navModuleEnabled('system_settings')
  ],

];

/*
|--------------------------------------------------------------------------
| Page titles
|--------------------------------------------------------------------------
*/

$pageTitles = [
  'dashboard'  => 'Dashboard',
  'users'      => 'User Management',
  'audit_logs' => 'Audit Trail',
  'permissions' => 'System Permissions',
];

$pageTitle = $pageTitles[$currentPage] ?? 'Administration';

$cssPage = $cssPageOverride
  ?? preg_replace('/[^a-z0-9_-]/', '', $currentPage);

/*
|--------------------------------------------------------------------------
| Profile information
|--------------------------------------------------------------------------
*/

$navUserName = $_SESSION['username'] ?? 'Administrator';

$navRoleLabel = match ($navRole) {
  'admin'    => 'System Administrator',
  'hr'       => 'Human Resources',
  'employee' => 'Employee',
  default    => 'User'
};

?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | HRIS Admin</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../assets/css/hris-base.css">
  <link rel="stylesheet" href="../assets/css/pages/<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>.css">

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
  <script src="../asset/js/page-transition.js" defer></script>

  <style>
    /* ── Layout: main fills full screen ──────────────── */
    html,
    body {
      height: 100%;
      margin: 0;
      padding: 0;
    }

    .hris-app {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }

    .topbar {
      position: sticky;
      top: 0;
      z-index: 100;
      flex-shrink: 0;
    }

    .main {
      display: flex;
      flex-direction: column;
      flex: 1;
      width: 100%;
      min-height: 0;
    }

    .content {
      flex: 1;
      overflow-y: auto;
      min-height: 0;
    }

    /* ── Sidebar overlay ─────────────────────────────── */
    .sidebar {
      position: fixed;
      top: 0;
      left: 0;
      height: 100vh;
      z-index: 300;
      transition: transform 0.28s cubic-bezier(.4, 0, .2, 1);
    }

    .sidebar.hidden {
      transform: translateX(-100%);
    }

    .sidebar-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.55);
      z-index: 299;
    }

    .sidebar-backdrop.active {
      display: block;
    }

    /* ── Hamburger button — fixed lines ──────────────── */
    .sidebar-toggle {
      display: inline-flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      gap: 5px;
      width: 36px;
      height: 36px;
      padding: 7px 6px;
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 8px;
      cursor: pointer;
      flex-shrink: 0;
      margin-right: 12px;
    }

    .sidebar-toggle .hb-line {
      display: block;
      width: 18px;
      height: 2px;
      border-radius: 2px;
      background-color: #e2e0ea;
      flex-shrink: 0;
      transition: all 0.2s ease;
    }

    .sidebar-toggle:hover {
      background: rgba(139, 92, 246, 0.2);
      border-color: rgba(139, 92, 246, 0.4);
    }

    .sidebar-toggle:hover .hb-line {
      background-color: #c4b5fd;
    }

    /* ── Topbar layout ───────────────────────────────── */
    .topbar-title {
      display: flex;
      align-items: center;
      gap: 0;
    }

    .topbar-title {
      display: flex;
      flex-direction: column;
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
    }

    /* Reset old margin on hamburger since it's now inline */
    .title-row .sidebar-toggle {
      margin-right: 0;
    }

    /* ── Override hris-base: sidebar is now overlay, not in flow ── */
    .hris-app .main {
      margin-left: 0 !important;
      width: 100% !important;
      max-width: 100vw !important;
      overflow-x: hidden;
    }

    .hris-app .content {
      padding: 0 !important;
      max-width: 100% !important;
      margin: 0 !important;
      overflow-x: hidden;
    }

    html,
    body {
      overflow-x: hidden;
    }

    /* =========================================
   ADMIN SIDEBAR - RESPONSIVE OVERLAY FIX
========================================= */

    .sidebar {
      position: fixed !important;
      top: 0;
      left: 0;
      bottom: 0;
      width: min(var(--sidebar-width), 85vw);
      height: 100dvh;
      overflow-y: auto;
      overflow-x: hidden;
      z-index: 300;
      transition: transform 0.28s cubic-bezier(.4, 0, .2, 1);
    }

    .sidebar.hidden {
      transform: translateX(-100%);
    }

    .sidebar:not(.hidden) {
      transform: translateX(0);
    }

    .sidebar-backdrop {
      position: fixed;
      inset: 0;
      z-index: 299;
    }

    .main {
      margin-left: 0 !important;
      width: 100%;
      min-width: 0;
    }

    .topbar {
      width: 100%;
      min-width: 0;
      flex-wrap: wrap;
      gap: 10px;
    }

    .topbar-title {
      min-width: 0;
    }

    .topbar-actions {
      min-width: 0;
    }

    .content {
      min-width: 0;
      width: 100%;
    }

    @media (max-width: 760px) {
      .sidebar {
        position: fixed !important;
        width: min(264px, 85vw);
        height: 100dvh;
        padding: 20px 14px;
      }

      .nav-list {
        display: grid;
        overflow: visible;
      }

      .sidebar-footer {
        display: block;
      }

      .topbar {
        padding: 12px 16px;
      }

      .topbar-actions {
        display: none;
      }
    }

    html,
    body {
      max-width: 100%;
      overflow-x: hidden;
    }

    html,
    body,
    .content,
    .sidebar {
      scrollbar-width: none;
      -ms-overflow-style: none;
    }

    html::-webkit-scrollbar,
    body::-webkit-scrollbar,
    .content::-webkit-scrollbar,
    .sidebar::-webkit-scrollbar {
      display: none;
    }
  </style>
</head>

<body class="hris-app admin-app page-<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>">

  <!-- Backdrop -->
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <!-- Sidebar -->
  <aside class="sidebar hidden" id="adminSidebar">
    <a class="logo-group" href="/admin/users.php" aria-label="Admin console">
      <span class="logo-mark">A</span>
      <span class="logo-text"><strong>HRIS</strong><small>System Admin</small></span>
    </a>

    <nav class="nav-list" aria-label="Main navigation">
      <?php foreach ($navItems as [$href, $label, $icon, $pages, $visible]): ?>
        <?php if ($visible): ?>
          <a class="nav-item <?= in_array($currentPage, $pages, true) ? 'active' : '' ?>"
            href="<?= $href ?>">
            <span class="nav-icon"><?= $icon ?></span>
            <span><?= $label ?></span>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <div class="footer-line"></div>
      <div class="admin-profile">
        <span class="avatar">
          <?= strtoupper(substr($navUserName, 0, 2)) ?>
        </span>

        <span class="admin-info">
          <strong>
            <?= htmlspecialchars($navUserName, ENT_QUOTES, 'UTF-8') ?>
          </strong>

          <span>
            <?= htmlspecialchars($navRoleLabel, ENT_QUOTES, 'UTF-8') ?>
          </span>
        </span>
      </div>
      <a class="logout-btn" href="/authentication/logout.php">
        <svg class="logout-ico" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span>Log Out</span>
      </a>
    </div>
  </aside>

  <!-- Main -->
  <main class="main">
    <header class="topbar">
      <div class="topbar-title">
        <div class="title-row">
          <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
            <span class="hb-line"></span>
            <span class="hb-line"></span>
            <span class="hb-line"></span>
          </button>
          <strong><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
      </div>
      <div class="topbar-actions">
        <span class="system-status">
          <span class="status-dot"></span> Admin Console
        </span>
      </div>
    </header>
    <section class="content">

      <script>
        const sidebar = document.getElementById('adminSidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const toggleBtn = document.getElementById('sidebarToggle');

        toggleBtn.addEventListener('click', () => {
          const isHidden = sidebar.classList.toggle('hidden');
          backdrop.classList.toggle('active', !isHidden);
        });

        backdrop.addEventListener('click', () => {
          sidebar.classList.add('hidden');
          backdrop.classList.remove('active');
        });
      </script>