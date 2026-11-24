<?php
// hr/attendance_reports.php

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../authentication/guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/schedule_evaluator.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$role = strtolower(trim((string)($_SESSION['role'] ?? 'employee')));
if (!in_array($role, ['hr', 'admin', 'manager'], true)) {
    header('Location: /employee/dashboard.php');
    exit;
}

try {
    $pdo->exec("SET time_zone = '+08:00'");
} catch (Throwable $e) {}

if (!function_exists('ar_has_table')) {
    function ar_has_table(PDO $pdo, string $table): bool {
        static $cache = [];
        if (array_key_exists($table, $cache)) return $cache[$table];
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
            $stmt->execute([':t' => $table]);
            $cache[$table] = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}

if (!function_exists('ar_has_col')) {
    function ar_has_col(PDO $pdo, string $table, string $col): bool {
        static $cache = [];
        $key = $table . '.' . $col;
        if (array_key_exists($key, $cache)) return $cache[$key];
        if (!ar_has_table($pdo, $table)) {
            $cache[$key] = false;
            return false;
        }
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :c");
            $stmt->execute([':c' => $col]);
            $cache[$key] = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

if (!function_exists('ar_dept_sql_meta')) {
    function ar_dept_sql_meta(PDO $pdo): array {
        $hasDeptCol = ar_has_col($pdo, 'employees', 'department');
        $hasDeptId  = ar_has_col($pdo, 'employees', 'department_id');
        $hasDeptTbl = ar_has_table($pdo, 'departments');

        if ($hasDeptId && $hasDeptTbl) {
            $dNameCol = ar_has_col($pdo, 'departments', 'name')
                ? 'name'
                : (ar_has_col($pdo, 'departments', 'department_name') ? 'department_name' : null);
            if ($dNameCol !== null) {
                $fallback = $hasDeptCol ? "NULLIF(TRIM(e.`department`), '')" : "NULL";
                return [
                    'dept_expr' => "COALESCE(NULLIF(TRIM(d.`{$dNameCol}`), ''), {$fallback}, 'Unassigned')",
                    'join_dept' => "LEFT JOIN `departments` d ON d.`id` = e.`department_id`",
                ];
            }
        }
        if ($hasDeptCol) {
            return [
                'dept_expr' => "COALESCE(NULLIF(TRIM(e.`department`), ''), 'Unassigned')",
                'join_dept' => "",
            ];
        }
        return [
            'dept_expr' => "'Unassigned'",
            'join_dept' => "",
        ];
    }
}

$today = date('Y-m-d');
$preset = trim((string)($_GET['preset'] ?? 'custom'));

if ($preset === 'cutoff_1') {
    $fromDate = date('Y-m-01', strtotime($today));
    $toDate   = date('Y-m-15', strtotime($today));
} elseif ($preset === 'cutoff_2') {
    $fromDate = date('Y-m-16', strtotime($today));
    $toDate   = date('Y-m-t', strtotime($today));
} elseif ($preset === 'month') {
    $fromDate = date('Y-m-01', strtotime($today));
    $toDate   = date('Y-m-t', strtotime($today));
} else {
    $rawFrom = trim((string)($_GET['from_date'] ?? ''));
    $rawTo   = trim((string)($_GET['to_date'] ?? ''));
    $fromDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom) ? $rawFrom : date('Y-m-01', strtotime($today));
    $toDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTo) ? $rawTo : $today;
}

if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$deptFilter    = trim((string)($_GET['department'] ?? ''));
$empIdFilter   = max(0, (int)($_GET['employee_id'] ?? 0));
$anomalyFilter = trim((string)($_GET['anomaly_type'] ?? ''));
$searchQ       = trim((string)($_GET['q'] ?? ''));

function ar_format_mins(int $totalMinutes): string {
    $mins = max(0, $totalMinutes);
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    if ($h === 0) return $m . 'm';
    return $h . 'h ' . $m . 'm';
}

function ar_time_to_seconds(?string $timeStr): ?int {
    $t = trim((string)$timeStr);
    if ($t === '' || $t === '00:00:00' || $t === '-') return null;
    if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', $t, $m)) {
        return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (int)($m[3] ?? 0);
    }
    $ts = strtotime($t);
    if ($ts === false) return null;
    return ((int)date('H', $ts) * 3600) + ((int)date('i', $ts) * 60) + (int)date('s', $ts);
}

function ar_fetch_employees(PDO $pdo, string $deptFilter = ''): array {
    if (!ar_has_table($pdo, 'employees')) return [];
    $deptMeta = ar_dept_sql_meta($pdo);
    $hasCode  = ar_has_col($pdo, 'employees', 'employee_code');
    $hasFirst = ar_has_col($pdo, 'employees', 'first_name');
    $hasLast  = ar_has_col($pdo, 'employees', 'last_name');
    $hasFull  = ar_has_col($pdo, 'employees', 'full_name');
    $hasName  = ar_has_col($pdo, 'employees', 'name');
    $hasStat  = ar_has_col($pdo, 'employees', 'status');

    if ($hasFirst && $hasLast) {
        $nameExpr = "TRIM(CONCAT(COALESCE(e.`first_name`, ''), ' ', COALESCE(e.`last_name`, '')))";
    } elseif ($hasFull) {
        $nameExpr = "COALESCE(NULLIF(TRIM(e.`full_name`), ''), CONCAT('Employee #', e.`id`))";
    } elseif ($hasName) {
        $nameExpr = "COALESCE(NULLIF(TRIM(e.`name`), ''), CONCAT('Employee #', e.`id`))";
    } else {
        $nameExpr = "CONCAT('Employee #', e.`id`)";
    }

    $codeExpr = $hasCode ? "COALESCE(e.`employee_code`, '')" : "''";
    $wheres = [];
    $params = [];
    if ($hasStat) {
        $wheres[] = "LOWER(COALESCE(e.`status`, 'active')) NOT IN ('inactive', 'terminated', 'resigned')";
    }
    if ($deptFilter !== '') {
        $wheres[] = "LOWER({$deptMeta['dept_expr']}) = LOWER(:dept)";
        $params[':dept'] = $deptFilter;
    }
    $whereSql = !empty($wheres) ? ('WHERE ' . implode(' AND ', $wheres)) : '';

    try {
        $stmt = $pdo->prepare("
            SELECT e.`id` AS employee_id,
                   {$codeExpr} AS employee_code,
                   {$nameExpr} AS emp_name,
                   {$deptMeta['dept_expr']} AS department_name
            FROM `employees` e
            {$deptMeta['join_dept']}
            {$whereSql}
            ORDER BY emp_name ASC
        ");
        $stmt->execute($params);
        $list = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $eid = (int)$r['employee_id'];
            if ($eid <= 0) continue;
            $list[$eid] = [
                'employee_id'     => $eid,
                'employee_code'   => (string)$r['employee_code'],
                'emp_name'        => trim((string)$r['emp_name']) ?: ('Employee #' . $eid),
                'department_name' => trim((string)$r['department_name']) ?: 'Unassigned',
            ];
        }
        return $list;
    } catch (Throwable $e) {
        return [];
    }
}

function ar_load_schedules_lookup(PDO $pdo): array {
    // Returns ['by_emp_dow' => [eid][dow] => [...], 'by_emp_date' => [eid][Y-m-d] => [...]]
    $lookup = ['by_emp_dow' => [], 'by_emp_date' => []];
    $candidates = ['employee_schedules', 'schedules', 'shift_schedules', 'work_schedules'];

    foreach ($candidates as $tbl) {
        if (!ar_has_table($pdo, $tbl) || !ar_has_col($pdo, $tbl, 'employee_id')) {
            continue;
        }
        $hasStart = ar_has_col($pdo, $tbl, 'start_time') || ar_has_col($pdo, $tbl, 'shift_start') || ar_has_col($pdo, $tbl, 'time_in');
        $hasEnd   = ar_has_col($pdo, $tbl, 'end_time') || ar_has_col($pdo, $tbl, 'shift_end') || ar_has_col($pdo, $tbl, 'time_out');
        $startCol = ar_has_col($pdo, $tbl, 'start_time') ? 'start_time' : (ar_has_col($pdo, $tbl, 'shift_start') ? 'shift_start' : 'time_in');
        $endCol   = ar_has_col($pdo, $tbl, 'end_time') ? 'end_time' : (ar_has_col($pdo, $tbl, 'shift_end') ? 'shift_end' : 'time_out');

        $hasRest  = ar_has_col($pdo, $tbl, 'is_rest_day');
        $hasType  = ar_has_col($pdo, $tbl, 'day_type') || ar_has_col($pdo, $tbl, 'shift_type');
        $typeCol  = ar_has_col($pdo, $tbl, 'day_type') ? 'day_type' : 'shift_type';

        $hasDow   = ar_has_col($pdo, $tbl, 'day_of_week') || ar_has_col($pdo, $tbl, 'weekday');
        $dowCol   = ar_has_col($pdo, $tbl, 'day_of_week') ? 'day_of_week' : 'weekday';

        $hasDate  = ar_has_col($pdo, $tbl, 'schedule_date') || ar_has_col($pdo, $tbl, 'work_date') || ar_has_col($pdo, $tbl, 'date');
        $dateCol  = ar_has_col($pdo, $tbl, 'schedule_date') ? 'schedule_date' : (ar_has_col($pdo, $tbl, 'work_date') ? 'work_date' : 'date');

        $cols = ['`employee_id`'];
        if ($hasStart) $cols[] = "`{$startCol}` AS sched_start";
        if ($hasEnd)   $cols[] = "`{$endCol}` AS sched_end";
        if ($hasRest)  $cols[] = "`is_rest_day`";
        if ($hasType)  $cols[] = "`{$typeCol}` AS day_type";
        if ($hasDow)   $cols[] = "`{$dowCol}` AS dow_val";
        if ($hasDate)  $cols[] = "DATE(`{$dateCol}`) AS sched_date";

        try {
            $rows = $pdo->query("SELECT " . implode(', ', $cols) . " FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $eid = (int)$r['employee_id'];
                if ($eid <= 0) continue;
                $sStart = trim((string)($r['sched_start'] ?? ''));
                $sEnd   = trim((string)($r['sched_end'] ?? ''));
                $isRest = !empty($r['is_rest_day']) || stripos((string)($r['day_type'] ?? ''), 'rest') !== false || ($sStart === '' && $sEnd === '' && ($hasStart || $hasRest));
                $meta = [
                    'start_time'  => $sStart,
                    'end_time'    => $sEnd,
                    'is_rest_day' => $isRest,
                ];
                if (!empty($r['sched_date'])) {
                    $lookup['by_emp_date'][$eid][(string)$r['sched_date']] = $meta;
                } elseif (isset($r['dow_val']) && $r['dow_val'] !== '') {
                    $rawDow = strtolower(trim((string)$r['dow_val']));
                    $dowMap = [
                        '0' => 0, '7' => 0, 'sun' => 0, 'sunday' => 0,
                        '1' => 1, 'mon' => 1, 'monday' => 1,
                        '2' => 2, 'tue' => 2, 'tuesday' => 2,
                        '3' => 3, 'wed' => 3, 'wednesday' => 3,
                        '4' => 4, 'thu' => 4, 'thursday' => 4,
                        '5' => 5, 'fri' => 5, 'friday' => 5,
                        '6' => 6, 'sat' => 6, 'saturday' => 6,
                    ];
                    if (isset($dowMap[$rawDow])) {
                        $lookup['by_emp_dow'][$eid][$dowMap[$rawDow]] = $meta;
                    }
                }
            }
            if (!empty($lookup['by_emp_date']) || !empty($lookup['by_emp_dow'])) {
                break;
            }
        } catch (Throwable $e) {}
    }

    return $lookup;
}

function ar_resolve_schedule(array $schedLookup, int $eid, string $workDate): ?array {
    if (isset($schedLookup['by_emp_date'][$eid][$workDate])) {
        return $schedLookup['by_emp_date'][$eid][$workDate];
    }
    $ts = strtotime($workDate);
    if ($ts !== false) {
        $dow = (int)date('w', $ts);
        if (isset($schedLookup['by_emp_dow'][$eid][$dow])) {
            return $schedLookup['by_emp_dow'][$eid][$dow];
        }
    }
    return null;
}

function ar_build_report(
    PDO $pdo,
    string $fromDate,
    string $toDate,
    string $today,
    string $deptFilter,
    int $empIdFilter,
    string $anomalyFilter,
    string $searchQ
): array {
    $departments = [];
    if (ar_has_table($pdo, 'employees')) {
        $deptMeta = ar_dept_sql_meta($pdo);
        $dSet = [];
        try {
            $dRows = $pdo->query("
                SELECT DISTINCT {$deptMeta['dept_expr']} AS dept_name
                FROM `employees` e
                {$deptMeta['join_dept']}
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dRows as $dr) {
                $dn = trim((string)($dr['dept_name'] ?? ''));
                if ($dn !== '') $dSet[$dn] = $dn;
            }
        } catch (Throwable $e) {}
        ksort($dSet, SORT_NATURAL | SORT_FLAG_CASE);
        $departments = array_values($dSet);
    }

    $allEmployees = ar_fetch_employees($pdo, '');
    $filteredEmployees = ar_fetch_employees($pdo, $deptFilter);
    if ($empIdFilter > 0) {
        $filteredEmployees = isset($filteredEmployees[$empIdFilter]) ? [$empIdFilter => $filteredEmployees[$empIdFilter]] : [];
    }

    // Leave lookup: [Y-m-d][eid] => ['status' => ..., 'leave_type' => ...]
    $leaveLookup = [];
    if (
        ar_has_table($pdo, 'leave_requests') &&
        ar_has_col($pdo, 'leave_requests', 'employee_id') &&
        ar_has_col($pdo, 'leave_requests', 'start_date') &&
        ar_has_col($pdo, 'leave_requests', 'end_date')
    ) {
        $hasStatus = ar_has_col($pdo, 'leave_requests', 'status');
        $hasType   = ar_has_col($pdo, 'leave_requests', 'leave_type') || ar_has_col($pdo, 'leave_requests', 'type');
        $typeCol   = ar_has_col($pdo, 'leave_requests', 'leave_type') ? 'leave_type' : 'type';

        $selStatus = $hasStatus ? "LOWER(COALESCE(`status`, 'pending')) AS l_status" : "'approved' AS l_status";
        $selType   = $hasType ? "COALESCE(`{$typeCol}`, 'Leave') AS l_type" : "'Leave' AS l_type";

        try {
            $lStmt = $pdo->prepare("
                SELECT `employee_id`, DATE(`start_date`) AS start_d, DATE(`end_date`) AS end_d, {$selStatus}, {$selType}
                FROM `leave_requests`
                WHERE DATE(`start_date`) <= :to_d
                  AND DATE(`end_date`) >= :from_d
            ");
            $lStmt->execute([':from_d' => $fromDate, ':to_d' => $toDate]);
            foreach ($lStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                $st = trim((string)$lr['l_status']);
                if (in_array($st, ['rejected', 'cancelled', 'declined'], true)) {
                    continue;
                }
                $eid = (int)$lr['employee_id'];
                $s = max($fromDate, (string)$lr['start_d']);
                $e = min($toDate, (string)$lr['end_d']);
                $cur = strtotime($s);
                $last = strtotime($e);
                while ($cur !== false && $last !== false && $cur <= $last) {
                    $leaveLookup[date('Y-m-d', $cur)][$eid] = [
                        'status'     => $st ?: 'approved',
                        'leave_type' => trim((string)$lr['l_type']) ?: 'Leave',
                    ];
                    $cur = strtotime('+1 day', $cur);
                }
            }
        } catch (Throwable $e) {}
    }

    // Overtime lookup: [Y-m-d][eid] => approved_minutes
    $otLookup = [];
    foreach (['overtime_requests', 'overtimes', 'overtime_logs'] as $otTbl) {
        if (!ar_has_table($pdo, $otTbl) || !ar_has_col($pdo, $otTbl, 'employee_id')) {
            continue;
        }
        $dCol = ar_has_col($pdo, $otTbl, 'overtime_date')
            ? 'overtime_date'
            : (ar_has_col($pdo, $otTbl, 'work_date') ? 'work_date' : (ar_has_col($pdo, $otTbl, 'date') ? 'date' : null));
        if ($dCol === null) continue;

        $hasMins = ar_has_col($pdo, $otTbl, 'overtime_minutes') || ar_has_col($pdo, $otTbl, 'minutes');
        $hasHrs  = ar_has_col($pdo, $otTbl, 'overtime_hours') || ar_has_col($pdo, $otTbl, 'hours');
        $hasSt   = ar_has_col($pdo, $otTbl, 'status');

        $mExpr = $hasMins
            ? (ar_has_col($pdo, $otTbl, 'overtime_minutes') ? "COALESCE(`overtime_minutes`, 0)" : "COALESCE(`minutes`, 0)")
            : ($hasHrs
                ? (ar_has_col($pdo, $otTbl, 'overtime_hours') ? "ROUND(COALESCE(`overtime_hours`, 0) * 60)" : "ROUND(COALESCE(`hours`, 0) * 60)")
                : "0");
        $stWhere = $hasSt ? "AND LOWER(COALESCE(`status`, '')) = 'approved'" : "";

        try {
            $oStmt = $pdo->prepare("
                SELECT `employee_id`, DATE(`{$dCol}`) AS ot_d, {$mExpr} AS ot_mins
                FROM `{$otTbl}`
                WHERE DATE(`{$dCol}`) BETWEEN :from_d AND :to_d
                  {$stWhere}
            ");
            $oStmt->execute([':from_d' => $fromDate, ':to_d' => $toDate]);
            foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $or) {
                $eid = (int)$or['employee_id'];
                $d   = (string)$or['ot_d'];
                if ($eid > 0 && $d !== '') {
                    $otLookup[$d][$eid] = ($otLookup[$d][$eid] ?? 0) + max(0, (int)$or['ot_mins']);
                }
            }
            break;
        } catch (Throwable $e) {}
    }

    $schedLookup = ar_load_schedules_lookup($pdo);

    // Fetch attendance logs
    $attTable = null;
    foreach (['attendance_logs', 'attendance_records', 'attendance'] as $cand) {
        if (ar_has_table($pdo, $cand)) {
            $attTable = $cand;
            break;
        }
    }

    $attRows = [];
    if ($attTable !== null && ar_has_col($pdo, $attTable, 'employee_id')) {
        $dateCol = ar_has_col($pdo, $attTable, 'work_date')
            ? 'work_date'
            : (ar_has_col($pdo, $attTable, 'attendance_date')
                ? 'attendance_date'
                : (ar_has_col($pdo, $attTable, 'date') ? 'date' : null));

        if ($dateCol !== null) {
            $inCol   = ar_has_col($pdo, $attTable, 'time_in')
                ? "a.`time_in`"
                : (ar_has_col($pdo, $attTable, 'clock_in') ? "a.`clock_in`" : "NULL");
            $outCol  = ar_has_col($pdo, $attTable, 'time_out')
                ? "a.`time_out`"
                : (ar_has_col($pdo, $attTable, 'clock_out') ? "a.`clock_out`" : "NULL");
            $stCol   = ar_has_col($pdo, $attTable, 'status') ? "COALESCE(a.`status`, 'Pending')" : "'Pending'";
            $lateCol = ar_has_col($pdo, $attTable, 'late_minutes') ? "COALESCE(a.`late_minutes`, 0)" : "0";
            $utCol   = ar_has_col($pdo, $attTable, 'undertime_minutes') ? "COALESCE(a.`undertime_minutes`, 0)" : "0";
            $otCol   = ar_has_col($pdo, $attTable, 'overtime_minutes') ? "COALESCE(a.`overtime_minutes`, 0)" : "0";
            $noteCol = ar_has_col($pdo, $attTable, 'notes')
                ? "COALESCE(a.`notes`, '')"
                : (ar_has_col($pdo, $attTable, 'remarks') ? "COALESCE(a.`remarks`, '')" : "''");

            try {
                $stmt = $pdo->prepare("
                    SELECT a.`employee_id`,
                           DATE(a.`{$dateCol}`) AS work_date,
                           {$inCol} AS time_in,
                           {$outCol} AS time_out,
                           {$stCol} AS status,
                           {$lateCol} AS late_minutes,
                           {$utCol} AS undertime_minutes,
                           {$otCol} AS overtime_minutes,
                           {$noteCol} AS notes
                    FROM `{$attTable}` a
                    WHERE DATE(a.`{$dateCol}`) BETWEEN :from_d AND :to_d
                ");
                $stmt->execute([':from_d' => $fromDate, ':to_d' => $toDate]);
                $attRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
        }
    }

    // Initialize employee rollups
    $empRollups = [];
    foreach ($filteredEmployees as $eid => $emp) {
        if ($searchQ !== '') {
            $needle = strtolower($searchQ);
            $hay = strtolower($emp['emp_name'] . ' ' . $emp['employee_code'] . ' ' . $emp['department_name'] . ' ' . $eid);
            if (strpos($hay, $needle) === false) {
                continue;
            }
        }
        $empRollups[$eid] = [
            'employee_id'     => $eid,
            'employee_code'   => $emp['employee_code'],
            'emp_name'        => $emp['emp_name'],
            'department_name' => $emp['department_name'],
            'present_days'    => 0,
            'late_days'       => 0,
            'absent_days'     => 0,
            'leave_days'      => 0,
            'late_mins'       => 0,
            'undertime_mins'  => 0,
            'overtime_mins'   => 0,
            'worked_mins'     => 0,
            'anomaly_count'   => 0,
        ];
    }

    // Count approved leave days per employee in range
    foreach ($leaveLookup as $d => $empMap) {
        foreach ($empMap as $eid => $lInfo) {
            if (!isset($empRollups[$eid])) continue;
            if (in_array($lInfo['status'], ['approved', 'active'], true)) {
                $empRollups[$eid]['leave_days']++;
            }
        }
    }

    // Add approved overtime from overtime table if any
    foreach ($otLookup as $d => $empMap) {
        foreach ($empMap as $eid => $mins) {
            if (!isset($empRollups[$eid])) continue;
            $empRollups[$eid]['overtime_mins'] += $mins;
        }
    }

    $anomalies = [];
    $missingOutCount = 0;
    $schedMismatchCount = 0;
    $leaveOverlapCount = 0;

    foreach ($attRows as $ar) {
        $eid = (int)($ar['employee_id'] ?? 0);
        $wd  = (string)($ar['work_date'] ?? '');
        if ($eid <= 0 || $wd === '' || !isset($empRollups[$eid])) {
            continue;
        }

        $emp     = $empRollups[$eid];
        $status  = trim((string)($ar['status'] ?? 'Pending'));
        $tInRaw  = trim((string)($ar['time_in'] ?? ''));
        $tOutRaw = trim((string)($ar['time_out'] ?? ''));
        $inSec   = ar_time_to_seconds($tInRaw);
        $outSec  = ar_time_to_seconds($tOutRaw);

        $lateMins = max(0, (int)($ar['late_minutes'] ?? 0));
        $utMins   = max(0, (int)($ar['undertime_minutes'] ?? 0));
        $otMins   = max(0, (int)($ar['overtime_minutes'] ?? 0));
        $notes    = trim((string)($ar['notes'] ?? ''));

        $isAbsent = (strcasecmp($status, 'Absent') === 0);
        $isLate   = (strcasecmp($status, 'Late') === 0 || $lateMins > 0);
        $hasPunch = ($inSec !== null || $outSec !== null);

        if ($isAbsent && empty($leaveLookup[$wd][$eid])) {
            $empRollups[$eid]['absent_days']++;
        } elseif ($hasPunch || in_array(strtolower($status), ['present', 'late', 'half day', 'rest day ot'], true)) {
            $empRollups[$eid]['present_days']++;
        }

        if ($isLate) {
            $empRollups[$eid]['late_days']++;
            $empRollups[$eid]['late_mins'] += $lateMins;
        }
        $empRollups[$eid]['undertime_mins'] += $utMins;
        if (!isset($otLookup[$wd][$eid])) {
            $empRollups[$eid]['overtime_mins'] += $otMins;
        }

        if ($inSec !== null && $outSec !== null && $outSec >= $inSec) {
            $empRollups[$eid]['worked_mins'] += intdiv($outSec - $inSec, 60);
        }

        // 1) Missing Clock-Out Anomaly (has time_in, no time_out, and date is past OR shift ended)
        $sched = ar_resolve_schedule($schedLookup, $eid, $wd);
        $isMissingOut = false;
        if ($inSec !== null && $outSec === null && !$isAbsent) {
            if ($wd < $today) {
                $isMissingOut = true;
            } elseif ($wd === $today && $sched !== null && !empty($sched['end_time'])) {
                $sEndSec = ar_time_to_seconds($sched['end_time']);
                $nowSec  = ((int)date('H') * 3600) + ((int)date('i') * 60) + (int)date('s');
                if ($sEndSec !== null && $nowSec > ($sEndSec + 1800)) {
                    $isMissingOut = true;
                }
            }
        }

        if ($isMissingOut) {
            $missingOutCount++;
            $empRollups[$eid]['anomaly_count']++;
            $anomalies[] = [
                'work_date'       => $wd,
                'employee_id'     => $eid,
                'employee_code'   => $emp['employee_code'],
                'emp_name'        => $emp['emp_name'],
                'department_name' => $emp['department_name'],
                'anomaly_key'     => 'missing_clock_out',
                'anomaly_label'   => 'Missing Clock-Out',
                'severity'        => 'high',
                'punch_info'      => 'In: ' . date('H:i', $inSec) . ' | Out: —',
                'detail'          => 'Clocked in at ' . date('H:i', $inSec) . ' with no recorded time-out.',
            ];
        }

        // 2) Schedule Mismatch Anomaly (worked on Rest Day, or punched > 60m outside assigned shift)
        $mismatchReason = '';
        if ($hasPunch) {
            if (stripos($status, 'Rest Day') !== false || ($sched !== null && !empty($sched['is_rest_day']))) {
                $mismatchReason = 'Time punch recorded on a scheduled Rest Day.';
            } elseif ($sched !== null && !empty($sched['start_time']) && $inSec !== null) {
                $sStartSec = ar_time_to_seconds($sched['start_time']);
                $sEndSec   = ar_time_to_seconds($sched['end_time'] ?? '');
                if ($sStartSec !== null && ($inSec - $sStartSec) > 3600) {
                    $mismatchReason = 'Clocked in ' . intdiv($inSec - $sStartSec, 60) . 'm after shift start (' . substr($sched['start_time'], 0, 5) . ').';
                } elseif ($sEndSec !== null && $outSec !== null && ($outSec - $sEndSec) > 7200 && ($otLookup[$wd][$eid] ?? 0) === 0) {
                    $mismatchReason = 'Clocked out ' . intdiv($outSec - $sEndSec, 60) . 'm past shift end (' . substr($sched['end_time'], 0, 5) . ') without approved OT.';
                }
            }
        }

        if ($mismatchReason !== '') {
            $schedMismatchCount++;
            $empRollups[$eid]['anomaly_count']++;
            $anomalies[] = [
                'work_date'       => $wd,
                'employee_id'     => $eid,
                'employee_code'   => $emp['employee_code'],
                'emp_name'        => $emp['emp_name'],
                'department_name' => $emp['department_name'],
                'anomaly_key'     => 'schedule_mismatch',
                'anomaly_label'   => 'Schedule Mismatch',
                'severity'        => 'med',
                'punch_info'      => ($inSec !== null ? date('H:i', $inSec) : '—') . ' - ' . ($outSec !== null ? date('H:i', $outSec) : '—'),
                'detail'          => $mismatchReason,
            ];
        }

        // 3) Leave Overlap Anomaly (attendance log or punch exists on a date covered by an approved/pending leave request)
        if (isset($leaveLookup[$wd][$eid]) && ($hasPunch || !$isAbsent)) {
            $lInfo = $leaveLookup[$wd][$eid];
            $leaveOverlapCount++;
            $empRollups[$eid]['anomaly_count']++;
            $anomalies[] = [
                'work_date'       => $wd,
                'employee_id'     => $eid,
                'employee_code'   => $emp['employee_code'],
                'emp_name'        => $emp['emp_name'],
                'department_name' => $emp['department_name'],
                'anomaly_key'     => 'leave_overlap',
                'anomaly_label'   => 'Leave Overlap',
                'severity'        => 'warn',
                'punch_info'      => ($inSec !== null ? date('H:i', $inSec) : $status),
                'detail'          => 'Attendance "' . $status . '" overlaps with ' . ucfirst($lInfo['status']) . ' ' . $lInfo['leave_type'] . '.',
            ];
        }
    }

    if ($anomalyFilter !== '') {
        $anomalies = array_values(array_filter($anomalies, static function (array $a) use ($anomalyFilter): bool {
            return $a['anomaly_key'] === $anomalyFilter;
        }));
    }

    usort($anomalies, static function (array $a, array $b): int {
        $cmp = strcmp((string)$b['work_date'], (string)$a['work_date']);
        return $cmp !== 0 ? $cmp : strcmp((string)$a['emp_name'], (string)$b['emp_name']);
    });

    $summaryRows = [];
    $totalPresent = 0;
    $totalAbsent  = 0;
    $totalLeave   = 0;
    $totalLateMin = 0;
    $totalOtMin   = 0;

    foreach ($empRollups as $r) {
        $totalPresent += $r['present_days'];
        $totalAbsent  += $r['absent_days'];
        $totalLeave   += $r['leave_days'];
        $totalLateMin += $r['late_mins'];
        $totalOtMin   += $r['overtime_mins'];

        $r['late_fmt']      = ar_format_mins($r['late_mins']);
        $r['undertime_fmt'] = ar_format_mins($r['undertime_mins']);
        $r['overtime_fmt']  = ar_format_mins($r['overtime_mins']);
        $r['worked_fmt']    = ar_format_mins($r['worked_mins']);
        $summaryRows[] = $r;
    }

    usort($summaryRows, static function (array $a, array $b): int {
        if ($a['anomaly_count'] !== $b['anomaly_count']) {
            return $b['anomaly_count'] <=> $a['anomaly_count'];
        }
        return strcmp((string)$a['emp_name'], (string)$b['emp_name']);
    });

    return [
        'from_date'   => $fromDate,
        'to_date'     => $toDate,
        'departments' => $departments,
        'employees'   => array_values($allEmployees),
        'kpis'        => [
            'total_anomalies'    => $missingOutCount + $schedMismatchCount + $leaveOverlapCount,
            'missing_clock_outs' => $missingOutCount,
            'schedule_mismatch'  => $schedMismatchCount,
            'leave_overlaps'     => $leaveOverlapCount,
            'total_present_days' => $totalPresent,
            'total_absent_days'  => $totalAbsent,
            'total_leave_days'   => $totalLeave,
            'total_late_fmt'     => ar_format_mins($totalLateMin),
            'total_ot_fmt'       => ar_format_mins($totalOtMin),
        ],
        'summary_rows' => $summaryRows,
        'anomalies'    => array_slice($anomalies, 0, 500),
    ];
}

try {
    if (function_exists('sched_sync_realtime_attendance')) {
        sched_sync_realtime_attendance($pdo, $today);
    }
} catch (Throwable $e) {}

// CSV Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $expScope = strtolower(trim((string)($_GET['scope'] ?? 'auto')));
    $expDept  = $deptFilter;
    $expEmpId = $empIdFilter;

    if ($expScope === 'all') {
        $expDept = '';
        $expEmpId = 0;
    } elseif ($expScope === 'department') {
        $expEmpId = 0;
    } elseif ($expScope === 'employee') {
        $expDept = '';
    }

    $expData = ar_build_report($pdo, $fromDate, $toDate, $today, $expDept, $expEmpId, $anomalyFilter, $searchQ);
    $mode = strtolower(trim((string)($_GET['export_type'] ?? 'both'))); // summary | anomalies | both

    $scopeTag = 'all_employees';
    if ($expEmpId > 0) {
        $scopeTag = 'employee_' . $expEmpId;
    } elseif ($expDept !== '') {
        $scopeTag = 'dept_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($expDept));
    }
    $filename = "attendance_report_{$scopeTag}_{$fromDate}_to_{$toDate}.csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fwrite($out, "\xEF\xBB\xBF");

        if ($mode === 'summary' || $mode === 'both') {
            fputcsv($out, ['=== ATTENDANCE CUTOFF & PAYROLL ROLLUP ===']);
            fputcsv($out, [
                'Employee ID', 'Employee Code', 'Employee Name', 'Department',
                'Present Days', 'Approved Leave Days', 'Absent Days',
                'Late Count', 'Late Duration', 'Undertime', 'Approved Overtime',
                'Net Worked Hours', 'Anomalies Flagged', 'Period From', 'Period To'
            ]);
            foreach ($expData['summary_rows'] as $r) {
                fputcsv($out, [
                    $r['employee_id'], $r['employee_code'], $r['emp_name'], $r['department_name'],
                    $r['present_days'], $r['leave_days'], $r['absent_days'],
                    $r['late_days'], $r['late_fmt'], $r['undertime_fmt'], $r['overtime_fmt'],
                    $r['worked_fmt'], $r['anomaly_count'], $fromDate, $toDate
                ]);
            }
            if ($mode === 'both') fputcsv($out, []);
        }

        if ($mode === 'anomalies' || $mode === 'both') {
            fputcsv($out, ['=== ATTENDANCE ANOMALIES & EXCEPTIONS ===']);
            fputcsv($out, [
                'Date', 'Employee ID', 'Employee Code', 'Employee Name', 'Department',
                'Anomaly Type', 'Punch / Status', 'Details'
            ]);
            foreach ($expData['anomalies'] as $a) {
                fputcsv($out, [
                    $a['work_date'], $a['employee_id'], $a['employee_code'], $a['emp_name'], $a['department_name'],
                    $a['anomaly_label'], $a['punch_info'], $a['detail']
                ]);
            }
        }
        fclose($out);
    }
    exit;
}

$report = ar_build_report($pdo, $fromDate, $toDate, $today, $deptFilter, $empIdFilter, $anomalyFilter, $searchQ);

if (isset($_GET['ajax']) && $_GET['ajax'] === 'live_state') {
    try {
        if (function_exists('sched_sync_realtime_attendance')) {
            sched_sync_realtime_attendance($pdo, $today);
        }
        $report = ar_build_report($pdo, $fromDate, $toDate, $today, $deptFilter, $empIdFilter, $anomalyFilter, $searchQ);
    } catch (Throwable $e) {}

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode([
        'ok'          => true,
        'server_time' => date('H:i:s'),
        'report'      => $report,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$hrHeader = __DIR__ . '/../templates/hrTemplates/header.php';
if (is_file($hrHeader)) {
    require_once $hrHeader;
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js" defer></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js" defer></script>

<style>
  .ar-wrap { max-width: 1360px; margin: 20px auto 56px; padding: 0 20px; color: #f8fafc; }
  .ar-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
  .ar-title { margin: 0; font-size: 24px; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 10px; }
  .ar-sub { margin: 4px 0 0; font-size: 13px; color: #94a3b8; }
  .ar-live-pill {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 700; background: rgba(16, 185, 129, 0.16); color: #34d399;
    border: 1px solid rgba(52, 211, 153, 0.35);
  }
  .ar-live-dot { width: 7px; height: 7px; border-radius: 50%; background: #34d399; animation: arPulse 1.6s infinite; }
  @keyframes arPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }

  .ar-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(175px, 1fr)); gap: 12px; margin-bottom: 18px; }
  .ar-kpi { background: #111827; border: 1px solid rgba(148,163,184,0.18); border-radius: 14px; padding: 14px 16px; }
  .ar-kpi-lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8; font-weight: 700; }
  .ar-kpi-val { font-size: 21px; font-weight: 700; color: #ffffff; margin-top: 6px; }
  .ar-kpi-sub { font-size: 11.5px; color: #64748b; margin-top: 2px; }

  .ar-card { background: #111827; border: 1px solid rgba(148,163,184,0.18); border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
  .ar-card-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; }
  .ar-card h2 { margin: 0; font-size: 16px; color: #ffffff; }
  .ar-filter-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); gap: 10px; align-items: end; }
  .ar-export-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
  .ar-label { display: block; font-size: 11px; text-transform: uppercase; color: #94a3b8; font-weight: 700; margin-bottom: 4px; }
  .ar-input, .ar-select {
    width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid #334155;
    background: #0f172a; color: #f8fafc; font-size: 13px;
  }
  .ar-btn {
    padding: 8px 14px; border-radius: 8px; border: none; background: #2563eb;
    color: #ffffff; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  }
  .ar-btn:hover { background: #1d4ed8; }
  .ar-btn-sec { background: #1e293b; color: #f8fafc; border: 1px solid #334155; }
  .ar-btn-sec:hover { background: #334155; }
  .ar-btn-exp { background: #059669; color: #ffffff; }
  .ar-btn-exp:hover { background: #047857; }

  .ar-table-wrap { overflow-x: auto; }
  .ar-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .ar-table th, .ar-table td { padding: 10px 10px; border-bottom: 1px solid rgba(148,163,184,0.14); text-align: left; }
  .ar-table th { font-size: 11.5px; text-transform: uppercase; color: #94a3b8; background: #0f172a; }

  .ar-badge { padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; display: inline-block; }
  .ar-b-high { background: rgba(239,68,68,0.22); color: #f87171; border: 1px solid rgba(248,113,113,0.35); }
  .ar-b-med { background: rgba(245,158,11,0.2); color: #fbbf24; border: 1px solid rgba(251,191,36,0.35); }
  .ar-b-warn { background: rgba(56,189,248,0.2); color: #38bdf8; border: 1px solid rgba(56,189,248,0.35); }
  .ar-b-ok { background: rgba(16,185,129,0.18); color: #34d399; }

  .dataTables_wrapper { color: #e2e8f0; font-size: 12.5px; margin-top: 8px; }
  .dataTables_wrapper .dataTables_length,
  .dataTables_wrapper .dataTables_filter { margin-bottom: 10px; color: #94a3b8; }
  .dataTables_wrapper .dataTables_filter input,
  .dataTables_wrapper .dataTables_length select {
    background: #0f172a; color: #f8fafc; border: 1px solid #334155; border-radius: 7px; padding: 4px 8px;
  }
  .dataTables_wrapper .dataTables_info,
  .dataTables_wrapper .dataTables_paginate { color: #94a3b8 !important; padding-top: 10px !important; }
  .dataTables_wrapper .dataTables_paginate .paginate_button {
    color: #cbd5e1 !important; background: #0f172a !important; border: 1px solid rgba(148,163,184,0.2) !important;
    border-radius: 6px !important; padding: 3px 9px !important;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button.current,
  .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
    background: #2563eb !important; color: #ffffff !important; border-color: #3b82f6 !important;
  }

  @media print {
    .ar-filter-form, .ar-export-bar, .dataTables_length, .dataTables_filter, .dataTables_paginate { display: none !important; }
    body { background: #ffffff !important; color: #000000 !important; }
    .ar-card, .ar-kpi { background: #ffffff !important; color: #000000 !important; border: 1px solid #cbd5e1 !important; }
    .ar-table th { background: #f1f5f9 !important; color: #000000 !important; }
  }
</style>

<div class="ar-wrap">
  <div class="ar-top">
    <div>
      <h1 class="ar-title">
        <span>Attendance Reports &amp; Anomaly Audit</span>
        <span class="ar-live-pill"><span class="ar-live-dot"></span> <span id="arLiveText">REAL-TIME SYNC</span></span>
      </h1>
      <p class="ar-sub">
        Cutoff payroll rollup &amp; exception detection for missing clock-outs, schedule mismatches, and leave overlaps (<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8') ?>)
      </p>
    </div>
    <div style="display:flex; align-items:center; gap:10px;">
      <span style="font-size:12px; color:#94a3b8;" id="arSyncClock">Real-time sync active</span>
      <button type="button" id="arForceSyncBtn" class="ar-btn ar-btn-sec" style="padding:6px 11px; font-size:12px;">Sync Now</button>
    </div>
  </div>

  <div class="ar-kpis">
    <div class="ar-kpi">
      <div class="ar-kpi-lbl">Missing Clock-Outs</div>
      <div class="ar-kpi-val" style="color:#f87171;" id="kpiMissingOut"><?= (int)$report['kpis']['missing_clock_outs'] ?></div>
      <div class="ar-kpi-sub">Open shifts without time-out</div>
    </div>
    <div class="ar-kpi">
      <div class="ar-kpi-lbl">Schedule Mismatches</div>
      <div class="ar-kpi-val" style="color:#fbbf24;" id="kpiSchedMismatch"><?= (int)$report['kpis']['schedule_mismatch'] ?></div>
      <div class="ar-kpi-sub">Rest day work or shift variance</div>
    </div>
    <div class="ar-kpi">
      <div class="ar-kpi-lbl">Leave Overlaps</div>
      <div class="ar-kpi-val" style="color:#38bdf8;" id="kpiLeaveOverlap"><?= (int)$report['kpis']['leave_overlaps'] ?></div>
      <div class="ar-kpi-sub">Punches on leave-covered dates</div>
    </div>
    <div class="ar-kpi">
      <div class="ar-kpi-lbl">Present / Leave / Absent</div>
      <div class="ar-kpi-val" style="font-size:18px;" id="kpiDaySplit">
        <?= (int)$report['kpis']['total_present_days'] ?>d / <?= (int)$report['kpis']['total_leave_days'] ?>d / <?= (int)$report['kpis']['total_absent_days'] ?>d
      </div>
      <div class="ar-kpi-sub">Cutoff attendance breakdown</div>
    </div>
    <div class="ar-kpi">
      <div class="ar-kpi-lbl">Late / Approved OT</div>
      <div class="ar-kpi-val" style="font-size:18px;" id="kpiTimeSplit">
        <?= htmlspecialchars((string)$report['kpis']['total_late_fmt'], ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars((string)$report['kpis']['total_ot_fmt'], ENT_QUOTES, 'UTF-8') ?>
      </div>
      <div class="ar-kpi-sub">Total period late vs. OT</div>
    </div>
  </div>

  <div class="ar-card">
    <div class="ar-card-head">
      <h2>Cutoff Period, Filters &amp; Export</h2>
    </div>
    <form method="get" class="ar-filter-form" id="arFilterForm">
      <div>
        <label class="ar-label">Period Preset</label>
        <select name="preset" id="arPresetSelect" class="ar-select">
          <option value="custom" <?= $preset === 'custom' ? 'selected' : '' ?>>Custom Range</option>
          <option value="cutoff_1" <?= $preset === 'cutoff_1' ? 'selected' : '' ?>>1st Cutoff (1st – 15th)</option>
          <option value="cutoff_2" <?= $preset === 'cutoff_2' ? 'selected' : '' ?>>2nd Cutoff (16th – End)</option>
          <option value="month" <?= $preset === 'month' ? 'selected' : '' ?>>Full Current Month</option>
        </select>
      </div>
      <div>
        <label class="ar-label">From Date</label>
        <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') ?>" class="ar-input">
      </div>
      <div>
        <label class="ar-label">To Date</label>
        <input type="date" name="to_date" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8') ?>" class="ar-input">
      </div>
      <div>
        <label class="ar-label">Department</label>
        <select name="department" id="arDeptSelect" class="ar-select">
          <option value="">All Departments</option>
          <?php foreach ($report['departments'] as $dName): ?>
            <option value="<?= htmlspecialchars($dName, ENT_QUOTES, 'UTF-8') ?>" <?= strcasecmp($deptFilter, $dName) === 0 ? 'selected' : '' ?>>
              <?= htmlspecialchars($dName, ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="ar-label">Employee</label>
        <select name="employee_id" id="arEmpSelect" class="ar-select">
          <option value="0">All Employees</option>
          <?php foreach ($report['employees'] as $eo): ?>
            <option value="<?= (int)$eo['employee_id'] ?>" <?= $empIdFilter === (int)$eo['employee_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)$eo['emp_name'] . ($eo['employee_code'] !== '' ? ' (' . $eo['employee_code'] . ')' : ''), ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="ar-label">Anomaly Type</label>
        <select name="anomaly_type" id="arAnomalySelect" class="ar-select">
          <option value="">All Anomalies</option>
          <option value="missing_clock_out" <?= $anomalyFilter === 'missing_clock_out' ? 'selected' : '' ?>>Missing Clock-Out</option>
          <option value="schedule_mismatch" <?= $anomalyFilter === 'schedule_mismatch' ? 'selected' : '' ?>>Schedule Mismatch</option>
          <option value="leave_overlap" <?= $anomalyFilter === 'leave_overlap' ? 'selected' : '' ?>>Leave Overlap</option>
        </select>
      </div>
      <div>
        <label class="ar-label">Search</label>
        <input type="text" name="q" value="<?= htmlspecialchars($searchQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="Name or code..." class="ar-input">
      </div>
      <div>
        <button type="submit" class="ar-btn" style="width:100%;">Apply</button>
      </div>
    </form>

    <div style="margin-top: 14px; padding-top: 14px; border-top: 1px solid rgba(148,163,184,0.14);">
      <div class="ar-export-bar">
        <div style="min-width: 180px;">
          <label class="ar-label">Export Scope</label>
          <select id="arExportScope" class="ar-select">
            <option value="auto">Current Filter View</option>
            <option value="all">All Employees</option>
            <option value="department">Selected Department</option>
            <option value="employee">Selected Employee</option>
          </select>
        </div>
        <div style="min-width: 195px;">
          <label class="ar-label">Export Content</label>
          <select id="arExportType" class="ar-select">
            <option value="both">Payroll Rollup + Anomalies (CSV)</option>
            <option value="summary">Payroll Rollup Only (CSV)</option>
            <option value="anomalies">Anomalies Only (CSV)</option>
          </select>
        </div>
        <div>
          <a href="#" id="arExportCsvBtn" class="ar-btn ar-btn-exp">Export CSV</a>
        </div>
        <div>
          <button type="button" id="arPrintBtn" class="ar-btn ar-btn-sec">Print / Save PDF</button>
        </div>
      </div>
    </div>
  </div>

  <div class="ar-card">
    <div class="ar-card-head">
      <h2>Detected Attendance Anomalies (Pre-Cutoff Audit)</h2>
      <a href="#" id="arQuickExportAnomalies" class="ar-btn ar-btn-exp" style="padding:6px 12px; font-size:12px;">Export Anomalies CSV</a>
    </div>
    <div class="ar-table-wrap">
      <table class="ar-table" id="arAnomaliesTable">
        <thead>
          <tr>
            <th>Date</th>
            <th>Employee</th>
            <th>Department</th>
            <th>Anomaly Type</th>
            <th>Punch / Status</th>
            <th>Audit Details</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($report['anomalies'] as $an): ?>
            <?php
              $bCls = $an['severity'] === 'high' ? 'ar-b-high' : ($an['severity'] === 'med' ? 'ar-b-med' : 'ar-b-warn');
            ?>
            <tr>
              <td><?= htmlspecialchars((string)$an['work_date'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <strong><?= htmlspecialchars((string)$an['emp_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if (!empty($an['employee_code'])): ?>
                  <div style="font-size:11.5px;color:#94a3b8;"><?= htmlspecialchars((string)$an['employee_code'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars((string)$an['department_name'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><span class="ar-badge <?= $bCls ?>"><?= htmlspecialchars((string)$an['anomaly_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
              <td><?= htmlspecialchars((string)$an['punch_info'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$an['detail'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="ar-card">
    <div class="ar-card-head">
      <h2>Employee Cutoff Attendance &amp; Payroll Summary</h2>
      <a href="#" id="arQuickExportSummary" class="ar-btn ar-btn-exp" style="padding:6px 12px; font-size:12px;">Export Summary CSV</a>
    </div>
    <div class="ar-table-wrap">
      <table class="ar-table" id="arSummaryTable">
        <thead>
          <tr>
            <th>Employee</th>
            <th>Department</th>
            <th>Present</th>
            <th>Approved Leave</th>
            <th>Absent</th>
            <th>Late</th>
            <th>Undertime</th>
            <th>Approved OT</th>
            <th>Net Worked</th>
            <th>Anomalies</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($report['summary_rows'] as $row): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars((string)$row['emp_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if (!empty($row['employee_code'])): ?>
                  <div style="font-size:11.5px;color:#94a3b8;"><?= htmlspecialchars((string)$row['employee_code'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars((string)$row['department_name'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><strong style="color:#34d399;"><?= (int)$row['present_days'] ?>d</strong></td>
              <td><span style="color:#38bdf8;"><?= (int)$row['leave_days'] ?>d</span></td>
              <td><strong style="color:#f87171;"><?= (int)$row['absent_days'] ?>d</strong></td>
              <td><?= (int)$row['late_days'] ?>x (<?= htmlspecialchars((string)$row['late_fmt'], ENT_QUOTES, 'UTF-8') ?>)</td>
              <td><?= htmlspecialchars((string)$row['undertime_fmt'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$row['overtime_fmt'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><strong><?= htmlspecialchars((string)$row['worked_fmt'], ENT_QUOTES, 'UTF-8') ?></strong></td>
              <td>
                <?php if ((int)$row['anomaly_count'] > 0): ?>
                  <span class="ar-badge ar-b-high"><?= (int)$row['anomaly_count'] ?> flagged</span>
                <?php else: ?>
                  <span class="ar-badge ar-b-ok">Clean</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function () {
  const params = {
    preset: <?= json_encode($preset, JSON_UNESCAPED_UNICODE) ?>,
    from_date: <?= json_encode($fromDate, JSON_UNESCAPED_UNICODE) ?>,
    to_date: <?= json_encode($toDate, JSON_UNESCAPED_UNICODE) ?>,
    department: <?= json_encode($deptFilter, JSON_UNESCAPED_UNICODE) ?>,
    employee_id: <?= (int)$empIdFilter ?>,
    anomaly_type: <?= json_encode($anomalyFilter, JSON_UNESCAPED_UNICODE) ?>,
    q: <?= json_encode($searchQ, JSON_UNESCAPED_UNICODE) ?>
  };

  let anomaliesDt = null;
  let summaryDt = null;
  let isPolling = false;

  function escHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function buildExportUrl(overrideScope, overrideType) {
    const scopeEl = document.getElementById('arExportScope');
    const typeEl = document.getElementById('arExportType');
    const deptEl = document.getElementById('arDeptSelect');
    const empEl = document.getElementById('arEmpSelect');
    const anomEl = document.getElementById('arAnomalySelect');

    const scope = overrideScope || (scopeEl ? scopeEl.value : 'auto');
    const exportType = overrideType || (typeEl ? typeEl.value : 'both');

    const qs = new URLSearchParams({
      export: 'csv',
      scope: scope,
      export_type: exportType,
      preset: params.preset,
      from_date: params.from_date,
      to_date: params.to_date,
      department: deptEl ? deptEl.value : params.department,
      employee_id: empEl ? empEl.value : String(params.employee_id),
      anomaly_type: anomEl ? anomEl.value : params.anomaly_type,
      q: params.q
    });
    return '?' + qs.toString();
  }

  function mapAnomalyCells(an) {
    const empHtml = an.employee_code
      ? `<strong>${escHtml(an.emp_name)}</strong><div style="font-size:11.5px;color:#94a3b8;">${escHtml(an.employee_code)}</div>`
      : `<strong>${escHtml(an.emp_name)}</strong>`;
    const bCls = an.severity === 'high' ? 'ar-b-high' : (an.severity === 'med' ? 'ar-b-med' : 'ar-b-warn');
    return [
      escHtml(an.work_date),
      empHtml,
      escHtml(an.department_name || 'Unassigned'),
      `<span class="ar-badge ${bCls}">${escHtml(an.anomaly_label)}</span>`,
      escHtml(an.punch_info || '—'),
      escHtml(an.detail || '')
    ];
  }

  function mapSummaryCells(row) {
    const empHtml = row.employee_code
      ? `<strong>${escHtml(row.emp_name)}</strong><div style="font-size:11.5px;color:#94a3b8;">${escHtml(row.employee_code)}</div>`
      : `<strong>${escHtml(row.emp_name)}</strong>`;
    const anomBadge = Number(row.anomaly_count || 0) > 0
      ? `<span class="ar-badge ar-b-high">${Number(row.anomaly_count)} flagged</span>`
      : `<span class="ar-badge ar-b-ok">Clean</span>`;
    return [
      empHtml,
      escHtml(row.department_name || 'Unassigned'),
      `<strong style="color:#34d399;">${Number(row.present_days || 0)}d</strong>`,
      `<span style="color:#38bdf8;">${Number(row.leave_days || 0)}d</span>`,
      `<strong style="color:#f87171;">${Number(row.absent_days || 0)}d</strong>`,
      `${Number(row.late_days || 0)}x (${escHtml(row.late_fmt || '0m')})`,
      escHtml(row.undertime_fmt || '0m'),
      escHtml(row.overtime_fmt || '0m'),
      `<strong>${escHtml(row.worked_fmt || '0m')}</strong>`,
      anomBadge
    ];
  }

  function initOrSyncTables(report) {
    if (!window.jQuery || !$.fn || !$.fn.DataTable) return;

    const aData = Array.isArray(report && report.anomalies) ? report.anomalies.map(mapAnomalyCells) : null;
    const sData = Array.isArray(report && report.summary_rows) ? report.summary_rows.map(mapSummaryCells) : null;

    if (!anomaliesDt) {
      anomaliesDt = $('#arAnomaliesTable').DataTable({
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        order: [[0, 'desc']],
        language: {
          emptyTable: 'No attendance anomalies detected for this period.',
          zeroRecords: 'No matching anomaly records found.'
        }
      });
    } else if (aData) {
      anomaliesDt.clear().rows.add(aData).draw(false);
    }

    if (!summaryDt) {
      summaryDt = $('#arSummaryTable').DataTable({
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        order: [[9, 'desc'], [0, 'asc']],
        language: {
          emptyTable: 'No employees found for this filter.',
          zeroRecords: 'No matching employees found.'
        }
      });
    } else if (sData) {
      summaryDt.clear().rows.add(sData).draw(false);
    }
  }

  function applyReport(payload) {
    if (!payload || !payload.ok || !payload.report) return;
    const rep = payload.report;
    const k = rep.kpis || {};
    const setTxt = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = String(v); };

    setTxt('kpiMissingOut', k.missing_clock_outs ?? 0);
    setTxt('kpiSchedMismatch', k.schedule_mismatch ?? 0);
    setTxt('kpiLeaveOverlap', k.leave_overlaps ?? 0);
    setTxt('kpiDaySplit', `${k.total_present_days ?? 0}d / ${k.total_leave_days ?? 0}d / ${k.total_absent_days ?? 0}d`);
    setTxt('kpiTimeSplit', `${k.total_late_fmt ?? '0m'} / ${k.total_ot_fmt ?? '0m'}`);

    if (payload.server_time) {
      setTxt('arSyncClock', 'Real-time synced at ' + payload.server_time);
    }

    initOrSyncTables(rep);
  }

  async function pollLive() {
    if (isPolling) return;
    isPolling = true;
    try {
      const deptEl = document.getElementById('arDeptSelect');
      const empEl = document.getElementById('arEmpSelect');
      const anomEl = document.getElementById('arAnomalySelect');

      const qs = new URLSearchParams({
        ajax: 'live_state',
        preset: params.preset,
        from_date: params.from_date,
        to_date: params.to_date,
        department: deptEl ? deptEl.value : params.department,
        employee_id: empEl ? empEl.value : String(params.employee_id),
        anomaly_type: anomEl ? anomEl.value : params.anomaly_type,
        q: params.q,
        _t: String(Date.now())
      });
      const res = await fetch('?' + qs.toString(), { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      applyReport(data);
    } catch (_) {}
    finally {
      isPolling = false;
    }
  }

  window.addEventListener('load', function () {
    initOrSyncTables(null);
    pollLive();
    setInterval(pollLive, 2000);

    const presetEl = document.getElementById('arPresetSelect');
    if (presetEl) {
      presetEl.addEventListener('change', () => {
        if (presetEl.value !== 'custom') {
          document.getElementById('arFilterForm').submit();
        }
      });
    }

    document.addEventListener('visibilitychange', () => { if (!document.hidden) pollLive(); });
    window.addEventListener('focus', pollLive);

    const forceBtn = document.getElementById('arForceSyncBtn');
    if (forceBtn) forceBtn.addEventListener('click', pollLive);

    const expCsvBtn = document.getElementById('arExportCsvBtn');
    if (expCsvBtn) expCsvBtn.addEventListener('click', (e) => { e.preventDefault(); window.location.href = buildExportUrl(); });

    const quickAnomBtn = document.getElementById('arQuickExportAnomalies');
    if (quickAnomBtn) quickAnomBtn.addEventListener('click', (e) => { e.preventDefault(); window.location.href = buildExportUrl(null, 'anomalies'); });

    const quickSumBtn = document.getElementById('arQuickExportSummary');
    if (quickSumBtn) quickSumBtn.addEventListener('click', (e) => { e.preventDefault(); window.location.href = buildExportUrl(null, 'summary'); });

    const printBtn = document.getElementById('arPrintBtn');
    if (printBtn) printBtn.addEventListener('click', () => window.print());

    try {
      if ('BroadcastChannel' in window) {
        const bc = new BroadcastChannel('hris_realtime_sync');
        bc.onmessage = (ev) => {
          const msg = ev && ev.data;
          if (!msg || typeof msg.type !== 'string') return;
          if (msg.type.startsWith('attendance.') || msg.type.startsWith('leave.') || msg.type.startsWith('overtime.') || msg.type.startsWith('schedule.')) {
            pollLive();
          }
        };
      }
    } catch (_) {}

    (async function attachWs() {
      const liveText = document.getElementById('arLiveText');
      try {
        for (const u of ['/api/realtime_token.php', '../api/realtime_token.php']) {
          const r = await fetch(u, { cache: 'no-store' });
          if (!r.ok) continue;
          const j = await r.json();
          const token = j.token || (j.data && j.data.token) || '';
          if (!token) continue;
          const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
          const ws = new WebSocket(`${proto}//${window.location.hostname}:8090/ws?token=${encodeURIComponent(token)}`);
          ws.onopen = () => { if (liveText) liveText.textContent = 'LIVE • WS'; pollLive(); };
          ws.onmessage = (ev) => {
            try {
              const pkt = JSON.parse(ev.data);
              const t = (pkt && pkt.event && pkt.event.type) || '';
              if (t.startsWith('attendance.') || t.startsWith('leave.') || t.startsWith('overtime.') || t.startsWith('schedule.')) {
                pollLive();
              }
            } catch (_) {}
          };
          ws.onclose = () => { if (liveText) liveText.textContent = 'LIVE • 2S POLL'; };
          break;
        }
      } catch (_) {}
    })();
  });
})();
</script>