<?php
// admin/backup/create.php

require_once __DIR__ . '/../../classes/BackupAuth.php';

// Only allow POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// Validate the CSRF token.
csrf_check($account['role']);

// Shared request directory.
$requestDir = '/requests';
$resultDir = '/requests';

// Redirect helper with a session message.
function backupRedirect(string $type, string $message, ?string $filename = null): never
{
    $_SESSION['backup_flash'] = [
        'type' => $type,
        'message' => $message,
        'filename' => $filename,
    ];

    header('Location: /admin/backup_management.php');
    exit;
}

// Check that the shared directory is accessible.
if (!is_dir($requestDir) || !is_writable($requestDir)) {
    error_log('Backup request directory is unavailable.');
    backupRedirect('error', 'Backup service is currently unavailable.');
}

// Generate a UUID v4 request ID.
$bytes = random_bytes(16);
$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

$hex = bin2hex($bytes);

$requestId = sprintf(
    '%s-%s-%s-%s-%s',
    substr($hex, 0, 8),
    substr($hex, 8, 4),
    substr($hex, 12, 4),
    substr($hex, 16, 4),
    substr($hex, 20, 12)
);

$requestFile = $requestDir . '/' . $requestId . '.request';
$resultFile = $resultDir . '/' . $requestId . '.result';

// Create the request without overwriting an existing file.
$handle = @fopen($requestFile, 'x');

if ($handle === false) {
    error_log('Could not create backup request: ' . $requestId);
    backupRedirect('error', 'Could not submit the backup request.');
}

fwrite($handle, 'requested_at=' . date('Y-m-d H:i:s') . PHP_EOL);
fclose($handle);

// Wait up to 60 seconds for the backup container to process it.
$timeoutSeconds = 60;
$startedAt = time();
$result = null;

while ((time() - $startedAt) < $timeoutSeconds) {
    if (is_file($resultFile)) {
        $result = parse_ini_file($resultFile, false, INI_SCANNER_RAW);
        break;
    }

    sleep(1);
}

// The backup may still be running.
if (!is_array($result)) {
    backupRedirect(
        'pending',
        'Your backup request was submitted. The backup is still processing.'
    );
}

// Check the result.
$status = $result['status'] ?? '';
$filename = $result['filename'] ?? '';

if ($status === 'success') {

    // Accept only filenames matching our backup naming convention.
    if (!preg_match(
        '/^hris_backup_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/',
        $filename
    )) {
        error_log('Invalid backup filename returned for request: ' . $requestId);
        backupRedirect('error', 'Backup completed, but its filename could not be verified.');
    }

    backupRedirect(
        'success',
        'Database backup created successfully.',
        $filename
    );
}

backupRedirect('error', 'Database backup failed. Check the backup container logs.');
