<?php
// admin/create_user.php: create a login account with any role.
require_once __DIR__ . '/../authentication/guard.php';
$account = require_access('user_management', 'edit', 'all');

require_once __DIR__ . '/../config/helpers.php'; // h()
require_once __DIR__ . '/../classes/User.php';

$userModel = new User($pdo);
$roles     = Rbac::ROLES;

$errors  = [];
$success = '';
$old     = ['role' => 'employee', 'employee_id' => '', 'username' => '', 'status' => 'Active'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check($account['role']);

    $role        = Rbac::normalizeRole($_POST['role'] ?? '');
    $username    = trim((string)($_POST['username'] ?? ''));
    $password    = (string)($_POST['password'] ?? '');
    $status      = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
    $employeeId  = ctype_digit((string)($_POST['employee_id'] ?? '')) && (int)$_POST['employee_id'] > 0
        ? (int)$_POST['employee_id']
        : null;

    $old = ['role' => $role, 'employee_id' => (string)($employeeId ?? ''), 'username' => $username, 'status' => $status];

    if (!in_array($role, $roles, true)) {
        $errors[] = 'Please choose a role.';
    }
    // Employees and managers are people on the payroll, so they need an employee record.
    if (in_array($role, ['employee', 'manager'], true) && $employeeId === null) {
        $errors[] = "The {$role} role needs a linked employee. Please select one.";
    }
    if (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($employeeId !== null && !$errors) {
        $chk = $pdo->prepare('SELECT 1 FROM employees WHERE id = :id AND id NOT IN (SELECT employee_id FROM users WHERE employee_id IS NOT NULL) LIMIT 1');
        $chk->execute(['id' => $employeeId]);
        if (!$chk->fetchColumn()) {
            $errors[] = 'That employee does not exist or already has an account.';
        }
    }
    if (!$errors) {
        $dup = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1');
        $dup->execute(['u' => $username]);
        if ($dup->fetchColumn()) {
            $errors[] = 'Username already exists.';
        }
    }

    if (!$errors) {
        try {
            $userModel->create([
                'employee_id'   => $employeeId,
                'username'      => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role'          => $role,
                'status'        => $status,
            ]);
            $success = "Created {$role} account \"{$username}\".";
            $old = ['role' => 'employee', 'employee_id' => '', 'username' => '', 'status' => 'Active'];
        } catch (Throwable $e) {
            error_log('create_user failed: ' . $e->getMessage());
            $errors[] = 'Could not create the user. Please try again.';
        }
    }
}

$employees = $userModel->getEmployeesWithoutUsers();

$cssPageOverride = 'department';
require __DIR__ . '/../templates/hrTemplates/header.php';
?>
<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">ADMINISTRATION</p>
    <h1>Create User</h1>
    <p>Choose a role first. Employee and manager accounts must be linked to an employee.</p>
  </div>
  <div class="ph-actions"><a class="button" href="/admin/users.php">&larr; Back to users</a></div>
</section>

<?php foreach ($errors as $e): ?>
  <div class="error" style="margin-bottom:12px"><?= h($e) ?></div>
<?php endforeach; ?>
<?php if ($success !== ''): ?>
  <div class="pill pill-active" style="display:block;margin-bottom:20px;padding:12px 16px;border-radius:12px"><?= h($success) ?></div>
<?php endif; ?>

<div class="panel" style="max-width:560px">
  <div class="panel-head"><h2>New account</h2></div>
  <div class="panel-body">
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div>
        <label for="role">Role</label>
        <select id="role" name="role">
          <?php foreach ($roles as $r): ?>
            <option value="<?= h($r) ?>" <?= $old['role'] === $r ? 'selected' : '' ?>><?= h($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="employee_id">Employee <span class="muted">(required for employee and manager)</span></label>
        <select id="employee_id" name="employee_id">
          <option value="">-- None --</option>
          <?php foreach ($employees as $emp):
            $label = (($emp['employee_id'] ?? '') !== '' ? '[' . $emp['employee_id'] . '] ' : '')
                   . trim(($emp['last_name'] ?? '') . ', ' . ($emp['first_name'] ?? ''))
                   . (empty($emp['email']) ? '' : ' — ' . $emp['email']);
          ?>
            <option value="<?= (int)$emp['id'] ?>" <?= $old['employee_id'] === (string)$emp['id'] ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="muted">Only employees without an account are listed.</small>
      </div>
      <div>
        <label for="username">Username</label>
        <input id="username" name="username" required minlength="3" value="<?= h($old['username']) ?>">
      </div>
      <div>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
      </div>
      <div>
        <label for="status">Status</label>
        <select id="status" name="status">
          <option value="Active" <?= $old['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
          <option value="Inactive" <?= $old['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <button class="primary-button" type="submit">Create user</button>
    </form>
  </div>
</div>
  </section>
</main>
</body>
</html>
