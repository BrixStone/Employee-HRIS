<?php
// admin/backup/verify.php

require_once __DIR__ . '/../../classes/BackupAuth.php';

// Only allow POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// Validate CSRF token.
csrf_check($account['role']);

// Redirect helper.
function verifyRedirect(string $type, string $message): never
{
    $_SESSION['backup_flash'] = [
        'type' => $type,
        'message' => $message,
    ];

    header('Location: /admin/backup_management');
    exit;
}

// Get filename.
$filename = $_POST['filename'] ?? '';

// Validate filename.
if (
    !is_string($filename) ||
    !preg_match(
        '/^hris_backup_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/',
        $filename
    )
) {
    verifyRedirect('error', 'Invalid backup filename.');
}

// Resolve backup file.
$backupDirectory = realpath('/backups');
$backupPath = realpath('/backups/' . $filename);

// Check file.
if (
    $backupDirectory === false ||
    $backupPath === false ||
    dirname($backupPath) !== $backupDirectory ||
    !is_file($backupPath) ||
    !is_readable($backupPath)
) {
    verifyRedirect('error', 'Backup file not found.');
}

// Open gzip archive.
$handle = @gzopen($backupPath, 'rb');

if ($handle === false) {
    verifyRedirect('error', 'Backup archive could not be opened.');
}

// Read the complete compressed stream.
$valid = true;

while (!gzeof($handle)) {
    $data = @gzread($handle, 1024 * 1024);

    if ($data === false) {
        $valid = false;
        break;
    }

    if ($data === '' && !gzeof($handle)) {
        $valid = false;
        break;
    }
}

gzclose($handle);

if (!$valid) {
    verifyRedirect('error', 'Backup archive verification failed.');
}

// Archive passed gzip integrity check.
verifyRedirect(
    'success',
    'Backup archive passed gzip integrity verification: ' . $filename
);
