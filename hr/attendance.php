<?php
require_once __DIR__ . '/../authentication/guard.php';
require_access('dashboard', 'view', null, ['admin', 'hr']);
$title = 'Employee Attendance';

$selectedDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])
    ? (string)$_GET['date']
    : date('Y-m-d');
$scope = (isset($_GET['scope']) && $_GET['scope'] === 'all') ? 'all' : 'date';

$attendanceRows = [];
$previousDates = [];
$dbError = '';

try {
    require_once __DIR__ . '/../config/database.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        // Distinct previous attendance dates (most recent first)
        try {
            $dateStmt = $pdo->query("
                SELECT DISTINCT DATE(time_in) AS att_date
                FROM attendance
                WHERE time_in IS NOT NULL
                ORDER BY att_date DESC
                LIMIT 60
            ");
            foreach ($dateStmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
                if (!empty($d)) {
                    $previousDates[] = (string)$d;
                }
            }
        } catch (Throwable $e) {}

        // Detect optional columns safely
        $hasDeptCol = false;
        $hasPosCol  = false;
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN);
            $hasDeptCol = in_array('department', $cols, true) || in_array('department_name', $cols, true);
            $hasPosCol  = in_array('position', $cols, true) || in_array('position_name', $cols, true);
        } catch (Throwable $e) {}

        $deptExpr = "'—'";
        if ($hasDeptCol) {
            $deptExpr = "COALESCE(NULLIF(TRIM(e.department), ''), '—')";
        } elseif (in_array('department_id', $cols ?? [], true)) {
            $deptExpr = "COALESCE(d.name, '—')";
        }

        $posExpr = "'—'";
        if ($hasPosCol) {
            $posExpr = "COALESCE(NULLIF(TRIM(e.position), ''), '—')";
        } elseif (in_array('position_id', $cols ?? [], true)) {
            $posExpr = "COALESCE(p.title, '—')";
        }

        $joinSql = "LEFT JOIN employees e ON a.employee_id = e.id";
        if ($deptExpr === "COALESCE(d.name, '—')") {
            $joinSql .= " LEFT JOIN departments d ON e.department_id = d.id";
        }
        if ($posExpr === "COALESCE(p.title, '—')") {
            $joinSql .= " LEFT JOIN positions p ON e.position_id = p.id";
        }

        $whereSql = ($scope === 'all')
            ? "1=1"
            : "DATE(a.time_in) = :att_date";

        $sql = "
            SELECT
                a.id AS attendance_id,
                a.employee_id,
                DATE(a.time_in) AS att_date,
                a.time_in,
                a.time_out,
                COALESCE(NULLIF(TRIM(a.status), ''), CASE WHEN a.time_out IS NULL THEN 'Active' ELSE 'Present' END) AS status_label,
                TRIM(CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, ''))) AS employee_name,
                {$deptExpr} AS department_name,
                {$posExpr} AS position_name,
                CASE
                    WHEN a.time_in IS NOT NULL AND a.time_out IS NOT NULL
                    THEN ROUND(TIMESTAMPDIFF(MINUTE, a.time_in, a.time_out) / 60, 2)
                    ELSE NULL
                END AS work_hours
            FROM attendance a
            {$joinSql}
            WHERE {$whereSql}
            ORDER BY a.time_in DESC, a.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        if ($scope !== 'all') {
            $stmt->execute([':att_date' => $selectedDate]);
        } else {
            $stmt->execute();
        }
        $attendanceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

require __DIR__ . '/../templates/hrTemplates/header.php';
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<style>
  .att-page-card {
    background: rgba(15, 23, 42, 0.78);
    border: 1px solid rgba(148, 163, 184, 0.2);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 24px;
    color: #f8fafc;
  }
  .att-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }
  .att-filter-group {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
  }
  .att-input, .att-select {
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid rgba(148, 163, 184, 0.3);
    background: rgba(30, 41, 59, 0.85);
    color: #f8fafc;
    font-size: 13px;
  }
  .att-btn {
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid rgba(96, 165, 250, 0.4);
    background: rgba(37, 99, 235, 0.25);
    color: #eff6ff;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
  }
  .att-btn.active {
    background: #2563eb;
    border-color: #60a5fa;
  }
  .status-pill {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
  }
  .status-pill.active  { background: rgba(16, 185, 129, 0.18); color: #34d399; }
  .status-pill.present { background: rgba(59, 130, 246, 0.18); color: #60a5fa; }
  .status-pill.other   { background: rgba(245, 158, 11, 0.18); color: #fbbf24; }

  /* DataTables dark theme adjustments */
  .dataTables_wrapper .dataTables_length,
  .dataTables_wrapper .dataTables_filter,
  .dataTables_wrapper .dataTables_info,
  .dataTables_wrapper .dataTables_paginate {
    color: #cbd5e1 !important;
    font-size: 12.5px;
    margin-bottom: 10px;
  }
  .dataTables_wrapper .dataTables_filter input,
  .dataTables_wrapper .dataTables_length select {
    background: rgba(30, 41, 59, 0.9) !important;
    color: #f8fafc !important;
    border: 1px solid rgba(148, 163, 184, 0.3) !important;
    border-radius: 6px;
    padding: 4px 8px;
  }
  table.dataTable tbody tr {
    background: rgba(15, 23, 42, 0.6) !important;
    color: #e2e8f0;
  }
  table.dataTable tbody tr:hover {
    background: rgba(30, 41, 59, 0.85) !important;
  }
  table.dataTable thead th {
    color: #94a3b8 !important;
    border-bottom: 1px solid rgba(148, 163, 184, 0.25) !important;
  }
</style>

<div class="att-page-card">
  <div class="att-toolbar">
    <div>
      <p class="eyebrow" style="margin:0 0 4px;">ATTENDANCE TRACKER</p>
      <h1 style="margin:0; font-size:1.5rem;">
        <?php if ($scope === 'all'): ?>
          All Employee Attendance (Today &amp; Previous Dates)
        <?php elseif ($selectedDate === date('Y-m-d')): ?>
          Employee Attendance — Today (<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>)
        <?php else: ?>
          Previous Employee Attendance — <?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
      </h1>
    </div>

    <form method="GET" class="att-filter-group">
      <a
        href="/hr/attendance.php?date=<?= urlencode(date('Y-m-d')) ?>"
        class="att-btn <?= ($scope !== 'all' && $selectedDate === date('Y-m-d')) ? 'active' : '' ?>"
      >Today</a>

      <select name="date" class="att-select" onchange="this.form.submit()" aria-label="Previous attendance dates">
        <option value="">Previous Dates...</option>
        <?php foreach ($previousDates as $prevDate): ?>
          <option value="<?= htmlspecialchars($prevDate, ENT_QUOTES, 'UTF-8') ?>" <?= ($scope !== 'all' && $selectedDate === $prevDate) ? 'selected' : '' ?>>
            <?= htmlspecialchars($prevDate, ENT_QUOTES, 'UTF-8') ?><?= ($prevDate === date('Y-m-d')) ? ' (Today)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>

      <input
        type="date"
        name="date"
        class="att-input"
        value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>"
      >
      <button type="submit" class="att-btn">Filter Date</button>
      <a
        href="/hr/attendance.php?scope=all"
        class="att-btn <?= ($scope === 'all') ? 'active' : '' ?>"
      >All Previous Dates</a>
    </form>
  </div>

  <?php if ($dbError !== ''): ?>
    <p style="color:#f87171;">Database notice: <?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>

  <div style="overflow-x:auto;">
    <table id="employeeAttendanceTable" class="display" style="width:100%;">
      <thead>
        <tr>
          <th>Date</th>
          <th>Employee ID</th>
          <th>Employee Name</th>
          <th>Department</th>
          <th>Position</th>
          <th>Status</th>
          <th>Time In</th>
          <th>Time Out</th>
          <th>Work Hours</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($attendanceRows as $r): ?>
          <?php
            $empName = trim((string)($r['employee_name'] ?? ''));
            if ($empName === '') {
                $empName = 'Employee #' . (int)($r['employee_id'] ?? 0);
            }
            $st = (string)($r['status_label'] ?? 'Present');
            $stLower = strtolower($st);
            $pillClass = str_contains($stLower, 'active') ? 'active' : (str_contains($stLower, 'present') ? 'present' : 'other');
            $hrs = $r['work_hours'];
          ?>
          <tr>
            <td><?= htmlspecialchars((string)($r['att_date'] ?? $selectedDate), ENT_QUOTES, 'UTF-8') ?></td>
            <td>#<?= (int)($r['employee_id'] ?? 0) ?></td>
            <td><strong><?= htmlspecialchars($empName, ENT_QUOTES, 'UTF-8') ?></strong></td>
            <td><?= htmlspecialchars((string)($r['department_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($r['position_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><span class="status-pill <?= $pillClass ?>"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><?= htmlspecialchars((string)($r['time_in'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($r['time_out'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= ($hrs !== null && $hrs !== '') ? htmlspecialchars((string)$hrs, ENT_QUOTES, 'UTF-8') . ' hrs' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
  $(document).ready(function () {
    $('#employeeAttendanceTable').DataTable({
      pageLength: 15,
      order: [[0, 'desc'], [6, 'desc']]
    });
  });
</script>