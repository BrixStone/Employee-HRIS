<?php
/**
 * POST /api/me/password.php                                         employee
 * Body: {"current_password": "...", "new_password": "...", "confirm_password": "..."}
 *
 * Same rules as employee/settings.php.
 */
require_once __DIR__ . '/../_api.php';

api_methods(['POST']);
$session = api_require_role(['employee']);

try {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../classes/User.php';

    $account = api_require_employee($pdo, $session);
    $input = api_json_body();

    $fields = ['current_password', 'new_password', 'confirm_password'];
    $errors = [];
    api_reject_unknown($input, $fields, $errors);

    // Passwords are taken as-is (never trimmed).
    foreach ($fields as $field) {
        if (!isset($input[$field]) || !is_string($input[$field]) || $input[$field] === '') {
            $errors[$field] = 'This field is required';
        }
    }
    if ($errors) {
        api_error(422, 'Validation failed', ['errors' => $errors]);
    }

    $users = new User($pdo);
    $hash = (string)($users->findById($account['user_id'])['password'] ?? '');

    if (!password_verify($input['current_password'], $hash)) {
        $errors['current_password'] = 'Your current password is incorrect';
    } elseif (mb_strlen($input['new_password']) < 8) {
        $errors['new_password'] = 'Must be at least 8 characters';
    } elseif ($input['new_password'] !== $input['confirm_password']) {
        $errors['confirm_password'] = 'Does not match the new password';
    } elseif (password_verify($input['new_password'], $hash)) {
        $errors['new_password'] = 'Must be different from the current password';
    }
    if ($errors) {
        api_error(422, 'Validation failed', ['errors' => $errors]);
    }

    $users->resetPassword($account['user_id'], password_hash($input['new_password'], PASSWORD_DEFAULT));

    respond(200, [
        'success' => true,
        'message' => 'Your password has been updated'
    ]);
} catch (Throwable $e) {
    error_log('Password API error: ' . $e->getMessage());
    api_error(500, 'Internal server error');
}
