<?php
// admin/backup/restore.php

require_once __DIR__ . '/../../classes/BackupAuth.php';

// Return JSON responses.
function restoreResponse(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode($data);
    exit;
}

// Only allow POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    restoreResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
}

// Validate CSRF.
csrf_check($account['role']);

// Validate the selected backup filename.
$filename = $_POST['filename'] ?? '';

if (!is_string($filename) || !preg_match(
    '/^(?:hris_backup|hris_pre_restore)_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.sql\.gz$/',
    $filename
)) {
    restoreResponse(422, [
        'success' => false,
        'message' => 'Invalid backup filename.',
    ]);
}

// Confirm that the selected backup exists inside /backups.
$backupDir = '/backups';
$backupPath = $backupDir . '/' . $filename;

$realBackupDir = realpath($backupDir);
$realBackupPath = realpath($backupPath);

if (
    $realBackupDir === false ||
    $realBackupPath === false ||
    !is_file($realBackupPath) ||
    dirname($realBackupPath) !== $realBackupDir
) {
    restoreResponse(404, [
        'success' => false,
        'message' => 'The selected backup could not be found.',
    ]);
}

// Check the shared request directory.
$requestDir = '/requests';

if (!is_dir($requestDir) || !is_writable($requestDir)) {
    error_log('Live restore request directory is unavailable.');

    restoreResponse(503, [
        'success' => false,
        'message' => 'Backup service is currently unavailable.',
    ]);
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

// Create the live restore request.
$requestFile = $requestDir . '/' . $requestId . '.restore.request';

$handle = @fopen($requestFile, 'x');

if ($handle === false) {
    error_log('Could not create live restore request: ' . $requestId);

    restoreResponse(500, [
        'success' => false,
        'message' => 'Could not submit the restore request.',
    ]);
}

// Write only validated data into the request file.
$requestData =
    'filename=' . $filename . PHP_EOL .
    'requested_at=' . date('Y-m-d H:i:s') . PHP_EOL;

$written = fwrite($handle, $requestData);
$closed = fclose($handle);

if ($written === false || !$closed) {
    @unlink($requestFile);

    error_log('Could not write live restore request: ' . $requestId);

    restoreResponse(500, [
        'success' => false,
        'message' => 'Could not save the restore request.',
    ]);
}

// Tell the frontend to begin monitoring this request.
restoreResponse(202, [
    'success' => true,
    'request_id' => $requestId,
    'filename' => $filename,
    'message' => 'Live database restoration has been submitted.',
]);
