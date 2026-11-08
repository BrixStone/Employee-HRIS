<?php
/**
 * POST /api/me/avatar.php   multipart/form-data, field "avatar"     employee
 *
 * Replaces the signed-in employee's profile photo. Same checks as
 * employee/upload_avatar.php: JPG, PNG, GIF or WEBP, at most 3 MB.
 */
require_once __DIR__ . '/../_api.php';

api_methods(['POST']);
$session = api_require_role(['employee']);

try {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../classes/Avatar.php';

    $account = api_require_employee($pdo, $session);
    api_require_permission($pdo, $account, 'employee_records', 'edit', ['own']);
    $employeeId = $account['employee_id'];

    $file = $_FILES['avatar'] ?? null;
    if (!is_array($file) || is_array($file['error'] ?? null)) {
        api_error(400, 'Send one image in the multipart form field "avatar"');
    }

    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        api_error(413, 'Image is too large (max 3 MB)');
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        api_error(400, 'Send one image in the multipart form field "avatar"');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        api_error(400, 'The uploaded file is empty');
    }
    if ($size > Avatar::MAX_BYTES) {
        api_error(413, 'Image is too large (max 3 MB)');
    }

    // Trust the file contents, not the client-supplied name or type.
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        api_error(400, 'Upload failed. Please try again.');
    }
    $info = @getimagesize($tmp);
    $mime = $info !== false ? (string)($info['mime'] ?? '') : '';
    if (!isset(Avatar::ALLOWED[$mime])) {
        api_error(415, 'Not a supported image. Use JPG, PNG, GIF or WEBP.');
    }

    $dir = Avatar::storageDir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create ' . $dir);
    }
    Avatar::ensureColumn($pdo);

    // Unique, non-guessable name scoped to the employee.
    $filename = 'emp_' . $employeeId . '_' . bin2hex(random_bytes(6)) . '.' . Avatar::ALLOWED[$mime];
    $absPath = $dir . '/' . $filename;
    $webPath = Avatar::WEB_DIR . '/' . $filename;

    if (!@move_uploaded_file($tmp, $absPath)) {
        throw new RuntimeException('move_uploaded_file failed for ' . $absPath);
    }

    $cur = $pdo->prepare('SELECT avatar FROM employees WHERE id = :id LIMIT 1');
    $cur->execute(['id' => $employeeId]);
    $oldWeb = $cur->fetchColumn() ?: null;

    try {
        $upd = $pdo->prepare('UPDATE employees SET avatar = :a, updated_at = NOW() WHERE id = :id');
        $upd->execute(['a' => $webPath, 'id' => $employeeId]);
    } catch (Throwable $e) {
        @unlink($absPath); // do not leave an orphaned file behind
        throw $e;
    }

    // Remove the previous upload (only files inside the avatars folder).
    if (is_string($oldWeb) && strpos($oldWeb, Avatar::WEB_DIR . '/') === 0 && strpos($oldWeb, '..') === false) {
        $oldAbs = dirname(__DIR__, 2) . $oldWeb;
        if (is_file($oldAbs)) {
            @unlink($oldAbs);
        }
    }

    respond(200, [
        'success' => true,
        'message' => 'Profile photo updated',
        'data' => ['avatar_url' => Avatar::url(['avatar' => $webPath])]
    ]);
} catch (Throwable $e) {
    error_log('Avatar API error: ' . $e->getMessage());
    api_error(500, 'Internal server error');
}
