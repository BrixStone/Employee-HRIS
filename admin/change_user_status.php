<?php
// admin/change_user_status.php

require_once __DIR__ . '/../authentication/guard.php';

$account = require_access(
    'user_management',
    'edit',
    'all'
);

require_once __DIR__ . '/../classes/AuditLogger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/users.php');
    exit;
}

// Only administrators can activate or deactivate accounts.
if (Rbac::normalizeRole($account['role']) !== 'admin') {
    guard_forbidden($account['role']);
}

csrf_check($account['role']);

$userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';

if (!$userId || $userId <= 0) {
    $_SESSION['user_management_error'] = 'Invalid account selected.';
    header('Location: /admin/users.php');
    exit;
}

if (!in_array($action, ['deactivate', 'activate'], true)) {
    $_SESSION['user_management_error'] = 'Invalid account action.';
    header('Location: /admin/users.php');
    exit;
}

if ($userId === (int)$account['user_id']) {
    $_SESSION['user_management_error'] =
        'You cannot change your own account status.';
    header('Location: /admin/users.php');
    exit;
}

$newStatus = $action === 'deactivate' ? 'Inactive' : 'Active';

try {
    $pdo->beginTransaction();

    // Lock the target account while processing the change.
    $stmt = $pdo->prepare(
        "SELECT id, username, role, status, setup_completed
         FROM users
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute(['id' => $userId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        throw new RuntimeException('That account no longer exists.');
    }

    // Pending accounts must use the invitation workflow.
    if ((int)$target['setup_completed'] !== 1) {
        throw new RuntimeException(
            'Pending setup accounts cannot be activated or deactivated here.'
        );
    }

    $oldStatus = (string)$target['status'];

    if ($oldStatus === $newStatus) {
        throw new RuntimeException(
            "This account is already {$newStatus}."
        );
    }

    // Never allow the system to have zero active administrators.
    $isActiveAdmin =
        Rbac::normalizeRole($target['role']) === 'admin'
        && strcasecmp($oldStatus, 'Active') === 0;

    if ($isActiveAdmin && $action === 'deactivate') {
        $adminStmt = $pdo->query(
            "SELECT id
             FROM users
             WHERE LOWER(role) = 'admin'
               AND status = 'Active'
             FOR UPDATE"
        );

        $activeAdmins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($activeAdmins) <= 1) {
            throw new RuntimeException(
                'This is the last active administrator. Activate another administrator first.'
            );
        }
    }

    $update = $pdo->prepare(
        "UPDATE users
         SET status = :status,
             updated_at = NOW()
         WHERE id = :id"
    );

    $update->execute([
        'status' => $newStatus,
        'id' => $userId
    ]);

    $pdo->commit();

    AuditLogger::log(
        'user.' . $action,
        'user',
        $userId,
        [
            'username' => $target['username'],
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]
    );

    $_SESSION['user_management_message'] =
        "Account {$target['username']} was successfully {$action}d.";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e instanceof RuntimeException) {
        $_SESSION['user_management_error'] = $e->getMessage();
    } else {
        error_log('Change user status error: ' . $e->getMessage());

        $_SESSION['user_management_error'] =
            'Could not update the account status. Please check the server logs.';
    }
}

header('Location: /admin/users.php');
exit;
