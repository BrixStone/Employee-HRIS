<?php
require_once __DIR__ . '/../authentication/guard.php';
// Only signed-in HR/admin accounts may approve or reject leave
require_role(['hr', 'admin']);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/LeaveRequest.php';
require __DIR__ . '/../classes/LeaveBalance.php';

$currentUserId = $_SESSION['user_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /hr/leave_request.php'); exit;
}

$id = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
$action = $_POST['action'] ?? '';

if ($id <= 0 || !in_array($action, ['approve','reject'], true)) {
    header('Location: /hr/leave_request.php'); exit;
}

$lr = new LeaveRequest($pdo);

// Reviewer account recorded in the leave transaction history. The display
// name and email are looked up from users/employees when the log is written.
$actor = [
    'id'   => $currentUserId !== null ? (int)$currentUserId : null,
    'role' => $_SESSION['role'] ?? 'hr',
];

try {
    if ($action === 'approve') {
        $lr->approve($id, $currentUserId !== null ? (int)$currentUserId : null, $actor);
    } else {
        $lr->reject($id, $currentUserId !== null ? (int)$currentUserId : null, $actor);
    }
    header('Location: /hr/leave_request.php?status=Pending'); exit;
} catch (Throwable $e) {
    // In a real app, flash the error; for now, simple fallback
    header('Location: /hr/leave_request.php?status=Pending'); exit;
}