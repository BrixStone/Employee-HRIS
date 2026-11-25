<?php
// classes/BackupAuth.php
// Authentication and authorization for all backup endpoints.

require_once __DIR__ . '/../authentication/guard.php';

// Verify the logged-in account against the database.
$account = require_access();

// Only administrators (Super Admins for now) can manage backups.
if (!Rbac::canManageBackups($account['role'] ?? '')) {
    guard_forbidden(
        $account['role'] ?? null,
        'Only the Super Admin can access Backup Management.'
    );
}
