<?php
// manager/attendance.php: recent attendance of the manager's direct reports (read only).
require_once __DIR__ . '/../authentication/guard.php';
$account = require_access('attendance', 'view', 'team', ['manager']);

require_once __DIR__ . '/../config/helpers.php'; // h()

$teamIds = Rbac::teamEmployeeIds($pdo, $account['employee_id']);

$daysOptions = [7, 14, 30];
$days = (int)($_GET['days'] ?? 14);
if (!in_array($days, $daysOptions, true)) {
    $days = 14;
}

// Optional filter: one team member. Anything outside the team is ignored.
$filterId = (int)($_GET['employee'] ?? 0);
if ($filterId > 0 && !in_array($filterId, $teamIds, true)) {
    $filterId = 0;
}

$members = [];
$rows = [];
if ($teamIds) {
    $in = implode(',', array_fill(0, count($teamIds), '?'));
    $st = $pdo->prepare("SELECT id, employee_id, first_name, last_name FROM employees WHERE id IN ({$in}) ORDER BY last_name, first_name");
    $st->execute($teamIds);
    $members = $st->fetchAll(PDO::FETCH_ASSOC);

    // Attendance rows are linked by employee_id, or by email when written by the clock-in page.
    $sql = "
        SELECT a.id, e.employee_id AS emp_code, e.first_name, e.last_name, a.time_in, a.time_out, a.workhours
        FROM employees e
        JOIN attendance a ON (a.employee_id = e.id OR (a.employee_id IS NULL AND LOWER(a.email) = LOWER(e.email)))
        WHERE e.manager_id = :m
          AND a.time_in >= (NOW() - INTERVAL :d DAY)
    ";
    $params = ['m' => $account['employee_id'], 'd' => $days];
    if ($filterId > 0) {
        $sql .= ' AND e.id = :e';
        $params['e'] = $filterId;
    }
    $sql .= ' ORDER BY a.time_in DESC LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

$cssPageOverride = 'employees';
require __DIR__ . '/../templates/hrTemplates/header.php';
?>
<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">MANAGER PORTAL</p>
    <h1>Team Attendance</h1>
    <p>Time in and time out for your team. Read only.</p>
  </div>
</section>

<form method="get" style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
  <label for="employee">Person:</label>
  <select id="employee" name="employee" onchange="this.form.submit()">
    <option value="0">Everyone</option>
    <?php foreach ($members as $m): ?>
      <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $filterId ? 'selected' : '' ?>><?= h($m['employee_id'] . ' - ' . $m['first_name'] . ' ' . $m['last_name']) ?></option>
    <?php endforeach; ?>
  </select>
  <label for="days">Period:</label>
  <select id="days" name="days" onchange="this.form.submit()">
    <?php foreach ($daysOptions as $d): ?>
      <option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>>Last <?= $d ?> days</option>
    <?php endforeach; ?>
  </select>
  <noscript><button class="primary-button" type="submit">Apply</button></noscript>
</form>

<div class="panel table-card">
  <div class="panel-head">
    <h2>Attendance</h2>
    <span class="muted"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?></span>
  </div>
  <table>
    <thead>
      <tr><th>Date</th><th>Employee</th><th>Time in</th><th>Time out</th><th>Hours</th></tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5"><div class="empty-state"><span class="es-ico">▥</span><?= $teamIds ? 'No attendance in this period.' : 'Nobody reports to you yet.' ?></div></td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= h(date('M d, Y', strtotime($r['time_in']))) ?></td>
          <td><?= h($r['emp_code'] . ' - ' . $r['first_name'] . ' ' . $r['last_name']) ?></td>
          <td><?= h(date('h:i A', strtotime($r['time_in']))) ?></td>
          <td><?= $r['time_out'] ? h(date('h:i A', strtotime($r['time_out']))) : '<span class="muted">still in</span>' ?></td>
          <td><?= $r['workhours'] !== null ? h($r['workhours']) : '<span class="muted">&mdash;</span>' ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
  </section>
</main>
</body>
</html>
