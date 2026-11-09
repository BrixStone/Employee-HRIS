<?php
// manager/leave_requests.php: approve or reject the team's leave requests.
require_once __DIR__ . '/../authentication/guard.php';
$account = require_access('leave_requests', 'view', 'team', ['manager']);

require_once __DIR__ . '/../config/helpers.php'; // h()
require_once __DIR__ . '/../classes/LeaveRequest.php';
require_once __DIR__ . '/../classes/LeaveBalance.php'; // approve() deducts from the balance

$canApprove = Rbac::can($pdo, $account['role'], 'leave_requests', 'approve');
$teamIds    = Rbac::teamEmployeeIds($pdo, $account['employee_id']);
$model      = new LeaveRequest($pdo);
$message    = '';
$error      = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canApprove) {
        guard_forbidden($account['role']);
    }
    csrf_check($account['role']);

    $id     = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $req    = $id > 0 ? $model->findById($id) : null;

    if (!in_array($action, ['approve', 'reject'], true)) {
        $error = 'Unknown action.';
    } elseif ($req === null || !in_array((int)$req['employee_id'], $teamIds, true)) {
        // Same answer for "does not exist" and "not your team": no probing other people's requests.
        $error = 'That request was not found on your team.';
    } elseif ($req['status'] !== 'Pending') {
        $error = "Request #{$id} is already {$req['status']}.";
    } else {
        $actor = ['id' => $account['user_id'], 'role' => 'manager'];
        try {
            if ($action === 'approve') {
                $model->approve($id, $account['user_id'], $actor);
                $message = "Request #{$id} approved.";
            } elseif ($model->reject($id, $account['user_id'], $actor)) {
                $message = "Request #{$id} rejected.";
            } else {
                $error = "Request #{$id} was just handled by someone else.";
            }
        } catch (RuntimeException $e) {
            // Business rules, e.g. "Insufficient leave balance."
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('manager leave action failed: ' . $e->getMessage());
            $error = 'Could not complete that action. Please try again.';
        }
    }
}

$statuses = ['Pending', 'Approved', 'Rejected', 'Cancelled', 'All'];
$status = (string)($_GET['status'] ?? 'Pending');
if (!in_array($status, $statuses, true)) {
    $status = 'Pending';
}
$requests = $model->searchForEmployees($teamIds, $status === 'All' ? null : $status);

$cssPageOverride = 'leave_management';
require __DIR__ . '/../templates/hrTemplates/header.php';
?>
<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">MANAGER PORTAL</p>
    <h1>Team Leave Requests</h1>
    <p>Approve or reject leave for the people who report to you.</p>
  </div>
  <div class="ph-actions">
    Real-time updates: <strong data-realtime-status>Offline</strong>
  </div>
</section>

<?php if ($message !== ''): ?>
  <div class="pill pill-active" style="display:block;margin-bottom:20px;padding:12px 16px;border-radius:12px"><?= h($message) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="error" style="margin-bottom:20px"><?= h($error) ?></div>
<?php endif; ?>

<form method="get" style="margin-bottom:16px">
  <label for="status">Status:</label>
  <select id="status" name="status" onchange="this.form.submit()">
    <?php foreach ($statuses as $s): ?>
      <option value="<?= h($s) ?>" <?= $s === $status ? 'selected' : '' ?>><?= h($s) ?></option>
    <?php endforeach; ?>
  </select>
  <noscript><button class="primary-button" type="submit">Filter</button></noscript>
</form>

<div class="panel table-card">
  <div class="panel-head">
    <h2><?= h($status) ?> requests</h2>
    <span class="muted"><?= count($requests) ?> record<?= count($requests) === 1 ? '' : 's' ?></span>
  </div>
  <table>
    <thead>
      <tr><th>#</th><th>Employee</th><th>Leave</th><th>Dates</th><th>Days</th><th>Reason</th><th>Status</th><th>Filed</th><?php if ($canApprove): ?><th>Action</th><?php endif; ?></tr>
    </thead>
    <tbody>
      <?php if (!$requests): ?>
        <tr><td colspan="9"><div class="empty-state"><span class="es-ico">◷</span>No <?= $status === 'All' ? '' : h(strtolower($status)) . ' ' ?>requests from your team.</div></td></tr>
      <?php else: foreach ($requests as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
          <td><?= h($r['leave_name']) ?></td>
          <td><?= h($r['start_date']) ?><?= $r['end_date'] !== $r['start_date'] ? ' to ' . h($r['end_date']) : '' ?></td>
          <td><?= h($r['number_of_days']) ?></td>
          <td><?= h($r['reason'] ?? '') ?></td>
          <td><span class="pill <?= $r['status'] === 'Approved' ? 'pill-active' : ($r['status'] === 'Pending' ? '' : 'pill-inactive') ?>"><?= h($r['status']) ?></span></td>
          <td><?= h($r['created_at']) ?></td>
          <?php if ($canApprove): ?>
            <td>
              <?php if ($r['status'] === 'Pending'): ?>
                <form method="post" style="display:flex;gap:6px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="primary-button" type="submit" name="action" value="approve">Approve</button>
                  <button class="button" type="submit" name="action" value="reject" onclick="return confirm('Reject this request?')">Reject</button>
                </form>
              <?php else: ?>
                <span class="muted">&mdash;</span>
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
<!-- Live updates: leave events for the people on your team arrive here. -->
<script src="/asset/js/realtime.js" data-refresh="auto" defer></script>
</body>
</html>
