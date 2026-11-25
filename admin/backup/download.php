<?php
// admin/backup/download.php

require_once __DIR__ . '/../../classes/BackupAuth.php';

// Only allow GET requests.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method not allowed.');
}

// Get requested filename.
$filename = $_GET['file'] ?? '';

// Validate filename.
if (
    !is_string($filename) ||
    !preg_match(
        '/^(?:hris_backup|hris_pre_restore)_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.sql\.gz$/',
        $filename
    )
) {
    http_response_code(400);
    exit('Invalid backup filename.');
}

// Resolve backup directory and file.
$backupDirectory = realpath('/backups');
$backupPath = realpath('/backups/' . $filename);

// Verify file location and permissions.
if (
    $backupDirectory === false ||
    $backupPath === false ||
    dirname($backupPath) !== $backupDirectory ||
    !is_file($backupPath) ||
    !is_readable($backupPath)
) {
    http_response_code(404);
    exit('Backup file not found.');
}

// Send file to browser.
header('Content-Type: application/gzip');
header(
    'Content-Disposition: attachment; filename="' . $filename . '"'
);
header('Content-Length: ' . filesize($backupPath));
header('X-Content-Type-Options: nosniff');

readfile($backupPath);
exit;
