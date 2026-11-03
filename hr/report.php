<?php
// hr/reports.php
require_once __DIR__ . '/../authentication/guard.php';
require_role(['admin', 'hr']);
require_once __DIR__ . '/../config/database.php';

// ─── Bubble Sort (DSA) ───────────────────────────────────────────────────────
function bubble_sort_rows(array $rows, string $key, string $dir = 'asc'): array {
    $n   = count($rows);
    $dir = strtolower($dir) === 'desc' ? 'desc' : 'asc';
    for ($i = 0; $i < $n - 1; $i++) {
        for ($j = 0; $j < $n - $i - 1; $j++) {
            $a = $rows[$j][$key]     ?? null;
            $b = $rows[$j + 1][$key] ?? null;
            // numeric compare for count columns
            if (is_numeric($a) && is_numeric($b)) {
                $cmp = ((float)$a) <=> ((float)$b);
            } else {
                $cmp = mb_strtolower((string)$a, 'UTF-8') <=> mb_strtolower((string)$b, 'UTF-8');
            }
            if (($dir === 'asc' && $cmp > 0) || ($dir === 'desc' && $cmp < 0)) {
                [$rows[$j], $rows[$j + 1]] = [$rows[$j + 1], $rows[$j]];
            }
        }
    }
    return $rows;
}

// ─── Detect department name column ───────────────────────────────────────────
function detect_dept_name_column(PDO $pdo): string {
    $cols = $pdo->query('SHOW COLUMNS FROM departments')->fetchAll(PDO::FETCH_COLUMN);
    $colsLower = array_map('strtolower', $cols);
    foreach (['department_name', 'name', 'dept_name', 'title'] as $want) {
        $idx = array_search($want, $colsLower, true);
        if ($idx !== false) return $cols[$idx]; // real casing
    }
    // fallback: first non-id text-ish column
    foreach ($cols as $c) {
        if (strtolower($c) !== 'id') return $c;
    }
    return 'name';
}

// ─── Detect employment status column on employees ────────────────────────────
function detect_status_column(PDO $pdo): ?string {
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM employees')->fetchAll(PDO::FETCH_COLUMN);
        $colsLower = array_map('strtolower', $cols);
        foreach (['employment_status', 'status', 'emp_status'] as $want) {
            $idx = array_search($want, $colsLower, true);
            if ($idx !== false) return $cols[$idx];
        }
    } catch (Throwable $e) {}
    return null;
}

// ─── Inputs ──────────────────────────────────────────────────────────────────
$allowedSort = ['department_name', 'active_count', 'inactive_count', 'total'];
$sortBy      = in_array($_GET['sort_by'] ?? '', $allowedSort, true) ? $_GET['sort_by'] : 'department_name';
$dir         = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$export      = $_GET['export'] ?? '';
$queryError  = '';

// ─── Quick Stats ─────────────────────────────────────────────────────────────
$stats = ['active' => 0, 'inactive' => 0, 'depts' => 0, 'pending_leaves' => 0];
$statusCol = null;
try {
    $statusCol = detect_status_column($pdo);
    $stats['depts'] = (int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn();

    if ($statusCol) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE LOWER(`$statusCol`) = 'active'");
        $st->execute();
        $stats['active'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE LOWER(`$statusCol`) = 'inactive'");
        $st->execute();
        $stats['inactive'] = (int)$st->fetchColumn();
    } else {
        $stats['active'] = (int)$pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn();
    }

    try {
        $stats['pending_leaves'] = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'")->fetchColumn();
    } catch (Throwable $e) {
        $stats['pending_leaves'] = 0;
    }
} catch (Throwable $e) {
    $queryError = 'Stats error: ' . $e->getMessage();
}

// ─── Headcount by Department ─────────────────────────────────────────────────
$headcount = [];
try {
    $deptNameCol = detect_dept_name_column($pdo);

    // Does employees have department_id?
    $empCols = $pdo->query('SHOW COLUMNS FROM employees')->fetchAll(PDO::FETCH_COLUMN);
    $empColsLower = array_map('strtolower', $empCols);
    $hasDeptFk = in_array('department_id', $empColsLower, true);

    if ($hasDeptFk && $statusCol) {
        $sql = "
            SELECT
                d.id,
                d.`{$deptNameCol}` AS department_name,
                COALESCE(SUM(CASE WHEN LOWER(e.`{$statusCol}`) = 'active'   THEN 1 ELSE 0 END), 0) AS active_count,
                COALESCE(SUM(CASE WHEN LOWER(e.`{$statusCol}`) = 'inactive' THEN 1 ELSE 0 END), 0) AS inactive_count,
                COUNT(e.id) AS total
            FROM departments d
            LEFT JOIN employees e ON e.department_id = d.id
            GROUP BY d.id, d.`{$deptNameCol}`
            ORDER BY d.`{$deptNameCol}` ASC
        ";
    } elseif ($hasDeptFk) {
        // no status column — only total
        $sql = "
            SELECT
                d.id,
                d.`{$deptNameCol}` AS department_name,
                COUNT(e.id) AS active_count,
                0 AS inactive_count,
                COUNT(e.id) AS total
            FROM departments d
            LEFT JOIN employees e ON e.department_id = d.id
            GROUP BY d.id, d.`{$deptNameCol}`
            ORDER BY d.`{$deptNameCol}` ASC
        ";
    } else {
        // no FK — just list departments with zero employees
        $sql = "
            SELECT
                d.id,
                d.`{$deptNameCol}` AS department_name,
                0 AS active_count,
                0 AS inactive_count,
                0 AS total
            FROM departments d
            ORDER BY d.`{$deptNameCol}` ASC
        ";
    }

    $headcount = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $headcount = [];
    $queryError = ($queryError ? $queryError . ' | ' : '') . 'Headcount error: ' . $e->getMessage();
}

// Apply bubble sort
$headcount = bubble_sort_rows($headcount, $sortBy, $dir);

// ─── CSV Export ──────────────────────────────────────────────────────────────
if ($export === 'headcount_csv') {
    $fname = 'headcount_by_department_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Department', 'Active', 'Inactive', 'Total']);
    foreach ($headcount as $r) {
        fputcsv($out, [
            $r['department_name'] ?? '',
            $r['active_count']    ?? 0,
            $r['inactive_count']  ?? 0,
            $r['total']           ?? 0,
        ]);
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>HR Reports</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    *{box-sizing:border-box}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f7f7fb;margin:0}
    .wrap{max-width:1100px;margin:28px auto;padding:0 16px}
    h2{margin:0 0 16px}
    .cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:20px}
    .card{background:#fff;border:1px solid #e6e6ef;border-radius:12px;padding:16px;box-shadow:0 4px 12px rgba(0,0,0,.05)}
    .card-label{font-size:13px;color:#6b7280;margin-bottom:6px}
    .card-value{font-size:30px;font-weight:700;color:#111}
    .tcard{background:#fff;border:1px solid #e6e6ef;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.05);overflow:hidden}
    .tcard-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #eee}
    .tcard-header h3{margin:0;font-size:16px}
    .toolbar{display:flex;gap:8px;align-items:center;padding:12px 16px;border-bottom:1px solid #eee;flex-wrap:wrap}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px 14px;text-align:left;border-bottom:1px solid #f0f0f0;font-size:14px}
    th{background:#fafafa;font-weight:600;color:#374151}
    tr:last-child td{border-bottom:none}
    .btn{display:inline-block;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;border:none;cursor:pointer}
    .btn-primary{background:#3b82f6;color:#fff}
    .btn-secondary{background:#6b7280;color:#fff}
    .btn-green{background:#10b981;color:#fff}
    select{padding:7px 10px;border:1px solid #cfd3df;border-radius:8px;font-size:13px}
    .muted{color:#6b7280;font-size:13px}
    .back{margin-top:14px;display:inline-block}
    .error{background:#fee2e2;border:1px solid #fecaca;color:#b91c1c;padding:10px 12px;border-radius:8px;margin-bottom:14px;font-size:13px}
  </style>
</head>
<body>
<div class="wrap">
  <h2>HR Reports</h2>

  <?php if ($queryError): ?>
    <div class="error"><strong>Debug:</strong> <?= htmlspecialchars($queryError) ?></div>
  <?php endif; ?>

  <!-- Quick Stats -->
  <div class="cards">
    <div class="card">
      <div class="card-label">Active Employees</div>
      <div class="card-value"><?= (int)$stats['active'] ?></div>
    </div>
    <div class="card">
      <div class="card-label">Inactive Employees</div>
      <div class="card-value"><?= (int)$stats['inactive'] ?></div>
    </div>
    <div class="card">
      <div class="card-label">Departments</div>
      <div class="card-value"><?= (int)$stats['depts'] ?></div>
    </div>
    <div class="card">
      <div class="card-label">Pending Leaves</div>
      <div class="card-value"><?= (int)$stats['pending_leaves'] ?></div>
    </div>
  </div>

  <!-- Headcount Table -->
  <div class="tcard">
    <div class="tcard-header">
      <h3>Headcount by Department</h3>
      <a class="btn btn-green"
         href="/hr/reports.php?export=headcount_csv&sort_by=<?= urlencode($sortBy) ?>&dir=<?= urlencode($dir) ?>">
        Export CSV
      </a>
    </div>

    <div class="toolbar">
      <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
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
        <button class="btn btn-primary" type="submit">Apply</button>
        <a class="btn btn-secondary" href="/hr/reports.php">Reset</a>
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
              <?php if ($queryError): ?>
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
  </div>

  <a class="btn btn-secondary back" href="/hr/dashboard.php">← Back to HR Dashboard</a>
</div>
</body>
</html>