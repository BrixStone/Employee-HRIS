<?php
// admin/backup/restore_test.php

require_once __DIR__ . '/../../classes/BackupAuth.php';

// Only allow POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// Validate CSRF token.
csrf_check($account['role']);

// Allow enough time for a database import.
set_time_limit(240);

// Redirect helper.
function restoreTestRedirect(string $type, string $message): never
{
    $_SESSION['backup_flash'] = [
        'type' => $type,
        'message' => $message,
    ];

    header('Location: /admin/backup_management');
    exit;
}

// Validate the selected filename.
$filename = $_POST['filename'] ?? '';

if (
    !is_string($filename) ||
    !preg_match(
        '/^hris_backup_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/',
        $filename
    )
) {
    restoreTestRedirect('error', 'Invalid backup filename.');
}

// Verify the selected backup exists.
$backupDirectory = realpath('/backups');
$backupPath = realpath('/backups/' . $filename);

if (
    $backupDirectory === false ||
    $backupPath === false ||
    dirname($backupPath) !== $backupDirectory ||
    !is_file($backupPath) ||
    !is_readable($backupPath)
) {
    restoreTestRedirect('error', 'Backup file not found.');
}

// Shared request directory.
$requestDir = '/requests';

if (!is_dir($requestDir) || !is_writable($requestDir)) {
    error_log('Restore-test request directory is unavailable.');
    restoreTestRedirect('error', 'Restore-test service is unavailable.');
}

// Generate UUID v4.
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

$requestFile = $requestDir . '/' . $requestId . '.restore-test.request';
$resultFile = $requestDir . '/' . $requestId . '.result';

// Submit the restore-test request.
$handle = @fopen($requestFile, 'x');

if ($handle === false) {
    error_log('Could not create restore-test request: ' . $requestId);
    restoreTestRedirect('error', 'Could not submit restore-test request.');
}

fwrite($handle, 'filename=' . $filename . PHP_EOL);
fclose($handle);

// Wait up to 180 seconds for the backup container.
$timeoutSeconds = 180;
$startedAt = time();
$result = null;

while ((time() - $startedAt) < $timeoutSeconds) {

    clearstatcache(true, $resultFile);

    if (is_file($resultFile)) {
        $parsedResult = @parse_ini_file(
            $resultFile,
            false,
            INI_SCANNER_RAW
        );

        if (is_array($parsedResult)) {
            $result = $parsedResult;
            break;
        }
    }

    sleep(1);
}

// The test may still be running.
if (!is_array($result)) {
    restoreTestRedirect(
        'pending',
        'Restore test submitted. Processing is taking longer than expected. Check the backup container logs.'
    );
}

// Validate returned result.
$status = $result['status'] ?? '';

if ($status === 'success') {
    restoreTestRedirect(
        'success',
        'Restore test PASSED. The backup was successfully imported into the temporary test database: ' . $filename
    );
}

restoreTestRedirect(
    'error',
    'Restore test FAILED. Check the backup container logs for details.'
);
