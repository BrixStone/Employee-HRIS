<?php
// Shared ADMIN shell. Separate from the HR workspace: the admin is a
// distinct role/user, so it gets its own navigation, branding, and workspace
// badge. It reuses the shared Moonlit design system (assets/css/hris-base.css)
// only for the look-and-feel — not the HR navigation.
//
// This file only renders navigation and styles; each admin page keeps its own
// request handling and database logic.
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'users.php', '.php');
require_once __DIR__ . '/../../classes/Rbac.php';
$navRole = Rbac::normalizeRole($_SESSION['role'] ?? '');
$navPdo  = (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
// Does this role hold $ability on $module, over everyone's data?
$navCan = static function (string $module, string $ability) use ($navPdo, $navRole): bool {
    return $navPdo !== null
        && Rbac::can($navPdo, $navRole, $module, $ability)
        && Rbac::scope($navPdo, $navRole, $module) === 'all';
};
// [link, label, icon, pages that highlight it, visible to this role?]
$navItems = [
    ['/admin/users.php', 'User Management', "\u{25C9}", ['users'], $navCan('user_management', 'view')],
    ['/admin/create_user.php', 'Create User', "\u{FF0B}", ['create_user'], $navCan('user_management', 'edit')],
    ['/admin/permissions.php', 'System Settings', "\u{2699}", ['permissions'], $navCan('system_settings', 'view')],
];

$pageTitles = [
    'users'       => 'User Management',
    'create_user' => 'Create User',
    'permissions' => 'System Settings',
];
$pageTitle = $pageTitles[$currentPage] ?? 'Administration';
$cssPage = $cssPageOverride ?? preg_replace('/[^a-z0-9_-]/', '', $currentPage);
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
<link rel="stylesheet" href="../assets/css/hris-base.css">
<link rel="stylesheet" href="../assets/css/pages/<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>.css">
<script src="../asset/js/page-transition.js" defer></script>
</head>
<body class="hris-app admin-app page-<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>">
<aside class="sidebar">
  <a class="logo-group" href="/admin/users.php" aria-label="Admin console">
    <span class="logo-mark">A</span><span class="logo-text"><strong>HRIS</strong><small>System Admin</small></span>
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
    <div class="admin-profile"><span class="avatar">AD</span><span class="admin-info"><strong>System Administrator</strong><span>Admin Console</span></span></div>
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
    <div class="topbar-title"><span class="eyebrow">ADMINISTRATION</span><strong><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div class="topbar-actions"><span class="system-status"><span class="status-dot"></span> Admin Console</span></div>
  </header>
  <section class="content">
