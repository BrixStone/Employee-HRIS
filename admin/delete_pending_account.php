<?php
// admin/delete_pending_account.php

require_once __DIR__ . '/../authentication/guard.php';


$account = require_access(
    'user_management',
    'edit',
    'all'
);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/AuditLogger.php';


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/users.php');
    exit;
}

csrf_check($account['role']);

$userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

if (!$userId || $userId <= 0) {
    $_SESSION['user_management_error'] =
        'Invalid account selected.';
    header('Location: /admin/users.php');
    exit;
}

try {
    $userModel = new User($pdo);

    if ($userModel->deletePendingAccount($userId)) {

    AuditLogger::log(
        'user.delete_pending',
        'user',
        $userId,
        [
            'deleted_by' => $account['user_id']
        ]
    );

    $_SESSION['user_management_message'] =
        'Pending account and its invitation tokens were successfully deleted.';
    } else {
        $_SESSION['user_management_error'] =
            'Account was not deleted. It may already be completed or no longer exist.';
    }

} catch (Throwable $e) {

    error_log(
        'Delete pending account error: ' . $e->getMessage()
    );

    $_SESSION['user_management_error'] =
        'Could not delete the pending account. Please check the server logs.';
}

header('Location: /admin/users.php');
exit;
