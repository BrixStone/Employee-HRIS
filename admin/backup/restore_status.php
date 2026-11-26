<?php
// admin/backup/restore_status.php

// Start or resume the administrator's PHP session.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Load RBAC without invoking database-backed authentication.
require_once __DIR__ . '/../../classes/Rbac.php';

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

// Check that a user is logged in.
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    !is_scalar($_SESSION['user_id']) ||
    !is_string($_SESSION['role'])
) {
    restoreStatusResponse(401, [
        'success' => false,
        'message' => 'Your session has expired. Please log in again.',
    ]);
}

// Only administrators allowed to manage backups can check restore status.
if (!Rbac::canManageBackups($_SESSION['role'])) {
    restoreStatusResponse(403, [
        'success' => false,
        'message' => 'You are not authorized to access restore status.',
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

// Verify that this restore request belongs to the current session.
$ownerFile = $requestDir . '/' . $requestId . '.owner';

if (!is_file($ownerFile) || !is_readable($ownerFile)) {
    restoreStatusResponse(404, [
        'success' => false,
        'message' => 'Restore request not found.',
    ]);
}

$ownerJson = file_get_contents($ownerFile);

if ($ownerJson === false) {
    restoreStatusResponse(503, [
        'success' => false,
        'message' => 'Could not verify restore request ownership.',
    ]);
}

$owner = json_decode($ownerJson, true);

if (
    !is_array($owner) ||
    !isset(
        $owner['user_id'],
        $owner['role'],
        $owner['session_hash']
    ) ||
    !is_string($owner['user_id']) ||
    !is_string($owner['role']) ||
    !is_string($owner['session_hash'])
) {
    restoreStatusResponse(403, [
        'success' => false,
        'message' => 'Restore request ownership could not be verified.',
    ]);
}

// Compare the owner information against the current session.
$currentUserId = (string) $_SESSION['user_id'];
$currentRole = $_SESSION['role'];
$currentSessionHash = hash('sha256', session_id());

if (
    !hash_equals($owner['user_id'], $currentUserId) ||
    !hash_equals($owner['role'], $currentRole) ||
    !hash_equals($owner['session_hash'], $currentSessionHash)
) {
    restoreStatusResponse(403, [
        'success' => false,
        'message' => 'You are not authorized to access this restore request.',
    ]);
}

// Ownership is verified. It is now safe to read the worker's files.
$progressFile = $requestDir . '/' . $requestId . '.progress';
$resultFile = $requestDir . '/' . $requestId . '.result';

// Read the worker's progress file.
$progress = null;

if (is_file($progressFile) && is_readable($progressFile)) {
    $progress = @parse_ini_file(
        $progressFile,
        false,
        INI_SCANNER_RAW
    );

    if (!is_array($progress)) {
        $progress = null;
    }
}

// Read the final result if available.
$result = null;

if (is_file($resultFile) && is_readable($resultFile)) {
    $result = @parse_ini_file(
        $resultFile,
        false,
        INI_SCANNER_RAW
    );

    if (!is_array($result)) {
        $result = null;
    }
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
if (is_array($result)) {
    $status = $result['status'] ?? $status;
    $message = $result['message'] ?? $message;

    $stage = $status === 'success'
        ? 'completed'
        : 'failed';

    $updatedAt = $result['completed_at'] ?? $updatedAt;
}

// Return the status to the frontend.
restoreStatusResponse(200, [
    'success' => true,
    'request_id' => $requestId,
    'status' => $status,
    'stage' => $stage,
    'message' => $message,
    'updated_at' => $updatedAt,
    'completed_at' => $result['completed_at'] ?? null,
]);
