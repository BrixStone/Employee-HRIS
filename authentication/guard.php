<?php
// authentication/guard.php
// IMPORTANT: Save this file as UTF-8 (without BOM).
// Do not put spaces, blank lines, or HTML before this opening PHP tag.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function require_role(array $allowed): void
{
    if (empty($_SESSION['role'])) {
        header('Location: /authentication/login.php');
        exit;
    }

    if (!in_array($_SESSION['role'], $allowed, true)) {
        http_response_code(403);
        exit('403 Forbidden — you do not have permission to access this page.');
    }
}

function current_role()
{
    return $_SESSION['role'] ?? null;
}

function current_user_id()
{
    return $_SESSION['user_id'] ?? null;
}

function current_employee_id()
{
    return $_SESSION['employee_id'] ?? null;
}
