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

// Lightweight counts for the metric strip.
$totalUsers = count($users);
$activeUsers = 0;
$linkedUsers = 0;
foreach ($users as $__u) {
    if (strcasecmp((string)($__u['status'] ?? ''), 'Active') === 0) {
        $activeUsers++;
    }
    if (!empty($__u['employee_id'])) {
        $linkedUsers++;
    }
}
$inactiveUsers = $totalUsers - $activeUsers;

require __DIR__ . '/../templates/adminTemplates/header.php';
?>

<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">ACCESS CONTROL</p>
    <h1>User Management</h1>
    <p>Create and review the login accounts that can access the HRIS.</p>
  </div>
  <div class="ph-actions">
    <a class="primary-button" href="/admin/create_user.php">＋ Create User</a>
  </div>
</section>

<div class="metric-strip">
  <article class="metric-card metric-teal">
    <div class="metric-top"><span class="metric-ico">◉</span><span class="metric-chip">All</span></div>
    <span class="metric-title">Total Users</span>
    <strong class="metric-number teal"><?= number_format($totalUsers) ?></strong>
  </article>
  <article class="metric-card metric-green">
    <div class="metric-top"><span class="metric-ico">✓</span></div>
    <span class="metric-title">Active</span>
    <strong class="metric-number green"><?= number_format($activeUsers) ?></strong>
  </article>
  <article class="metric-card metric-blue">
    <div class="metric-top"><span class="metric-ico">✕</span></div>
    <span class="metric-title">Inactive</span>
    <strong class="metric-number blue"><?= number_format($inactiveUsers) ?></strong>
  </article>
  <article class="metric-card metric-violet">
    <div class="metric-top"><span class="metric-ico">⚲</span></div>
    <span class="metric-title">Linked to Employee</span>
    <strong class="metric-number purple"><?= number_format($linkedUsers) ?></strong>
  </article>
</div>

<div class="panel table-card">
  <div class="panel-head">
    <h2>Accounts</h2>
    <span class="muted"><?= number_format($totalUsers) ?> user<?= $totalUsers === 1 ? '' : 's' ?></span>
  </div>
  <table>
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
        <tr>
          <td colspan="4">
            <div class="empty-state">
              <span class="es-ico">◉</span>
              No users found.
            </div>
          </td>
        </tr>
      <?php else: foreach ($users as $u): ?>
        <?php
          $isActive = strcasecmp((string)($u['status'] ?? ''), 'Active') === 0;
          $pillClass = $isActive ? 'pill-active' : (($u['status'] ?? '') !== '' ? 'pill-inactive' : 'pill-neutral');
        ?>
        <tr>
          <td><strong><?= h($u['username']) ?></strong></td>
          <td><span class="pill pill-neutral"><?= h(ucfirst((string)$u['role'])) ?></span></td>
          <td><span class="pill <?= $pillClass ?>"><?= h($u['status'] ?: 'Unknown') ?></span></td>
          <td>
            <?php if ($u['employee_id']): ?>
              <strong><?= h($u['employee_no']) ?></strong> — <?= h(fullName($u)) ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

