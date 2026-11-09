<?php
// Include-only: requesting this file by URL gives a 404.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}
/**
 * Shared Reports data layer.
 *
 * Computes the HR reporting figures (quick stats + headcount by department)
 * and exposes them as $stats, $headcount, $sortBy, $dir and $queryError so
 * the same numbers can be rendered both on the standalone Reports page
 * (hr/report.php) and embedded inside the HR dashboard.
 *
 * Requires an open $pdo (config/database.php) before inclusion.
 *
 * Set $REPORT_ALLOW_EXPORT = false before including to disable the CSV
 * download branch (used when embedding, where exit would kill the host page).
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    // Nothing to compute without a connection; provide safe defaults.
    $stats = ['active' => 0, 'inactive' => 0, 'depts' => 0, 'pending_leaves' => 0];
    $headcount = [];
    $sortBy = 'department_name';
    $dir = 'asc';
    $queryError = 'No database connection available.';
    return;
}

$REPORT_ALLOW_EXPORT = $REPORT_ALLOW_EXPORT ?? true;

// merge_sort_numeric(), used below to sort the headcount, is defined here.
// (Without this include the Reports page and the dashboard report section crash.)
require_once __DIR__ . '/../algorithms/merge_sort.php';

// ─── Bubble Sort (DSA) ───────────────────────────────────────────────────────
if (!function_exists('bubble_sort_rows')) {
    function bubble_sort_rows(array $rows, string $key, string $dir = 'asc'): array {
        $n   = count($rows);
        $dir = strtolower($dir) === 'desc' ? 'desc' : 'asc';
        for ($i = 0; $i < $n - 1; $i++) {
            for ($j = 0; $j < $n - $i - 1; $j++) {
                $a = $rows[$j][$key]     ?? null;
                $b = $rows[$j + 1][$key] ?? null;
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
}

if (!function_exists('detect_dept_name_column')) {
    function detect_dept_name_column(PDO $pdo): string {
        $cols = $pdo->query('SHOW COLUMNS FROM departments')->fetchAll(PDO::FETCH_COLUMN);
        $colsLower = array_map('strtolower', $cols);
        foreach (['department_name', 'name', 'dept_name', 'title'] as $want) {
            $idx = array_search($want, $colsLower, true);
            if ($idx !== false) return $cols[$idx];
        }
        foreach ($cols as $c) {
            if (strtolower($c) !== 'id') return $c;
        }
        return 'name';
    }
}

if (!function_exists('detect_status_column')) {
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
if ($REPORT_ALLOW_EXPORT && $export === 'headcount_csv') {
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
