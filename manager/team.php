<?php
// manager/team.php: the manager's direct reports (read only).
require_once __DIR__ . '/../authentication/guard.php';
$account = require_access('employee_records', 'view', 'team', ['manager']);

require_once __DIR__ . '/../config/helpers.php'; // h()

$q = trim((string)($_GET['q'] ?? ''));

$members = [];
if ($account['employee_id'] !== null) {
    // Only rows where manager_id is THIS manager. The id comes from the
    // verified account, never from the request.
    $st = $pdo->prepare("
        SELECT e.id, e.employee_id, e.first_name, e.middle_name, e.last_name, e.email, e.phone,
               e.employment_status, e.date_hired,
               d.name AS department_name, p.title AS position_title
        FROM employees e
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN positions p ON p.id = e.position_id
        WHERE e.manager_id = :m
        ORDER BY e.last_name, e.first_name
    ");
    $st->execute(['m' => $account['employee_id']]);
    $members = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($q !== '') {
        $members = array_values(array_filter($members, static function (array $m) use ($q): bool {
            return stripos($m['employee_id'] . ' ' . fullName($m) . ' ' . $m['email'], $q) !== false;
        }));
    }
}

$cssPageOverride = 'employees';
require __DIR__ . '/../templates/hrTemplates/header.php';
?>
<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">MANAGER PORTAL</p>
    <h1>My Team</h1>
    <p>The people who report to you. This list is read only; HR maintains the records.</p>
  </div>
</section>

<form method="get" style="margin-bottom:16px">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search by ID, name or email" aria-label="Search your team">
  <button class="primary-button" type="submit">Search</button>
  <?php if ($q !== ''): ?><a class="button" href="/manager/team.php">Clear</a><?php endif; ?>
</form>

<div class="panel table-card">
  <div class="panel-head">
    <h2>Team members</h2>
    <span class="muted"><?= count($members) ?> record<?= count($members) === 1 ? '' : 's' ?></span>
  </div>
  <table>
    <thead>
      <tr><th>Employee ID</th><th>Name</th><th>Position</th><th>Department</th><th>Email</th><th>Phone</th><th>Status</th></tr>
    </thead>
    <tbody>
      <?php if (!$members): ?>
        <tr><td colspan="7"><div class="empty-state"><span class="es-ico">♙</span><?= $q !== '' ? 'No one on your team matches that search.' : 'Nobody reports to you yet.' ?></div></td></tr>
      <?php else: foreach ($members as $m):
        $active = strcasecmp((string)$m['employment_status'], 'Active') === 0;
      ?>
        <tr>
          <td><?= h($m['employee_id']) ?></td>
          <td><?= h(fullName($m)) ?></td>
          <td><?= h($m['position_title'] ?? '—') ?></td>
          <td><?= h($m['department_name'] ?? '—') ?></td>
          <td><?= h($m['email'] ?? '—') ?></td>
          <td><?= h($m['phone'] ?? '—') ?></td>
          <td><span class="pill <?= $active ? 'pill-active' : 'pill-inactive' ?>"><?= h($m['employment_status']) ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
  </section>
</main>
</body>
</html>
