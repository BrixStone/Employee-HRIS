<?php
// admin/create_user.php: create a login account with any role.
require_once __DIR__ . '/../authentication/guard.php';
$account = require_access('user_management', 'edit', 'all');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';

$userModel = new User($pdo);
$roles = Rbac::ROLES;

$employees = $userModel->getEmployeesWithoutUsers();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($account['role']);

  $role = Rbac::normalizeRole($_POST['role'] ?? '');
  $username = trim($_POST['username'] ?? '');
  $password = (string)($_POST['password'] ?? '');
  $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
  $employee_id = ctype_digit((string)($_POST['employee_id'] ?? '')) && (int)$_POST['employee_id'] > 0
    ? (int)$_POST['employee_id']
    : null;

  if (!in_array($role, $roles, true)) {
    $errors[] = 'Please choose a role.';
  }

  // Employees and managers are people on the payroll, so they need an employee record.
  if (in_array($role, ['employee', 'manager'], true) && $employee_id === null) {
    $errors[] = "The {$role} role needs a linked employee. Please select one.";
  }

  if ($username === '' || strlen($username) < 3) {
    $errors[] = 'Username must be at least 3 characters.';
  }

  if ($password === '' || strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
  }

  if ($employee_id !== null && !$errors) {
    $chk = $pdo->prepare('SELECT 1 FROM employees WHERE id = :id AND id NOT IN (SELECT employee_id FROM users WHERE employee_id IS NOT NULL) LIMIT 1');
    $chk->execute([':id' => $employee_id]);
    if (!$chk->fetchColumn()) {
      $errors[] = 'That employee does not exist or already has an account.';
    }
  }

  if (!$errors) {
    $stmt = $pdo->prepare(
      "SELECT 1 FROM users WHERE lower(username)=lower(:u) LIMIT 1"
    );
    $stmt->execute([':u' => $username]);

    if ($stmt->fetchColumn()) {
      $errors[] = 'Username already exists.';
    }
  }

  if (!$errors) {
    try {
      $hash = password_hash($password, PASSWORD_DEFAULT);

      $userModel->create([
        'employee_id' => $employee_id,
        'username' => $username,
        'password_hash' => $hash,
        'role' => $role,
        'status' => $status,
      ]);

      $success = "Created {$role} account \"{$username}\".";
      $employees = $userModel->getEmployeesWithoutUsers();
      $_POST = [];
    } catch (Throwable $e) {
      error_log('create_user failed: ' . $e->getMessage());
      $errors[] = 'Could not create the user. Please try again.';
    }
  }
}
require __DIR__ . '/../templates/adminTemplates/header.php';
?>

<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">ACCESS CONTROL</p>
    <h1>Create User</h1>
    <p>Link an employee record to a new login account for the portal.</p>
  </div>
  <div class="ph-actions">
    <a class="button" href="/admin/users.php">← Back to Users</a>
  </div>
</section>

<?php if ($errors): ?>
  <div class="alert error" style="margin-bottom:20px">
    <?php foreach ($errors as $e): ?>
      <div><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert" style="margin-bottom:20px;border-color:rgba(52,211,153,.4);background:rgba(52,211,153,.12);color:#c7f9e5">
    <?= htmlspecialchars($success) ?>
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <h2>New Account Details</h2>
  </div>
  <div class="panel-body">
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>

      <div>
        <label for="employee_id">Employee</label>
        <select id="employee_id" name="employee_id">
          <option value="">-- None --</option>

          <?php foreach ($employees as $emp):
            $id = (int)$emp['id'];
            $code = isset($emp['employee_id']) && $emp['employee_id'] !== ''
              ? ('[' . htmlspecialchars($emp['employee_id']) . '] ')
              : '';
            $name = trim(($emp['last_name'] ?? '') . ', ' . ($emp['first_name'] ?? ''));
            $label = $code . $name . (empty($emp['email']) ? '' : ' — ' . $emp['email']);
            $sel = (isset($_POST['employee_id']) && (int)$_POST['employee_id'] === $id)
              ? 'selected'
              : '';
          ?>
            <option value="<?= $id ?>" <?= $sel ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="muted" style="margin-top:6px">
          Only employees without an existing login are shown. Required for employee and manager accounts.
        </div>
      </div>

      <div class="form-grid">
        <div>
          <label for="username">Username</label>
          <input
            id="username"
            name="username"
            required
            minlength="3"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>

        <div>
          <label for="password">Password</label>
          <input
            id="password"
            name="password"
            type="password"
            required
            minlength="8"
            value="">
        </div>
      </div>

      <div class="form-grid">
        <div>
          <label for="role">Role</label>
          <select id="role" name="role">
            <?php foreach ($roles as $r): ?>
              <option value="<?= htmlspecialchars($r) ?>" <?= (($_POST['role'] ?? 'employee') === $r) ? 'selected' : '' ?>>
                <?= htmlspecialchars(ucfirst($r)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label for="status">Status</label>
          <select id="status" name="status">
            <option value="Active" <?= (($_POST['status'] ?? 'Active') === 'Active') ? 'selected' : '' ?>>
              Active
            </option>
            <option value="Inactive" <?= (($_POST['status'] ?? '') === 'Inactive') ? 'selected' : '' ?>>
              Inactive
            </option>
          </select>
        </div>
      </div>

      <div class="form-actions">
        <button class="primary-button" type="submit">Create User</button>
        <a class="button" href="/admin/users.php">Cancel</a>
      </div>
    </form>
  </div>
</div>
