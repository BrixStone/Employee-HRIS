<?php
// manager/dashboard.php: overview of the manager's own team.
require_once __DIR__ . '/../authentication/guard.php';
$account = require_access('dashboard', 'view', null, ['manager']);

require_once __DIR__ . '/../config/helpers.php'; // h()
require_once __DIR__ . '/../classes/LeaveRequest.php';

$teamIds   = Rbac::teamEmployeeIds($pdo, $account['employee_id']);
$teamSize  = count($teamIds);
$pending   = 0;
$onLeave   = 0;
$clockedIn = 0;
$recent    = [];

if ($teamIds) {
    $in = implode(',', array_fill(0, $teamSize, '?'));

    $st = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending' AND employee_id IN ({$in})");
    $st->execute($teamIds);
    $pending = (int)$st->fetchColumn();

    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT employee_id) FROM leave_requests
         WHERE status = 'Approved' AND CURDATE() BETWEEN start_date AND end_date AND employee_id IN ({$in})"
    );
    $st->execute($teamIds);
    $onLeave = (int)$st->fetchColumn();

    // Attendance rows are linked by employee_id, or by email when written by the clock-in page.
    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT e.id)
         FROM employees e
         JOIN attendance a ON (a.employee_id = e.id OR (a.employee_id IS NULL AND LOWER(a.email) = LOWER(e.email)))
         WHERE e.manager_id = ? AND DATE(a.time_in) = CURDATE()"
    );
    $st->execute([$account['employee_id']]);
    $clockedIn = (int)$st->fetchColumn();

    $recent = array_slice((new LeaveRequest($pdo))->searchForEmployees($teamIds, 'Pending'), 0, 5);
}

$cssPageOverride = 'dashboard';
require __DIR__ . '/../templates/hrTemplates/header.php';
?>
<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">MANAGER PORTAL</p>
    <h1>Team overview</h1>
    <p>Your direct reports at a glance. You only ever see your own team.</p>
  </div>
  <div class="ph-actions">
    <span class="system-status" data-realtime-status>Offline</span>
  </div>
</section>

<?php if ($account['employee_id'] === null): ?>
  <div class="error" style="margin-bottom:20px">Your account is not linked to an employee record, so you have no team. Ask an admin to link it.</div>
<?php elseif ($teamSize === 0): ?>
  <div class="error" style="margin-bottom:20px">Nobody reports to you yet. HR can assign people to you from Employees &rarr; Edit &rarr; "Reports to".</div>
<?php endif; ?>

<div class="metric-strip">
  <article class="metric-card">
    <span class="metric-title">Team members</span>
    <strong class="metric-number green"><?= $teamSize ?></strong>
    <span class="metric-description">People who report to you</span>
  </article>
  <article class="metric-card">
    <span class="metric-title">Pending leave</span>
    <strong class="metric-number blue"><?= $pending ?></strong>
    <span class="metric-description">Waiting for your decision</span>
  </article>
  <article class="metric-card">
    <span class="metric-title">On leave today</span>
    <strong class="metric-number purple"><?= $onLeave ?></strong>
    <span class="metric-description">Approved leave covering today</span>
  </article>
  <article class="metric-card">
    <span class="metric-title">Clocked in today</span>
    <strong class="metric-number"><?= $clockedIn ?></strong>
    <span class="metric-description">Team members with a time-in today</span>
  </article>
</div>

<div class="panel table-card" style="margin-top:20px">
  <div class="panel-head">
    <h2>Latest pending requests</h2>
    <a class="button" href="/manager/leave_requests.php">Open all &rarr;</a>
  </div>
  <table>
    <thead><tr><th>Employee</th><th>Leave</th><th>Dates</th><th>Days</th></tr></thead>
    <tbody>
      <?php if (!$recent): ?>
        <tr><td colspan="4"><div class="empty-state"><span class="es-ico">◷</span>Nothing is waiting for you.</div></td></tr>
      <?php else: foreach ($recent as $r): ?>
        <tr>
          <td><?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
          <td><?= h($r['leave_name']) ?></td>
          <td><?= h($r['start_date']) ?><?= $r['end_date'] !== $r['start_date'] ? ' to ' . h($r['end_date']) : '' ?></td>
          <td><?= h($r['number_of_days']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
  </section>
</main>
<!-- Live updates: leave events for the people on your team arrive here. -->
<script src="/asset/js/realtime.js" data-refresh="prompt" defer></script>
</body>
</html>
