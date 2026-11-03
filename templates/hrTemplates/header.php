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

<body>

<header>
    <h1>HR Dashboard</h1>

    <nav>
        <ul>
            <li>
                <a href="../hr/employees.php">Employee Management</a>
            </li>

            <li>
                <a href="../hr/department.php">Department Management</a>
            </li>

            <li>
                <a href="../hr/leave_management.php">Leave Management</a>
            </li>

            <li><a href="/hr/report.php">Reports</a></li>

             
            <li>
                <a href="/authentication/logout.php">Sign out</a>
            </li>

            
        </ul>
    </nav>
</header>

<main>
