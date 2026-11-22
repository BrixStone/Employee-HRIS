<?php

require_once __DIR__ . '/../authentication/guard.php';
require_once __DIR__ . '/../classes/Mailer.php';
require_once __DIR__ . '/../classes/AuditLogger.php';


$account = require_access(
    'user_management',
    'edit',
    'all'
);

if (Rbac::normalizeRole($account['role']) !== 'admin') {
    guard_forbidden($account['role']);
}

require_once __DIR__ . '/../classes/User.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/users.php');
    exit;
}

csrf_check($account['role']);

$userId = (int)($_POST['user_id'] ?? 0);

if ($userId <= 0) {
    $_SESSION['user_error'] = 'Invalid account selected.';
    header('Location: /admin/users.php');
    exit;
}

try {

    $userModel = new User($pdo);

    $sent = $userModel->resendSetupInvitation($userId);

    if ($sent) {

    AuditLogger::log(
        'user.invitation_resent',
        'user',
        $userId,
        [
            'performed_by' => $account['user_id']
        ]
    );

    $_SESSION['user_message'] =
        'A new password setup invitation was successfully sent.';
    } else {
        $_SESSION['user_error'] =
    'The invitation could not be sent. The account may not be pending, the resend cooldown may still be active, or the email configuration may need attention.';
    }

} catch (Throwable $e) {

    error_log(
        'Resend invitation error: ' . $e->getMessage()
    );

    $_SESSION['user_error'] =
        'Could not resend invitation. Please check the server logs.';
}

header('Location: /admin/users.php');
exit;
