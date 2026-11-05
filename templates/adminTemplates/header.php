<?php
// Shared ADMIN shell. Separate from the HR workspace: the admin is a
// distinct role/user, so it gets its own navigation, branding, and workspace
// badge. It reuses the shared Moonlit design system (assets/css/hris-base.css)
// only for the look-and-feel — not the HR navigation.
//
// This file only renders navigation and styles; each admin page keeps its own
// request handling and database logic.
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'users.php', '.php');

$pageTitles = [
    'users'       => 'User Management',
    'create_user' => 'Create User',
];
$pageTitle = $pageTitles[$currentPage] ?? 'Administration';
$cssPage = preg_replace('/[^a-z0-9_-]/', '', $currentPage);
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
    <a class="nav-item <?= $currentPage === 'users' ? 'active' : '' ?>" href="/admin/users.php"><span class="nav-icon">◉</span><span>User Management</span></a>
    <a class="nav-item <?= $currentPage === 'create_user' ? 'active' : '' ?>" href="/admin/create_user.php"><span class="nav-icon">＋</span><span>Create User</span></a>
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
