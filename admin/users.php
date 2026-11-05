<?php
// admin/users.php
require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php'; // h(), fullName()
require __DIR__ . '/../classes/User.php';

session_start();
// Optional guard:
// if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('Forbidden'); }

$userModel = new User($pdo);
$users = $userModel->getAll();
?>
<h1>User Management</h1>

<p><a href="/admin/create_user.php">+ Create User</a></p>

<table border="1" cellpadding="6" cellspacing="0">
  <thead>
    <tr>
      <th>Username</th>
      <th>Role</th>
      <th>Status</th>
      <th>Employee</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($users)): ?>
      <tr><td colspan="4">No users found.</td></tr>
    <?php else: foreach ($users as $u): ?>
      <tr>
        <td><?= h($u['username']) ?></td>
        <td><?= h($u['role']) ?></td>
        <td><?= h($u['status']) ?></td>
        <td>
          <?php if ($u['employee_id']): ?>
            <?= h($u['employee_no']) ?> — <?= h(fullName($u)) ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>
      <a href="/authentication/logout.php">Sign out</a>
