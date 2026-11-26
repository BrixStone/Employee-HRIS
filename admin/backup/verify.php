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

// Send a JSON response to JavaScript.
function verifyResponse(
    bool $success,
    string $message,
    int $statusCode = 200
): never {

    http_response_code($statusCode);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => $success,
        'message' => $message,
    ]);

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
    verifyResponse(false, 'Invalid backup filename.', 422);
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
    verifyResponse(false, 'Backup file not found.', 404);
}

// Open gzip archive.
$handle = @gzopen($backupPath, 'rb');

if ($handle === false) {
    verifyResponse(false, 'Backup archive could not be opened.', 422);
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

// Check archive integrity result.
if (!$valid) {
    verifyResponse(false, 'Backup archive verification failed.', 422);
}

// Archive passed gzip integrity check.
verifyResponse(
    true,
    'Backup archive passed gzip integrity verification: ' . $filename
);
