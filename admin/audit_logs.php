<?php
require_once __DIR__ . '/../authentication/guard.php';
require_access('audit_logs', 'view', 'all');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

// ── Filters ──────────────────────────────────────────────────────────────────
$filterAction     = trim($_GET['action']      ?? '');
$filterEntity     = trim($_GET['entity_type'] ?? '');
$filterUser       = trim($_GET['actor']        ?? '');
$filterDateFrom   = trim($_GET['date_from']   ?? '');
$filterDateTo     = trim($_GET['date_to']     ?? '');
$page             = max(1, (int)($_GET['page'] ?? 1));
$perPage          = 25;
$offset           = ($page - 1) * $perPage;
$isExport         = isset($_GET['export']) && $_GET['export'] === 'csv';

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($filterAction !== '') {
    $where[]              = 'al.action LIKE :action';
    $params[':action']    = '%' . $filterAction . '%';
}
if ($filterEntity !== '') {
    $where[]              = 'al.entity_type = :entity_type';
    $params[':entity_type'] = $filterEntity;
}
if ($filterUser !== '') {
    $where[]              = '(u.username LIKE :actor OR u.id = :actor_id)';
    $params[':actor']     = '%' . $filterUser . '%';
    $params[':actor_id']  = is_numeric($filterUser) ? (int)$filterUser : 0;
}
if ($filterDateFrom !== '') {
    $where[]                = 'DATE(al.created_at) >= :date_from';
    $params[':date_from']   = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $where[]              = 'DATE(al.created_at) <= :date_to';
    $params[':date_to']   = $filterDateTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Distinct action and entity values for filter dropdowns ───────────────────
$allActions  = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")
    ->fetchAll(PDO::FETCH_COLUMN);
$allEntities = $pdo->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type")
    ->fetchAll(PDO::FETCH_COLUMN);

// ── Total count ───────────────────────────────────────────────────────────────
$countSql = "
    SELECT COUNT(*)
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.actor_user_id
    $whereSql
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// ── Fetch rows ────────────────────────────────────────────────────────────────
$dataSql = "
    SELECT
        al.id,
        al.actor_user_id,
        u.username          AS actor_username,
        al.action,
        al.entity_type,
        al.entity_id,
        al.details,
        al.created_at
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.actor_user_id
    $whereSql
    ORDER BY al.id DESC
";

if ($isExport) {
    // Export ALL matching rows — no limit
    $dataStmt = $pdo->prepare($dataSql);
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="audit_trail_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Actor User ID', 'Actor Username', 'Action', 'Entity Type', 'Entity ID', 'Details', 'Timestamp']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'],
            $row['actor_user_id'] ?? 'System',
            $row['actor_username'] ?? '—',
            $row['action'],
            $row['entity_type'],
            $row['entity_id'] ?? '—',
            $row['details'],
            $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// Paginated
$dataSql .= " LIMIT :limit OFFSET :offset";
$dataStmt = $pdo->prepare($dataSql);
foreach ($params as $k => $v) {
    $dataStmt->bindValue($k, $v);
}
$dataStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$dataStmt->execute();
$logs = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Action badge colour map ───────────────────────────────────────────────────
function auditBadgeClass(string $action): string {
    if (str_contains($action, 'login.failed'))    return 'badge-danger';
    if (str_contains($action, 'login'))           return 'badge-success';
    if (str_contains($action, 'delete')
     || str_contains($action, 'deactivate'))      return 'badge-danger';
    if (str_contains($action, 'create')
     || str_contains($action, 'activate'))        return 'badge-success';
    if (str_contains($action, 'approve'))         return 'badge-success';
    if (str_contains($action, 'reject')
     || str_contains($action, 'cancel'))          return 'badge-warning';
    if (str_contains($action, 'update')
     || str_contains($action, 'status_change'))   return 'badge-info';
    return 'badge-neutral';
}

// ── Current query string for pagination links ─────────────────────────────────
function auditPageUrl(int $p): string {
    $q = $_GET;
    $q['page'] = $p;
    unset($q['export']);
    return '/admin/audit_logs.php?' . http_build_query($q);
}

require __DIR__ . '/../templates/adminTemplates/header.php';
?>

<div class="audit-toolbar">
  <form method="get" action="/admin/audit_logs.php" class="audit-filters">
    <input  type="hidden" name="page" value="1">

    <select name="action" class="filter-select">
      <option value="">All Actions</option>
      <?php foreach ($allActions as $a): ?>
        <option value="<?= h($a) ?>" <?= $filterAction === $a ? 'selected' : '' ?>>
          <?= h($a) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="entity_type" class="filter-select">
      <option value="">All Entities</option>
      <?php foreach ($allEntities as $e): ?>
        <option value="<?= h($e) ?>" <?= $filterEntity === $e ? 'selected' : '' ?>>
          <?= h($e) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <input type="text"  name="actor"     class="filter-input" placeholder="Actor username or ID" value="<?= h($filterUser) ?>">
    <input type="date"  name="date_from" class="filter-input" value="<?= h($filterDateFrom) ?>">
    <input type="date"  name="date_to"   class="filter-input" value="<?= h($filterDateTo) ?>">

    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="/admin/audit_logs.php" class="btn btn-ghost btn-sm">Reset</a>
  </form>

  <a href="<?= h('/admin/audit_logs.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"
     class="btn btn-outline btn-sm">
    ⬇ Export CSV
  </a>
</div>

<div class="audit-meta">
  Showing <strong><?= number_format(count($logs)) ?></strong>
  of <strong><?= number_format($totalRows) ?></strong> records
</div>

<div class="table-wrapper">
  <table class="data-table audit-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Timestamp</th>
        <th>Actor</th>
        <th>Action</th>
        <th>Entity</th>
        <th>Entity ID</th>
        <th>Details</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($logs)): ?>
      <tr><td colspan="7" class="empty-state">No audit records match your filters.</td></tr>
    <?php else: ?>
      <?php foreach ($logs as $log): ?>
      <?php
        $details = [];
        if (!empty($log['details'])) {
            $details = json_decode($log['details'], true) ?? [];
        }
        // Remove noisy fields from details preview
        unset($details['ip'], $details['user_agent']);
        $detailsPreview = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '—';
      ?>
      <tr>
        <td class="col-id"><?= (int)$log['id'] ?></td>
        <td class="col-ts"><span class="ts"><?= h($log['created_at']) ?></span></td>
        <td class="col-actor">
          <?php if ($log['actor_username']): ?>
            <span class="actor-name"><?= h($log['actor_username']) ?></span>
            <span class="actor-id">#<?= (int)$log['actor_user_id'] ?></span>
          <?php else: ?>
            <span class="actor-system">System</span>
          <?php endif; ?>
        </td>
        <td class="col-action">
          <span class="badge <?= auditBadgeClass($log['action']) ?>">
            <?= h($log['action']) ?>
          </span>
        </td>
        <td class="col-entity"><?= h($log['entity_type']) ?></td>
        <td class="col-eid"><?= $log['entity_id'] !== null ? (int)$log['entity_id'] : '—' ?></td>
        <td class="col-details">
          <?php if ($details): ?>
          <details>
            <summary>View</summary>
            <pre class="details-json"><?= h($detailsPreview) ?></pre>
          </details>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
  <?php if ($page > 1): ?>
    <a href="<?= auditPageUrl($page - 1) ?>" class="page-btn">← Prev</a>
  <?php endif; ?>

  <?php
    $start = max(1, $page - 2);
    $end   = min($totalPages, $page + 2);
    for ($i = $start; $i <= $end; $i++):
  ?>
    <a href="<?= auditPageUrl($i) ?>"
       class="page-btn <?= $i === $page ? 'active' : '' ?>">
      <?= $i ?>
    </a>
  <?php endfor; ?>

  <?php if ($page < $totalPages): ?>
    <a href="<?= auditPageUrl($page + 1) ?>" class="page-btn">Next →</a>
  <?php endif; ?>
</div>
<?php endif; ?>

</section></main></body></html>