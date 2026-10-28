<?php
// Shared HRIS-inspired shell. This file only renders navigation and styles;
// the HR pages retain their existing request handling and database logic.
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php', '.php');
$pageTitles = [
 'dashboard' => 'HR Dashboard', 'employees' => 'Employee Management',
 'add_employee' => 'Add Employee', 'edit_employee' => 'Edit Employee',
 'view_employee' => 'Employee Profile', 'department' => 'Department Management',
 'position' => 'Position Management', 'leave_management' => 'Leave Management',
 'report' => 'Reports & Audits'
];
$pageTitle = $pageTitles[$currentPage] ?? 'Human Resources';
$cssPage = preg_replace('/[^a-z0-9_-]/', '', $currentPage);
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
<link rel="stylesheet" href="../assets/css/pages/<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>.css">
</head>
<body class="hris-app page-<?= htmlspecialchars($cssPage, ENT_QUOTES, 'UTF-8') ?>">
<aside class="sidebar">
  <a class="logo-group" href="/hr/dashboard.php" aria-label="HRIS dashboard">
    <span class="logo-mark">H</span><span class="logo-text"><strong>HRIS</strong><small>Admin Control</small></span>
  </a>
  <nav class="nav-list" aria-label="Main navigation">
    <a class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>" href="/hr/dashboard.php"><span class="nav-icon">▦</span><span>Dashboard</span></a>
    <a class="nav-item <?= in_array($currentPage, ['employees','add_employee','edit_employee','view_employee','employee_deactivate'], true) ? 'active' : '' ?>" href="/hr/employees.php"><span class="nav-icon">♙</span><span>Employees</span></a>
    <a class="nav-item <?= $currentPage === 'leave_management' ? 'active' : '' ?>" href="/hr/leave_management.php"><span class="nav-icon">◷</span><span>Leave Management</span></a>
    <a class="nav-item <?= $currentPage === 'department' ? 'active' : '' ?>" href="/hr/department.php"><span class="nav-icon">▤</span><span>Departments</span></a>
    <a class="nav-item <?= $currentPage === 'position' ? 'active' : '' ?>" href="/hr/position.php"><span class="nav-icon">▣</span><span>Positions</span></a>
    <a class="nav-item <?= $currentPage === 'report' ? 'active' : '' ?>" href="/hr/report.php"><span class="nav-icon">▥</span><span>Reports &amp; Audits</span></a>
  </nav>
  <div class="sidebar-footer"><div class="footer-line"></div><div class="admin-profile"><span class="avatar">HR</span><span class="admin-info"><strong>HR Administration</strong><span>Human Resources</span></span></div></div>
</aside>
<main class="main">
  <header class="topbar">
    <div class="topbar-title"><span class="eyebrow">HUMAN RESOURCES</span><strong><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div class="topbar-actions"><span class="system-status"><span class="status-dot"></span> HR Workspace</span></div>
  </header>
  <section class="content">
