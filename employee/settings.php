<?php
session_start();

require_once __DIR__ . '/../config/database.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

date_default_timezone_set('Asia/Manila');

// Logged in, account still active, employee portal (changing your own password
// is not tied to a module in the permission matrix).
require_once __DIR__ . '/../authentication/guard.php';
require_access(null, 'view', null, ['employee']);

$userId = (int) $_SESSION['user_id'];
$employeeId = $_SESSION['employee_id'] ?? null;

// Load the account + linked employee record.
$stmt = $pdo->prepare("
    SELECT u.id AS user_id, u.username, u.password AS password_hash, u.role, u.status,
           e.first_name, e.last_name, e.email
    FROM users AS u
    LEFT JOIN employees AS e ON e.id = u.employee_id
    WHERE u.id = :uid
    LIMIT 1
");
$stmt->execute(['uid' => $userId]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    exit('Account record not found.');
}

$fullName = trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? ''));

$message = '';
$error = '';

// Handle password change.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($current === '' || $new === '' || $confirm === '') {
        $error = 'Please fill in all password fields.';
    } elseif (!password_verify($current, $account['password_hash'])) {
        $error = 'Your current password is incorrect.';
    } elseif (mb_strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } elseif (password_verify($new, $account['password_hash'])) {
        $error = 'New password must be different from the current one.';
    } else {
        try {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE users SET password = :pw WHERE id = :uid");
            $upd->execute(['pw' => $newHash, 'uid' => $userId]);
            $account['password_hash'] = $newHash;
            $message = 'Your password has been updated successfully.';
        } catch (Throwable $e) {
            error_log('Password update failed: ' . $e->getMessage());
            $error = 'Unable to update your password right now. Please try again.';
        }
    }
}

$user = [
    'full_name' => htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'),
    'email'     => htmlspecialchars($account['email'] ?? '', ENT_QUOTES, 'UTF-8'),
    'avatar'    => 'https://ui-avatars.com/api/?name=' . urlencode($fullName) . '&background=4763ff&color=fff',
];

// Shell configuration
$title = 'Settings';
$eyebrow = 'ACCOUNT';
$subtitle = 'Manage your account and security';
$pageCss = 'emp_settings';
require __DIR__ . '/../templates/employeeTemplates/header.php';
?>

<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">ACCOUNT</p>
    <h1>Settings</h1>
    <p>Review your account details and update your password.</p>
  </div>
</section>

<?php if ($message !== ''): ?>
  <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="settings-grid">
  <article class="card">
    <div class="card-heading"><div><p class="eyebrow">PROFILE</p><h2>Account information</h2></div></div>
    <div class="set-info">
      <div class="set-row"><span class="set-label">Full Name</span><strong><?= htmlspecialchars($fullName ?: '—') ?></strong></div>
      <div class="set-row"><span class="set-label">Username</span><strong><?= htmlspecialchars($account['username'] ?? '—') ?></strong></div>
      <div class="set-row"><span class="set-label">Email</span><strong class="teal"><?= htmlspecialchars($account['email'] ?? '—') ?></strong></div>
      <div class="set-row"><span class="set-label">Role</span><span class="pill pill-neutral"><?= htmlspecialchars(ucfirst($account['role'] ?? 'employee')) ?></span></div>
      <div class="set-row"><span class="set-label">Status</span>
        <span class="pill <?= strcasecmp((string)$account['status'], 'Active') === 0 ? 'pill-active' : 'pill-inactive' ?>"><?= htmlspecialchars($account['status'] ?? '—') ?></span>
      </div>
    </div>
    <p class="muted" style="margin:16px 0 0;font-size:12px">To change your name or email, contact HR — these are managed from your employee record.</p>
  </article>

  <article class="card">
    <div class="card-heading"><div><p class="eyebrow">SECURITY</p><h2>Change password</h2></div></div>
    <form method="POST" action="settings.php" autocomplete="off" class="pw-form">
      <input type="hidden" name="action" value="change_password">
      <div class="form-field">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
      </div>
      <div class="form-field">
        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
        <small class="muted">At least 8 characters.</small>
      </div>
      <div class="form-field">
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
      </div>
      <div class="pw-actions">
        <button type="submit" class="primary-button">Update password</button>
      </div>
    </form>
  </article>
</div>

<?php require __DIR__ . '/../templates/employeeTemplates/footer.php'; ?>
