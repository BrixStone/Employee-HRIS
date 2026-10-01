<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($title ?? 'Dashboard') ?></title>
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
                <a href="../hr/leave_request.php">Leave Request</a>
            </li>

            <li>
                <a href="../hr/report.php">Report</a>
            </li>
        </ul>
    </nav>
</header>

<main>
