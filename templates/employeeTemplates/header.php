<?php
/**
 * Shared employee shell — mirrors the HR moonlit design system so the
 * employee portal matches the HR workspace exactly.
 *
 * Pages set these before requiring this file (all optional):
 *   $title        string  Page title shown in the browser tab + topbar.
 *   $eyebrow      string  Small uppercase label above the topbar title.
 *   $subtitle     string  Short line under the topbar title.
 *   $pageCss      string  Base name of a per-page stylesheet in assets/css/pages.
 *   $user         array   ['full_name','email','avatar'] for the sidebar footer.
 *   $realtime     bool    When true, renders the realtime status badge + script.
 *   $topbarExtra  string  Extra HTML injected into the topbar actions area.
 *
 * This file only renders navigation and styles; each page keeps its own
 * request handling and database logic.
 */
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php', '.php');

$pageTitles = [
    'dashboard'     => 'Employee Dashboard',
    'profile'       => 'My Profile',
    'attendance'    => 'Attendance',
    'file_leave'    => 'Leave Management',
    'leave_history' => 'Leave History',
    'leave_balance' => 'Leave Balance',
    'payslips'      => 'Payslips',
    'settings'      => 'Settings',
];
$pageTitle = $title ?? ($pageTitles[$currentPage] ?? 'Employee Portal');
$eyebrowText = $eyebrow ?? 'EMPLOYEE PORTAL';
$subtitleText = $subtitle ?? '';
$cssPage = preg_replace('/[^a-z0-9_-]/', '', $pageCss ?? $currentPage);

/*
 * Embedded mode: when a page is shown inside another page's iframe/panel
 * (e.g. Leave History inside the Leave Management slide-over), hide the
 * sidebar + topbar so the shell is not duplicated. A page opts in with
 * $embedded = true, or the request carries ?embedded=1.
 */
$embedded = !empty($embedded) || (($_GET['embedded'] ?? '') === '1');

$navUser = $user ?? [];
$navName = $navUser['full_name'] ?? ($navUser['name'] ?? 'Employee');
$navEmail = $navUser['email'] ?? '';
$navAvatar = $navUser['avatar'] ?? ('https://ui-avatars.com/api/?name=' . urlencode($navName) . '&background=4763ff&color=fff');
$navInitials = strtoupper(mb_substr($navName, 0, 2));

/*
 * Pocket Profile data. So the "About Me" pop-up shows the SAME full details
 * (ID, name, email, phone, status) on EVERY page — not just the profile page —
 * fetch the employee record here when the page didn't already provide it.
 */
if (!isset($pocketProfile) || !is_array($pocketProfile)) {
    $pocketProfile = [];
    $__empId = $_SESSION['employee_id'] ?? null;
    if ($__empId && isset($pdo) && $pdo instanceof PDO) {
        try {
            require_once __DIR__ . '/../../classes/Avatar.php';
            Avatar::ensureColumn($pdo);
            $__ps = $pdo->prepare(
                'SELECT id, first_name, last_name, email, phone, employment_status, avatar
                 FROM employees WHERE id = :id LIMIT 1'
            );
            $__ps->execute([':id' => $__empId]);
            $__emp = $__ps->fetch(PDO::FETCH_ASSOC);
            if ($__emp) {
                $__full = trim(($__emp['first_name'] ?? '') . ' ' . ($__emp['last_name'] ?? ''));
                $__avatarUrl = Avatar::url($__emp);
                $pocketProfile = [
                    'id'     => $__emp['id'],
                    'name'   => $__full,
                    'email'  => $__emp['email'] ?? $navEmail,
                    'phone'  => $__emp['phone'] ?? 'Not Provided',
                    'status' => $__emp['employment_status'] ?? 'Active',
                    'avatar' => $__avatarUrl,
                ];
                // Sidebar avatar shows the uploaded photo too.
                $navAvatar = $__avatarUrl;
                // Keep the sidebar identity in sync if the page didn't set it.
                if (($navUser['full_name'] ?? '') === '' && $__full !== '') {
                    $navName = $__full;
                    $navInitials = strtoupper(mb_substr($__full, 0, 2));
                }
                if ($navEmail === '') {
                    $navEmail = $__emp['email'] ?? '';
                }
            }
        } catch (Throwable $e) {
            // Non-fatal: pocket profile just falls back to name/email only.
        }
    }
}

// Leave pages share one "active" state in the sidebar.
$leavePages = ['file_leave', 'leave_history', 'leave_balance'];

function emp_nav_active($page, $current): string
{
    if (is_array($page)) {
        return in_array($current, $page, true) ? 'active' : '';
    }
    return $page === $current ? 'active' : '';
}
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
<link rel="stylesheet" href="../assets/css/hris-base.css">
<!-- Pocket Profile modal styles loaded in <head> so the overlay is hidden on
     first paint (prevents a flash of the modal during page load/navigation). -->
<link rel="stylesheet" href="../assets/css/pocket-profile.css">
<link rel="stylesheet" href="../assets/css/welcome.css">
<?php if ($cssPage !== ''): ?>
<link rel="stylesheet" href="../assets/css/pages/<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>.css">
<?php endif; ?>
</head>
<body class="hris-app page-<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?><?= $embedded ? ' is-embedded' : '' ?>">
<?php if (!$embedded): ?>
<aside class="sidebar">
  <a class="logo-group" href="/employee/dashboard.php" aria-label="Employee dashboard">
    <span class="logo-mark">H</span><span class="logo-text"><strong>HRIS</strong><small>Employee Portal</small></span>
  </a>
  <nav class="nav-list" aria-label="Main navigation">
    <a class="nav-item <?= emp_nav_active('dashboard', $currentPage) ?>" href="/employee/dashboard.php"><span class="nav-icon">▦</span><span>Dashboard</span></a>
    <a class="nav-item <?= emp_nav_active('profile', $currentPage) ?>" href="/employee/profile.php"><span class="nav-icon">◉</span><span>My Profile</span></a>
    <a class="nav-item <?= emp_nav_active('attendance', $currentPage) ?>" href="/employee/attendance.php"><span class="nav-icon">◷</span><span>Attendance</span></a>
    <a class="nav-item <?= emp_nav_active($leavePages, $currentPage) ?>" href="/employee/file_leave.php"><span class="nav-icon">▤</span><span>Leave</span></a>
    <a class="nav-item <?= emp_nav_active('payslips', $currentPage) ?>" href="/employee/payslips.php"><span class="nav-icon">▦</span><span>Payslips</span></a>
    <a class="nav-item <?= emp_nav_active('settings', $currentPage) ?>" href="/employee/settings.php"><span class="nav-icon">⚙</span><span>Settings</span></a>
  </nav>
  <div class="sidebar-footer">
    <div class="footer-line"></div>
    <?php
      // On the profile page this card opens the Pocket Profile modal (wired by
      // profile.php). On every other page it links to the profile page.
      $isProfilePage = ($currentPage === 'profile');
    ?>
    <?php
      // Show the uploaded photo in the sidebar when one exists, else initials.
      $sidebarAvatar = $pocketProfile['avatar'] ?? ($navAvatar ?? '');
      $sidebarHasPhoto = $sidebarAvatar !== '' && strpos($sidebarAvatar, '/uploads/avatars/') !== false;
    ?>
    <div class="admin-profile"
         id="sidebarProfile"
         role="button"
         tabindex="0"
         data-pocket-trigger="1"
         title="View your pocket profile">
      <?php if ($sidebarHasPhoto): ?>
        <span class="avatar avatar-img"><img src="<?= htmlspecialchars($sidebarAvatar, ENT_QUOTES, 'UTF-8') ?>" alt=""></span>
      <?php else: ?>
        <span class="avatar"><?= htmlspecialchars($navInitials, ENT_QUOTES, 'UTF-8') ?></span>
      <?php endif; ?>
      <span class="admin-info">
        <strong><?= htmlspecialchars($navName, ENT_QUOTES, 'UTF-8') ?></strong>
        <span><?= htmlspecialchars($navEmail, ENT_QUOTES, 'UTF-8') ?></span>
      </span>
    </div>
    <a class="logout-btn" href="/authentication/logout.php">
      <svg class="logout-ico" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <span>Log Out</span>
    </a>
  </div>
</aside>
<?php endif; ?>
<main class="main">
  <?php if (!$embedded): ?>
  <header class="topbar">
    <div class="topbar-title">
      <span class="eyebrow"><?= htmlspecialchars($eyebrowText, ENT_QUOTES, 'UTF-8') ?></span>
      <strong><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></strong>
      <?php if ($subtitleText !== ''): ?><span class="muted" style="font-size:12px"><?= htmlspecialchars($subtitleText, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
    </div>
    <div class="topbar-actions">
      <span id="live-datetime" class="system-status" style="font-variant-numeric:tabular-nums"></span>
      <?php if (!empty($realtime)): ?>
        <span class="system-status" data-realtime-status><span class="status-dot" style="background:var(--muted);box-shadow:none"></span> Offline</span>
      <?php endif; ?>
      <?= $topbarExtra ?? '' ?>
    </div>
  </header>
  <?php endif; ?>
  <section class="content">
