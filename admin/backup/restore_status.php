<?php
// admin/backup/restore_status.php

require_once __DIR__ . '/../../classes/BackupAuth.php';

// Return a JSON response.
function restoreStatusResponse(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode($data);
    exit;
}

// Only allow GET requests.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    restoreStatusResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
}

// Validate the request ID.
$requestId = $_GET['request_id'] ?? '';

if (
    !is_string($requestId) ||
    !preg_match(
        '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
        $requestId
    )
) {
    restoreStatusResponse(422, [
        'success' => false,
        'message' => 'Invalid restore request ID.',
    ]);
}

// Shared request directory.
$requestDir = '/requests';

if (!is_dir($requestDir) || !is_readable($requestDir)) {
    restoreStatusResponse(503, [
        'success' => false,
        'message' => 'Restore status service is unavailable.',
    ]);
}

$progressFile = $requestDir . '/' . $requestId . '.progress';
$resultFile = $requestDir . '/' . $requestId . '.result';

// Read the worker's progress file.
$progress = null;

if (is_file($progressFile) && is_readable($progressFile)) {
    $progress = parse_ini_file(
        $progressFile,
        false,
        INI_SCANNER_RAW
    );
}

// Read the final result if available.
$result = null;

if (is_file($resultFile) && is_readable($resultFile)) {
    $result = parse_ini_file(
        $resultFile,
        false,
        INI_SCANNER_RAW
    );
}

// If neither file exists, the worker may not have picked up the request yet.
if (!is_array($progress) && !is_array($result)) {
    restoreStatusResponse(202, [
        'success' => true,
        'request_id' => $requestId,
        'status' => 'pending',
        'stage' => 'queued',
        'message' => 'Your restore request is waiting to be processed.',
    ]);
}

// Prefer the progress file for the current stage.
$status = $progress['status'] ?? 'running';
$stage = $progress['stage'] ?? 'unknown';
$message = $progress['message'] ?? 'Restore operation is processing.';
$updatedAt = $progress['updated_at'] ?? null;

// Use the result file as the authoritative final result.
// Use the result file as the authoritative final result.
if (is_array($result)) {
    $status = $result['status'] ?? $status;
    $message = $result['message'] ?? $message;

    $stage = $status === 'success'
        ? 'completed'
        : 'failed';

    $updatedAt = $result['completed_at'] ?? $updatedAt;
}

restoreStatusResponse(200, [
    'success' => true,
    'request_id' => $requestId,
    'status' => $status,
    'stage' => $stage,
    'message' => $message,
    'updated_at' => $updatedAt,
    'completed_at' => $result['completed_at'] ?? null,
]);
