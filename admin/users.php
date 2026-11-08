<?php
// admin/users.php: user accounts, their role and their status.
require_once __DIR__ . '/../authentication/guard.php';
$account = require_access('user_management', 'view', 'all');

require_once __DIR__ . '/../config/helpers.php'; // h(), fullName()
require_once __DIR__ . '/../classes/User.php';

$canEdit  = Rbac::can($pdo, $account['role'], 'user_management', 'edit');
$roles    = Rbac::ROLES;
$statuses = ['Active', 'Inactive'];
$message  = '';
$error    = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canEdit) {
        guard_forbidden($account['role']);
    }
    csrf_check($account['role']);

    $targetId  = (int)($_POST['user_id'] ?? 0);
    $newRole   = Rbac::normalizeRole($_POST['role'] ?? '');
    $newStatus = (string)($_POST['status'] ?? '');

    $st = $pdo->prepare('SELECT id, username, role, status, employee_id FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $targetId]);
    $target = $st->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        $error = 'That user no longer exists.';
    } elseif ($targetId === $account['user_id']) {
        $error = 'You cannot change your own role or status. Ask another admin.';
    } elseif (!in_array($newRole, $roles, true)) {
        $error = 'Unknown role.';
    } elseif (!in_array($newStatus, $statuses, true)) {
        $error = 'Unknown status.';
    } elseif (in_array($newRole, ['employee', 'manager'], true) && $target['employee_id'] === null) {
        $error = "The {$newRole} role needs a linked employee record, and this account has none.";
    } else {
        // Never leave the system without an active admin.
        $wasActiveAdmin = Rbac::normalizeRole($target['role']) === 'admin'
            && strcasecmp((string)$target['status'], 'Active') === 0;
        if ($wasActiveAdmin && ($newRole !== 'admin' || $newStatus !== 'Active')) {
            $c = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'admin' AND status = 'Active' AND id <> :id");
            $c->execute(['id' => $targetId]);
            if ((int)$c->fetchColumn() === 0) {
                $error = 'This is the last active admin. Make another admin first.';
            }
        }
    }

    if ($error === '') {
        $up = $pdo->prepare('UPDATE users SET role = :r, status = :s, updated_at = NOW() WHERE id = :id');
        $up->execute(['r' => $newRole, 's' => $newStatus, 'id' => $targetId]);
        $message = "Saved {$target['username']}: role {$newRole}, status {$newStatus}. "
                 . 'If they are signed in, the change applies on their next click.';
    }
}

$users = (new User($pdo))->getAll();

$cssPageOverride = 'department';
require __DIR__ . '/../templates/hrTemplates/header.php';
?>
<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">ADMINISTRATION</p>
    <h1>User Management</h1>
    <p>Create accounts and choose what each person can do.</p>
  </div>
  <div class="ph-actions">
    <?php if ($canEdit): ?><a class="button" href="/admin/create_user.php">+ Create user</a><?php endif; ?>
    <a class="button" href="/admin/permissions.php">View permissions</a>
  </div>
</section>

<?php if ($message !== ''): ?>
  <div class="pill pill-active" style="display:block;margin-bottom:20px;padding:12px 16px;border-radius:12px"><?= h($message) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="error" style="margin-bottom:20px"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel table-card">
  <div class="panel-head">
    <h2>Accounts</h2>
    <span class="muted"><?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?></span>
  </div>
  <table>
    <thead>
      <tr>
        <th>Username</th>
        <th>Employee</th>
        <th>Role</th>
        <th>Status</th>
        <?php if ($canEdit): ?><th>Change</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($users)): ?>
        <tr><td colspan="5"><div class="empty-state"><span class="es-ico">☺</span>No users found.</div></td></tr>
      <?php else: foreach ($users as $u):
        $isMe   = (int)$u['id'] === $account['user_id'];
        $active = strcasecmp((string)$u['status'], 'Active') === 0;
        $uRole  = Rbac::normalizeRole($u['role']);
      ?>
        <tr>
          <td><?= h($u['username']) ?><?= $isMe ? ' <span class="muted">(you)</span>' : '' ?></td>
          <td>
            <?php if ($u['employee_id']): ?>
              <?= h($u['employee_no']) ?> &mdash; <?= h(fullName($u)) ?>
            <?php else: ?>
              <span class="muted">&mdash;</span>
            <?php endif; ?>
          </td>
          <td><?= h($uRole) ?></td>
          <td><span class="pill <?= $active ? 'pill-active' : 'pill-inactive' ?>"><?= h($u['status']) ?></span></td>
          <?php if ($canEdit): ?>
            <td>
              <?php if ($isMe): ?>
                <span class="muted">Your own account</span>
              <?php else: ?>
                <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                  <?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <select name="role" aria-label="Role for <?= h($u['username']) ?>">
                    <?php foreach ($roles as $r): ?>
                      <option value="<?= h($r) ?>" <?= $r === $uRole ? 'selected' : '' ?>><?= h($r) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <select name="status" aria-label="Status for <?= h($u['username']) ?>">
                    <?php foreach ($statuses as $s): ?>
                      <option value="<?= h($s) ?>" <?= $active === ($s === 'Active') ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="primary-button" type="submit">Save</button>
                </form>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
  </section>
</main>
</body>
</html>
