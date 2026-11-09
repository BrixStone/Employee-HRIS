<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Avatar.php';

// Logged in, account still active, employee portal, allowed to edit their own record
require_once __DIR__ . '/../authentication/guard.php';
require_access('employee_records', 'edit', 'own', ['employee']);

$employeeId = $_SESSION['employee_id'] ?? null;
if (!$employeeId) {
    exit('No employee record is linked to this user account.');
}

// Where to send the user back to, with a status flag.
function back(string $status): void
{
    header('Location: /employee/profile.php?avatar=' . urlencode($status));
    exit;
}

// Only accept POST uploads.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    back('error');
}

// Basic presence / PHP upload error checks.
if (!isset($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
    back('error');
}
$file = $_FILES['avatar'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    // e.g. too large per php.ini, or no file chosen
    back($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE ? 'toolarge' : 'error');
}

// Size guard (app-level, independent of php.ini).
if (($file['size'] ?? 0) <= 0 || $file['size'] > Avatar::MAX_BYTES) {
    back('toolarge');
}

// Verify it really is an image and get its true MIME type (don't trust the
// client-supplied name/type).
$tmp = $file['tmp_name'] ?? '';
if ($tmp === '' || !is_uploaded_file($tmp)) {
    back('error');
}

$info = @getimagesize($tmp);
if ($info === false) {
    back('invalid');          // not a real image
}
$mime = $info['mime'] ?? '';
if (!isset(Avatar::ALLOWED[$mime])) {
    back('invalid');          // unsupported image type
}
$ext = Avatar::ALLOWED[$mime];

// Ensure the storage directory and DB column exist.
$dir = Avatar::storageDir();
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    back('error');
}
Avatar::ensureColumn($pdo);

// Build a unique, non-guessable filename scoped to the employee.
$filename = 'emp_' . (int)$employeeId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$absPath  = $dir . '/' . $filename;
$webPath  = Avatar::WEB_DIR . '/' . $filename;

if (!@move_uploaded_file($tmp, $absPath)) {
    back('error');
}

// Fetch the previous avatar so we can remove the old file after a successful swap.
$oldWeb = null;
try {
    $cur = $pdo->prepare('SELECT avatar FROM employees WHERE id = :id LIMIT 1');
    $cur->execute([':id' => $employeeId]);
    $oldWeb = $cur->fetchColumn() ?: null;
} catch (Throwable $e) { /* ignore */ }

// Persist the new path.
try {
    $upd = $pdo->prepare('UPDATE employees SET avatar = :a WHERE id = :id');
    $upd->execute([':a' => $webPath, ':id' => $employeeId]);
} catch (Throwable $e) {
    // Roll back the just-saved file so we don't orphan it.
    @unlink($absPath);
    error_log('Avatar DB update failed: ' . $e->getMessage());
    back('error');
}

// Clean up the previous uploaded image (only files inside our avatars dir).
if ($oldWeb && strpos($oldWeb, Avatar::WEB_DIR . '/') === 0) {
    $oldAbs = dirname(__DIR__) . $oldWeb;
    if (is_file($oldAbs)) {
        @unlink($oldAbs);
    }
}

back('ok');
