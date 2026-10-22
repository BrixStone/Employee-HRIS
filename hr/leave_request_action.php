<?php
require __DIR__ . '/../config/supabase.php';
require __DIR__ . '/../classes/LeaveRequest.php';
require __DIR__ . '/../classes/LeaveBalance.php';

session_start();
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

// Reviewer account recorded in the leave transaction history
$actor = [
    'id'    => $currentUserId !== null ? (int)$currentUserId : null,
    'name'  => $_SESSION['username'] ?? null,
    'email' => $_SESSION['email'] ?? null,
    'role'  => $_SESSION['role'] ?? 'hr',
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