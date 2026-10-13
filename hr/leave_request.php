<?php
require __DIR__ . '/../config/supabase.php';
require __DIR__ . '/../classes/LeaveRequest.php';

session_start();
$currentUserId = $_SESSION['user_id'] ?? null; // if you have users
$lr = new LeaveRequest($pdo);

$status = $_GET['status'] ?? 'Pending';
$valid = ['Pending','Approved','Rejected','Cancelled','All'];
if (!in_array($status, $valid, true)) $status = 'Pending';

$rows = $lr->getForHR($status === 'All' ? null : $status);

require __DIR__ . '/../templates/hrTemplates/header.php';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fullName($r){
  $m = !empty($r['middle_name']) ? $r['middle_name'].' ' : '';
  return trim(($r['first_name'] ?? '').' '.$m.($r['last_name'] ?? ''));
}
?>
<h1>Leave Requests</h1>

<form method="get" style="margin:8px 0;">
  <label>Status:</label>
  <select name="status">
    <?php foreach (['Pending','Approved','Rejected','Cancelled','All'] as $s): ?>
      <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= $s ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit">Filter</button>
</form>

<table border="1" cellpadding="6" cellspacing="0">
  <thead>
    <tr>
      <th>Employee</th><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="6">No requests.</td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['employee_no']) ?> — <?= h(fullName($r)) ?></td>
        <td><?= h($r['leave_name']) ?></td>
        <td><?= h($r['start_date']) ?> → <?= h($r['end_date']) ?></td>
        <td><?= h($r['number_of_days']) ?></td>
        <td><?= h($r['status']) ?></td>
        <td>
          <?php if ($r['status']==='Pending'): ?>
            <form action="/hr/leave_request_action.php" method="POST" style="display:inline" onsubmit="return confirm('Approve this request?');">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="action" value="approve">
              <button type="submit">Approve</button>
            </form>
            <form action="/hr/leave_request_action.php" method="POST" style="display:inline" onsubmit="return confirm('Reject this request?');">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="action" value="reject">
              <button type="submit">Reject</button>
            </form>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>