<?php
/**
 * Shared Reports presentation.
 *
 * Expects $stats, $headcount, $sortBy, $dir, $queryError from _report_data.php.
 * Set $reportEmbedded = true before including to render the compact,
 * dashboard-embedded variant (no page <h1>, filter form posts back to the
 * dashboard, CSV export still points at the standalone report route).
 */
$reportEmbedded = $reportEmbedded ?? false;
$reportFormAction = $reportEmbedded ? '/hr/dashboard.php' : '/hr/report.php';
$reportResetHref = $reportEmbedded ? '/hr/dashboard.php' : '/hr/report.php';
?>

<?php if (!empty($queryError)): ?>
  <div class="error"><strong>Debug:</strong> <?= htmlspecialchars($queryError) ?></div>
<?php endif; ?>

<!-- Quick Stats -->
<div class="metric-strip">
  <article class="metric-card">
    <span class="metric-title">Active Employees</span>
    <strong class="metric-number green"><?= (int)$stats['active'] ?></strong>
    <span class="metric-description">Currently marked active</span>
  </article>
  <article class="metric-card">
    <span class="metric-title">Inactive Employees</span>
    <strong class="metric-number"><?= (int)$stats['inactive'] ?></strong>
    <span class="metric-description">Currently marked inactive</span>
  </article>
  <article class="metric-card">
    <span class="metric-title">Departments</span>
    <strong class="metric-number purple"><?= (int)$stats['depts'] ?></strong>
    <span class="metric-description">Organization departments</span>
  </article>
  <article class="metric-card">
    <span class="metric-title">Pending Leaves</span>
    <strong class="metric-number blue"><?= (int)$stats['pending_leaves'] ?></strong>
    <span class="metric-description">Awaiting review</span>
  </article>
</div>

<!-- Headcount Table -->
<article class="card report-card">
  <div class="card-heading">
    <h2>Headcount by Department</h2>
    <form method="get" action="<?= htmlspecialchars($reportFormAction) ?>" class="report-toolbar">
      <label class="muted">Sort by
        <select name="sort_by">
          <option value="department_name" <?= $sortBy === 'department_name' ? 'selected' : '' ?>>Department</option>
          <option value="active_count"    <?= $sortBy === 'active_count'    ? 'selected' : '' ?>>Active</option>
          <option value="inactive_count"  <?= $sortBy === 'inactive_count'  ? 'selected' : '' ?>>Inactive</option>
          <option value="total"           <?= $sortBy === 'total'           ? 'selected' : '' ?>>Total</option>
        </select>
      </label>
      <label class="muted">Direction
        <select name="dir">
          <option value="asc"  <?= $dir === 'asc'  ? 'selected' : '' ?>>ASC</option>
          <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>DESC</option>
        </select>
      </label>
      <button class="primary-button" type="submit">Apply</button>
      <a class="button" href="<?= htmlspecialchars($reportResetHref) ?>">Reset</a>
      <?php if ($reportEmbedded): ?>
        <?php /* Only when embedded in the dashboard: the standalone Reports page already has its own Export CSV button in the page header. */ ?>
        <a class="button" href="/hr/report.php?export=headcount_csv&sort_by=<?= urlencode($sortBy) ?>&dir=<?= urlencode($dir) ?>">⭳ Export CSV</a>
      <?php endif; ?>
    </form>
  </div>

  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Department</th>
        <th>Active</th>
        <th>Inactive</th>
        <th>Total</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($headcount)): ?>
        <tr>
          <td colspan="5" class="muted" style="text-align:center;padding:20px">
            No departments found.
            <?php if (!empty($queryError)): ?>
              <br><small>See the red debug message above for the SQL error.</small>
            <?php endif; ?>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($headcount as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars((string)($r['department_name'] ?? '')) ?></td>
            <td><?= (int)($r['active_count']   ?? 0) ?></td>
            <td><?= (int)($r['inactive_count'] ?? 0) ?></td>
            <td><?= (int)($r['total']          ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</article>
