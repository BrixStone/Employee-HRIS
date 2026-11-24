<?php
// hr/late_absence_monitoring.php

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

if (!function_exists('att_has_table')) {
    function att_has_table(PDO $pdo, string $table): bool {
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

if (!function_exists('att_has_col')) {
    function att_has_col(PDO $pdo, string $table, string $col): bool {
        static $cache = [];
        $key = $table . '.' . $col;
        if (array_key_exists($key, $cache)) return $cache[$key];
        if (!att_has_table($pdo, $table)) {
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

if (!function_exists('att_dept_sql_meta')) {
    function att_dept_sql_meta(PDO $pdo): array {
        $hasDeptCol = att_has_col($pdo, 'employees', 'department');
        $hasDeptId  = att_has_col($pdo, 'employees', 'department_id');
        $hasDeptTbl = att_has_table($pdo, 'departments');

        if ($hasDeptId && $hasDeptTbl) {
            $dNameCol = att_has_col($pdo, 'departments', 'name')
                ? 'name'
                : (att_has_col($pdo, 'departments', 'department_name') ? 'department_name' : null);
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

if (!function_exists('att_ensure_tables')) {
    function att_ensure_tables(PDO $pdo): void {
        // Handled by schedule_evaluator / migrations if needed
    }
}

if (!function_exists('att_fetch_unified_records')) {
    function att_fetch_unified_records(PDO $pdo, string $fromDate, string $toDate, array $onLeaveLookup = []): array {
        if (!att_has_table($pdo, 'employees')) {
            return [];
        }

        $deptMeta = att_dept_sql_meta($pdo);
        $hasCode  = att_has_col($pdo, 'employees', 'employee_code');
        $hasFirst = att_has_col($pdo, 'employees', 'first_name');
        $hasLast  = att_has_col($pdo, 'employees', 'last_name');
        $hasFull  = att_has_col($pdo, 'employees', 'full_name');
        $hasName  = att_has_col($pdo, 'employees', 'name');
        $hasStat  = att_has_col($pdo, 'employees', 'status');

        if ($hasFirst && $hasLast) {
            $nameExpr = "TRIM(CONCAT(COALESCE(e.`first_name`, ''), ' ', COALESCE(e.`last_name`, '')))";
        } elseif ($hasFull) {
            $nameExpr = "COALESCE(NULLIF(TRIM(e.`full_name`), ''), CONCAT('Employee #', e.`id`))";
        } elseif ($hasName) {
            $nameExpr = "COALESCE(NULLIF(TRIM(e.`name`), ''), CONCAT('Employee #', e.`id`))";
        } else {
            $nameExpr = "CONCAT('Employee #', e.`id`)";
        }

        $codeExpr   = $hasCode ? "COALESCE(e.`employee_code`, '')" : "''";
        $activeCond = $hasStat ? "WHERE LOWER(COALESCE(e.`status`, 'active')) NOT IN ('inactive', 'terminated', 'resigned')" : "";

        $employees = [];
        try {
            $eRows = $pdo->query("
                SELECT e.`id` AS employee_id,
                       {$codeExpr} AS employee_code,
                       {$nameExpr} AS emp_name,
                       {$deptMeta['dept_expr']} AS department_name
                FROM `employees` e
                {$deptMeta['join_dept']}
                {$activeCond}
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($eRows as $er) {
                $eid = (int)$er['employee_id'];
                if ($eid > 0) {
                    $employees[$eid] = $er;
                }
            }
        } catch (Throwable $e) {
            return [];
        }

        if (empty($employees)) {
            return [];
        }

        $attTable = null;
        foreach (['attendance_logs', 'attendance_records', 'attendance'] as $cand) {
            if (att_has_table($pdo, $cand)) {
                $attTable = $cand;
                break;
            }
        }

        $recordsByKey = [];
        if ($attTable !== null) {
            $dateCol = att_has_col($pdo, $attTable, 'work_date')
                ? 'work_date'
                : (att_has_col($pdo, $attTable, 'attendance_date')
                    ? 'attendance_date'
                    : (att_has_col($pdo, $attTable, 'date') ? 'date' : null));

            if ($dateCol !== null && att_has_col($pdo, $attTable, 'employee_id')) {
                $stCol   = att_has_col($pdo, $attTable, 'status') ? "COALESCE(a.`status`, 'Pending')" : "'Pending'";
                $lateCol = att_has_col($pdo, $attTable, 'late_minutes') ? "COALESCE(a.`late_minutes`, 0)" : "0";
                $noteCol = att_has_col($pdo, $attTable, 'notes')
                    ? "COALESCE(a.`notes`, '')"
                    : (att_has_col($pdo, $attTable, 'remarks') ? "COALESCE(a.`remarks`, '')" : "''");

                try {
                    $stmt = $pdo->prepare("
                        SELECT a.`employee_id`,
                               DATE(a.`{$dateCol}`) AS work_date,
                               {$stCol} AS status,
                               {$lateCol} AS late_minutes,
                               {$noteCol} AS notes
                        FROM `{$attTable}` a
                        WHERE DATE(a.`{$dateCol}`) BETWEEN :from_d AND :to_d
                    ");
                    $stmt->execute([':from_d' => $fromDate, ':to_d' => $toDate]);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                        $eid = (int)$ar['employee_id'];
                        $wd  = (string)$ar['work_date'];
                        if (!isset($employees[$eid]) || $wd === '') continue;

                        $emp = $employees[$eid];
                        $recordsByKey[$wd . '|' . $eid] = [
                            'work_date'       => $wd,
                            'employee_id'     => $eid,
                            'employee_code'   => (string)$emp['employee_code'],
                            'emp_name'        => (string)$emp['emp_name'],
                            'department_name' => (string)$emp['department_name'],
                            'status'          => trim((string)$ar['status']) ?: 'Pending',
                            'late_minutes'    => max(0, (int)$ar['late_minutes']),
                            'notes'           => (string)$ar['notes'],
                        ];
                    }
                } catch (Throwable $e) {}
            }
        }

        return array_values($recordsByKey);
    }
}

$today = date('Y-m-d');
$rawFrom = trim((string)($_GET['from_date'] ?? ''));
$rawTo   = trim((string)($_GET['to_date'] ?? ''));

$fromDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom) ? $rawFrom : date('Y-m-01', strtotime($today));
$toDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTo) ? $rawTo : $today;
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$deptFilter  = trim((string)($_GET['department'] ?? ''));
$empIdFilter = max(0, (int)($_GET['employee_id'] ?? 0));
$searchQ     = trim((string)($_GET['q'] ?? ''));
$minLate     = max(1, (int)($_GET['min_late'] ?? 3));
$minAbsent   = max(1, (int)($_GET['min_absent'] ?? 2));

function lam_format_hours(int $totalMinutes): string {
    $mins = max(0, $totalMinutes);
    $hours = $mins / 60;
    $hInt = intdiv($mins, 60);
    $remM = $mins % 60;
    if ($hInt === 0) {
        return $remM . 'm (0.0h)';
    }
    return $hInt . 'h ' . $remM . 'm (' . number_format($hours, 2) . 'h)';
}

function lam_fetch_employee_options(PDO $pdo, string $deptFilter = ''): array {
    if (!att_has_table($pdo, 'employees')) {
        return [];
    }
    $deptMeta = att_dept_sql_meta($pdo);
    $hasCode  = att_has_col($pdo, 'employees', 'employee_code');
    $hasFirst = att_has_col($pdo, 'employees', 'first_name');
    $hasLast  = att_has_col($pdo, 'employees', 'last_name');
    $hasFull  = att_has_col($pdo, 'employees', 'full_name');
    $hasName  = att_has_col($pdo, 'employees', 'name');
    $hasStat  = att_has_col($pdo, 'employees', 'status');

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
            $list[] = [
                'employee_id'     => $eid,
                'employee_code'   => (string)$r['employee_code'],
                'emp_name'        => (string)$r['emp_name'] ?: ('Employee #' . $eid),
                'department_name' => trim((string)$r['department_name']) ?: 'Unassigned',
            ];
        }
        return $list;
    } catch (Throwable $e) {
        return [];
    }
}

function lam_build_report(
    PDO $pdo,
    string $fromDate,
    string $toDate,
    string $deptFilter,
    int $empIdFilter,
    string $searchQ,
    int $minLate,
    int $minAbsent
): array {
    $onLeaveLookup = [];
    if (
        att_has_table($pdo, 'leave_requests') &&
        att_has_col($pdo, 'leave_requests', 'employee_id') &&
        att_has_col($pdo, 'leave_requests', 'start_date') &&
        att_has_col($pdo, 'leave_requests', 'end_date')
    ) {
        $hasStatus = att_has_col($pdo, 'leave_requests', 'status');
        $stWhere = $hasStatus ? "AND LOWER(COALESCE(`status`, '')) IN ('approved', 'active')" : "";
        try {
            $lStmt = $pdo->prepare("
                SELECT `employee_id`, DATE(`start_date`) AS start_d, DATE(`end_date`) AS end_d
                FROM `leave_requests`
                WHERE DATE(`start_date`) <= :to_d
                  AND DATE(`end_date`) >= :from_d
                  {$stWhere}
            ");
            $lStmt->execute([':from_d' => $fromDate, ':to_d' => $toDate]);
            foreach ($lStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                $s = max($fromDate, (string)$lr['start_d']);
                $e = min($toDate, (string)$lr['end_d']);
                $cur = strtotime($s);
                $last = strtotime($e);
                while ($cur !== false && $last !== false && $cur <= $last) {
                    $onLeaveLookup[date('Y-m-d', $cur)][(int)$lr['employee_id']] = true;
                    $cur = strtotime('+1 day', $cur);
                }
            }
        } catch (Throwable $e) {}
    }

    $departments = [];
    if (att_has_table($pdo, 'employees')) {
        $deptMeta = att_dept_sql_meta($pdo);
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

    $employeeOptions = lam_fetch_employee_options($pdo, $deptFilter);

    $rows = att_fetch_unified_records($pdo, $fromDate, $toDate, $onLeaveLookup);
    $empStats = [];
    $incidents = [];

    foreach ($rows as $r) {
        $d = (string)($r['work_date'] ?? '');
        $eid = (int)($r['employee_id'] ?? 0);
        if ($eid <= 0) continue;

        if (!empty($onLeaveLookup[$d][$eid])) {
            continue;
        }

        if ($empIdFilter > 0 && $eid !== $empIdFilter) {
            continue;
        }

        $empName = (string)($r['emp_name'] ?? ('Employee #' . $eid));
        $empCode = (string)($r['employee_code'] ?? '');
        $deptName = trim((string)($r['department_name'] ?? 'Unassigned')) ?: 'Unassigned';
        $st = (string)($r['status'] ?? 'Pending');
        $lateMins = max(0, (int)($r['late_minutes'] ?? 0));

        $isLate = (strcasecmp($st, 'Late') === 0 || $lateMins > 0);
        $isAbsent = (strcasecmp($st, 'Absent') === 0);

        if (!$isLate && !$isAbsent) {
            continue;
        }

        if ($deptFilter !== '' && strcasecmp($deptName, $deptFilter) !== 0) {
            continue;
        }

        if ($searchQ !== '') {
            $needle = strtolower($searchQ);
            $hay = strtolower($empName . ' ' . $empCode . ' ' . $deptName . ' ' . $eid);
            if (strpos($hay, $needle) === false) {
                continue;
            }
        }

        if (!isset($empStats[$eid])) {
            $empStats[$eid] = [
                'employee_id'      => $eid,
                'employee_code'    => $empCode,
                'emp_name'         => $empName,
                'department_name'  => $deptName,
                'late_count'       => 0,
                'total_late_mins'  => 0,
                'absent_count'     => 0,
                'last_incident'    => $d,
            ];
        }

        if ($isLate) {
            $empStats[$eid]['late_count']++;
            $empStats[$eid]['total_late_mins'] += $lateMins;
            $incidents[] = [
                'work_date'       => $d,
                'employee_id'     => $eid,
                'employee_code'   => $empCode,
                'emp_name'        => $empName,
                'department_name' => $deptName,
                'type'            => 'Late',
                'late_minutes'    => $lateMins,
                'detail'          => $lateMins > 0 ? ($lateMins . 'm late arrival') : 'Marked Late',
            ];
        }

        if ($isAbsent) {
            $empStats[$eid]['absent_count']++;
            $incidents[] = [
                'work_date'       => $d,
                'employee_id'     => $eid,
                'employee_code'   => $empCode,
                'emp_name'        => $empName,
                'department_name' => $deptName,
                'type'            => 'Absent',
                'late_minutes'    => 0,
                'detail'          => (string)($r['notes'] ?? '') !== '' ? (string)$r['notes'] : 'Absent',
            ];
        }

        if ($d > $empStats[$eid]['last_incident']) {
            $empStats[$eid]['last_incident'] = $d;
        }
    }

    // Build Monthly Late & Absence Counts + Rates across the last 6 months up to $toDate
    $anchorMonthTs = strtotime(substr($toDate, 0, 7) . '-01') ?: strtotime(date('Y-m-01'));
    $chartStartYm  = date('Y-m-01', strtotime('-5 months', $anchorMonthTs));
    $chartEndYm    = date('Y-m-t', $anchorMonthTs);

    $monthBuckets = [];
    for ($i = 5; $i >= 0; $i--) {
        $mTs = strtotime("-{$i} months", $anchorMonthTs);
        $ym  = date('Y-m', $mTs);
        $monthBuckets[$ym] = [
            'ym'           => $ym,
            'label'        => date('M Y', $mTs),
            'late_count'   => 0,
            'absent_count' => 0,
            'total_logs'   => 0,
            'late_rate'    => 0.0,
            'absent_rate'  => 0.0,
        ];
    }

    $monthlyOnLeave = [];
    if (
        att_has_table($pdo, 'leave_requests') &&
        att_has_col($pdo, 'leave_requests', 'employee_id') &&
        att_has_col($pdo, 'leave_requests', 'start_date') &&
        att_has_col($pdo, 'leave_requests', 'end_date')
    ) {
        $hasStatus = att_has_col($pdo, 'leave_requests', 'status');
        $stWhere = $hasStatus ? "AND LOWER(COALESCE(`status`, '')) IN ('approved', 'active')" : "";
        try {
            $mLvStmt = $pdo->prepare("
                SELECT `employee_id`, DATE(`start_date`) AS start_d, DATE(`end_date`) AS end_d
                FROM `leave_requests`
                WHERE DATE(`start_date`) <= :ce_d
                  AND DATE(`end_date`) >= :cs_d
                  {$stWhere}
            ");
            $mLvStmt->execute([':cs_d' => $chartStartYm, ':ce_d' => $chartEndYm]);
            foreach ($mLvStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                $s = max($chartStartYm, (string)$lr['start_d']);
                $e = min($chartEndYm, (string)$lr['end_d']);
                $cur = strtotime($s);
                $last = strtotime($e);
                while ($cur !== false && $last !== false && $cur <= $last) {
                    $monthlyOnLeave[date('Y-m-d', $cur)][(int)$lr['employee_id']] = true;
                    $cur = strtotime('+1 day', $cur);
                }
            }
        } catch (Throwable $e) {}
    }

    $monthlyAllRows = att_fetch_unified_records($pdo, $chartStartYm, $chartEndYm, $monthlyOnLeave);
    foreach ($monthlyAllRows as $mr) {
        $md = (string)($mr['work_date'] ?? '');
        $meid = (int)($mr['employee_id'] ?? 0);
        $mym = substr($md, 0, 7);
        if (!isset($monthBuckets[$mym]) || $meid <= 0) continue;
        if (!empty($monthlyOnLeave[$md][$meid])) continue;
        if ($empIdFilter > 0 && $meid !== $empIdFilter) continue;

        $mEmpName = (string)($mr['emp_name'] ?? ('Employee #' . $meid));
        $mEmpCode = (string)($mr['employee_code'] ?? '');
        $mDept    = trim((string)($mr['department_name'] ?? 'Unassigned')) ?: 'Unassigned';

        if ($deptFilter !== '' && strcasecmp($mDept, $deptFilter) !== 0) continue;
        if ($searchQ !== '') {
            $needle = strtolower($searchQ);
            $hay = strtolower($mEmpName . ' ' . $mEmpCode . ' ' . $mDept . ' ' . $meid);
            if (strpos($hay, $needle) === false) continue;
        }

        $mst = (string)($mr['status'] ?? 'Pending');
        $mlateMins = max(0, (int)($mr['late_minutes'] ?? 0));
        $mIsLate = (strcasecmp($mst, 'Late') === 0 || $mlateMins > 0);
        $mIsAbsent = (strcasecmp($mst, 'Absent') === 0);

        $monthBuckets[$mym]['total_logs']++;
        if ($mIsLate) {
            $monthBuckets[$mym]['late_count']++;
        }
        if ($mIsAbsent) {
            $monthBuckets[$mym]['absent_count']++;
        }
    }

    foreach ($monthBuckets as $ym => &$mb) {
        $denom = max(1, (int)$mb['total_logs']);
        $mb['late_rate']   = round(((int)$mb['late_count'] / $denom) * 100, 1);
        $mb['absent_rate'] = round(((int)$mb['absent_count'] / $denom) * 100, 1);
    }
    unset($mb);

    $summaryRows = [];
    $frequentCount = 0;
    $totalLateArrivals = 0;
    $totalLateMinutes = 0;
    $totalAbsences = 0;

    foreach ($empStats as $item) {
        $isFreqLate = (int)$item['late_count'] >= $minLate;
        $isFreqAbs  = (int)$item['absent_count'] >= $minAbsent;
        $isFrequent = $isFreqLate || $isFreqAbs;

        if ($isFrequent) {
            $frequentCount++;
        }

        $totalLateArrivals += (int)$item['late_count'];
        $totalLateMinutes  += (int)$item['total_late_mins'];
        $totalAbsences     += (int)$item['absent_count'];

        $flagLabel = 'Watchlist';
        $flagTier  = 'watch';
        if ($isFreqLate && $isFreqAbs) {
            $flagLabel = 'Frequent Late & Absent';
            $flagTier  = 'freq';
        } elseif ($isFreqLate) {
            $flagLabel = 'Frequent Late';
            $flagTier  = 'freq';
        } elseif ($isFreqAbs) {
            $flagLabel = 'Frequent Absent';
            $flagTier  = 'freq';
        }

        $item['is_frequent']        = $isFrequent;
        $item['flag_label']         = $flagLabel;
        $item['flag_tier']          = $flagTier;
        $item['total_late_hours_fmt'] = lam_format_hours((int)$item['total_late_mins']);
        $summaryRows[] = $item;
    }

    usort($summaryRows, static function (array $a, array $b): int {
        if ($a['is_frequent'] !== $b['is_frequent']) {
            return $a['is_frequent'] ? -1 : 1;
        }
        $scoreA = ($a['absent_count'] * 1000) + $a['total_late_mins'];
        $scoreB = ($b['absent_count'] * 1000) + $b['total_late_mins'];
        if ($scoreA !== $scoreB) {
            return $scoreB <=> $scoreA;
        }
        return strcmp((string)$a['emp_name'], (string)$b['emp_name']);
    });

    usort($incidents, static function (array $a, array $b): int {
        $cmp = strcmp((string)$b['work_date'], (string)$a['work_date']);
        return $cmp !== 0 ? $cmp : strcmp((string)$a['emp_name'], (string)$b['emp_name']);
    });

    return [
        'from_date'           => $fromDate,
        'to_date'             => $toDate,
        'departments'         => $departments,
        'employees'           => $employeeOptions,
        'kpis'                => [
            'frequent_offenders'    => $frequentCount,
            'total_late_arrivals'   => $totalLateArrivals,
            'total_late_minutes'    => $totalLateMinutes,
            'total_late_hours_fmt'  => lam_format_hours($totalLateMinutes),
            'total_absences'        => $totalAbsences,
        ],
        'monthly_chart'       => array_values($monthBuckets),
        'summary_rows'        => $summaryRows,
        'incidents'           => array_slice($incidents, 0, 500),
    ];
}

try {
    att_ensure_tables($pdo);
    if (function_exists('sched_sync_realtime_attendance')) {
        sched_sync_realtime_attendance($pdo, $today);
    }
} catch (Throwable $e) {}

// Handle CSV Export (by employee, department, or all employees)
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

    $expReport = lam_build_report($pdo, $fromDate, $toDate, $expDept, $expEmpId, $searchQ, $minLate, $minAbsent);
    $mode = strtolower(trim((string)($_GET['export_type'] ?? 'both'))); // summary | incidents | both

    $scopeTag = 'all_employees';
    if ($expEmpId > 0) {
        $scopeTag = 'employee_' . $expEmpId;
    } elseif ($expDept !== '') {
        $scopeTag = 'dept_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($expDept));
    }
    $filename = "late_absence_{$scopeTag}_{$fromDate}_to_{$toDate}.csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fwrite($out, "\xEF\xBB\xBF");

        if ($mode === 'summary' || $mode === 'both') {
            fputcsv($out, ['=== EMPLOYEE LATE & ABSENCE SUMMARY ===']);
            fputcsv($out, [
                'Employee ID',
                'Employee Code',
                'Employee Name',
                'Department',
                'Late Count',
                'Total Late Minutes',
                'Total Late Hours',
                'Absence Count',
                'Last Incident Date',
                'Frequency Flag',
                'Period From',
                'Period To',
            ]);
            foreach ($expReport['summary_rows'] as $s) {
                fputcsv($out, [
                    $s['employee_id'],
                    $s['employee_code'],
                    $s['emp_name'],
                    $s['department_name'],
                    $s['late_count'],
                    $s['total_late_mins'],
                    $s['total_late_hours_fmt'],
                    $s['absent_count'],
                    $s['last_incident'],
                    $s['flag_label'],
                    $fromDate,
                    $toDate,
                ]);
            }
            if ($mode === 'both') {
                fputcsv($out, []);
            }
        }

        if ($mode === 'incidents' || $mode === 'both') {
            fputcsv($out, ['=== LATE & ABSENCE INCIDENT LOGS ===']);
            fputcsv($out, [
                'Date',
                'Employee ID',
                'Employee Code',
                'Employee Name',
                'Department',
                'Incident Type',
                'Late Minutes',
                'Details',
            ]);
            foreach ($expReport['incidents'] as $inc) {
                fputcsv($out, [
                    $inc['work_date'],
                    $inc['employee_id'],
                    $inc['employee_code'],
                    $inc['emp_name'],
                    $inc['department_name'],
                    $inc['type'],
                    $inc['late_minutes'],
                    $inc['detail'],
                ]);
            }
        }

        fclose($out);
    }
    exit;
}

$report = lam_build_report($pdo, $fromDate, $toDate, $deptFilter, $empIdFilter, $searchQ, $minLate, $minAbsent);

if (isset($_GET['ajax']) && $_GET['ajax'] === 'live_state') {
    try {
        if (function_exists('sched_sync_realtime_attendance')) {
            sched_sync_realtime_attendance($pdo, $today);
        }
        $report = lam_build_report($pdo, $fromDate, $toDate, $deptFilter, $empIdFilter, $searchQ, $minLate, $minAbsent);
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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" defer></script>

<style>
  .lam-wrap { max-width: 1320px; margin: 20px auto 56px; padding: 0 20px; color: #f8fafc; }
  .lam-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
  .lam-title { margin: 0; font-size: 24px; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 10px; }
  .lam-sub { margin: 4px 0 0; font-size: 13px; color: #94a3b8; }
  .lam-live-pill {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 700; background: rgba(16, 185, 129, 0.16); color: #34d399;
    border: 1px solid rgba(52, 211, 153, 0.35);
  }
  .lam-live-dot { width: 7px; height: 7px; border-radius: 50%; background: #34d399; animation: lamPulse 1.6s infinite; }
  @keyframes lamPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }

  .lam-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 18px; }
  .lam-kpi { background: #111827; border: 1px solid rgba(148,163,184,0.18); border-radius: 14px; padding: 14px 16px; }
  .lam-kpi-lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8; font-weight: 700; }
  .lam-kpi-val { font-size: 22px; font-weight: 700; color: #ffffff; margin-top: 6px; }
  .lam-kpi-sub { font-size: 11.5px; color: #64748b; margin-top: 2px; }

  .lam-card { background: #111827; border: 1px solid rgba(148,163,184,0.18); border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
  .lam-card-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; }
  .lam-card h2 { margin: 0; font-size: 16px; color: #ffffff; }
  .lam-charts-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  @media (max-width: 980px) { .lam-charts-2 { grid-template-columns: 1fr; } }
  .lam-chart-box { background: #0f172a; border: 1px solid rgba(148,163,184,0.14); border-radius: 12px; padding: 14px; }
  .lam-chart-title { font-size: 13px; font-weight: 700; color: #e2e8f0; margin: 0 0 8px; }

  .lam-filter-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); gap: 10px; align-items: end; }
  .lam-export-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
  .lam-label { display: block; font-size: 11px; text-transform: uppercase; color: #94a3b8; font-weight: 700; margin-bottom: 4px; }
  .lam-input, .lam-select {
    width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid #334155;
    background: #0f172a; color: #f8fafc; font-size: 13px;
  }
  .lam-btn {
    padding: 8px 14px; border-radius: 8px; border: none; background: #2563eb;
    color: #ffffff; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  }
  .lam-btn:hover { background: #1d4ed8; }
  .lam-btn-sec { background: #1e293b; color: #f8fafc; border: 1px solid #334155; }
  .lam-btn-sec:hover { background: #334155; }
  .lam-btn-exp { background: #059669; color: #ffffff; }
  .lam-btn-exp:hover { background: #047857; }

  .lam-table-wrap { overflow-x: auto; }
  .lam-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .lam-table th, .lam-table td { padding: 10px 10px; border-bottom: 1px solid rgba(148,163,184,0.14); text-align: left; }
  .lam-table th { font-size: 11.5px; text-transform: uppercase; color: #94a3b8; background: #0f172a; }

  .lam-badge { padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; display: inline-block; }
  .lam-b-freq { background: rgba(239,68,68,0.22); color: #f87171; border: 1px solid rgba(248,113,113,0.35); }
  .lam-b-watch { background: rgba(245,158,11,0.2); color: #fbbf24; border: 1px solid rgba(251,191,36,0.35); }
  .lam-b-late { background: rgba(245,158,11,0.2); color: #fbbf24; }
  .lam-b-abs { background: rgba(239,68,68,0.2); color: #f87171; }

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
    .lam-filter-form, .lam-export-bar, .dataTables_length, .dataTables_filter, .dataTables_paginate { display: none !important; }
    body { background: #ffffff !important; color: #000000 !important; }
    .lam-card, .lam-kpi { background: #ffffff !important; color: #000000 !important; border: 1px solid #cbd5e1 !important; }
    .lam-table th { background: #f1f5f9 !important; color: #000000 !important; }
  }
</style>

<div class="lam-wrap">
  <div class="lam-top">
    <div>
      <h1 class="lam-title">
        <span>Late and Absence Monitoring</span>
        <span class="lam-live-pill"><span class="lam-live-dot"></span> <span id="lamLiveText">REAL-TIME SYNC</span></span>
      </h1>
      <p class="lam-sub">
        Frequent late arrivals &amp; absences, total late hours, and absence counts (<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8') ?>)
      </p>
    </div>
    <div style="display:flex; align-items:center; gap:10px;">
      <span style="font-size:12px; color:#94a3b8;" id="lamSyncClock">Real-time sync active</span>
      <button type="button" id="lamForceSyncBtn" class="lam-btn lam-btn-sec" style="padding:6px 11px; font-size:12px;">Sync Now</button>
    </div>
  </div>

  <div class="lam-kpis">
    <div class="lam-kpi">
      <div class="lam-kpi-lbl">Frequent Offenders</div>
      <div class="lam-kpi-val" style="color:#f87171;" id="kpiFreqCount"><?= (int)$report['kpis']['frequent_offenders'] ?></div>
      <div class="lam-kpi-sub">&ge; <?= $minLate ?> lates or &ge; <?= $minAbsent ?> absences</div>
    </div>
    <div class="lam-kpi">
      <div class="lam-kpi-lbl">Total Late Arrivals</div>
      <div class="lam-kpi-val" style="color:#fbbf24;" id="kpiLateCount"><?= (int)$report['kpis']['total_late_arrivals'] ?></div>
      <div class="lam-kpi-sub">Late check-in occurrences</div>
    </div>
    <div class="lam-kpi">
      <div class="lam-kpi-lbl">Total Late Hours</div>
      <div class="lam-kpi-val" id="kpiLateHours"><?= htmlspecialchars((string)$report['kpis']['total_late_hours_fmt'], ENT_QUOTES, 'UTF-8') ?></div>
      <div class="lam-kpi-sub"><span id="kpiLateMins"><?= (int)$report['kpis']['total_late_minutes'] ?></span> total late minutes</div>
    </div>
    <div class="lam-kpi">
      <div class="lam-kpi-lbl">Total Absence Count</div>
      <div class="lam-kpi-val" style="color:#f87171;" id="kpiAbsCount"><?= (int)$report['kpis']['total_absences'] ?></div>
      <div class="lam-kpi-sub">Absent days recorded</div>
    </div>
  </div>

  <div class="lam-card">
    <div class="lam-card-head">
      <h2>Filter &amp; Frequency Thresholds</h2>
    </div>
    <form method="get" class="lam-filter-form" id="lamFilterForm">
      <div>
        <label class="lam-label">From Date</label>
        <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') ?>" class="lam-input">
      </div>
      <div>
        <label class="lam-label">To Date</label>
        <input type="date" name="to_date" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8') ?>" class="lam-input">
      </div>
      <div>
        <label class="lam-label">Department</label>
        <select name="department" id="lamDeptSelect" class="lam-select">
          <option value="">All Departments</option>
          <?php foreach ($report['departments'] as $dName): ?>
            <option value="<?= htmlspecialchars($dName, ENT_QUOTES, 'UTF-8') ?>" <?= strcasecmp($deptFilter, $dName) === 0 ? 'selected' : '' ?>>
              <?= htmlspecialchars($dName, ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="lam-label">Employee</label>
        <select name="employee_id" id="lamEmpSelect" class="lam-select">
          <option value="0">All Employees</option>
          <?php foreach ($report['employees'] as $eo): ?>
            <option value="<?= (int)$eo['employee_id'] ?>" <?= $empIdFilter === (int)$eo['employee_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)$eo['emp_name'] . ($eo['employee_code'] !== '' ? ' (' . $eo['employee_code'] . ')' : ''), ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="lam-label">Frequent Late &ge;</label>
        <input type="number" name="min_late" min="1" max="100" value="<?= $minLate ?>" class="lam-input">
      </div>
      <div>
        <label class="lam-label">Frequent Absent &ge;</label>
        <input type="number" name="min_absent" min="1" max="100" value="<?= $minAbsent ?>" class="lam-input">
      </div>
      <div>
        <label class="lam-label">Keyword Search</label>
        <input type="text" name="q" value="<?= htmlspecialchars($searchQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="Name, code, dept..." class="lam-input">
      </div>
      <div>
        <button type="submit" class="lam-btn" style="width:100%;">Apply</button>
      </div>
    </form>

    <div style="margin-top: 14px; padding-top: 14px; border-top: 1px solid rgba(148,163,184,0.14);">
      <div class="lam-export-bar">
        <div style="min-width: 180px;">
          <label class="lam-label">Export Scope</label>
          <select id="lamExportScope" class="lam-select">
            <option value="auto">Current Filter View</option>
            <option value="all">All Employees</option>
            <option value="department">Selected Department</option>
            <option value="employee">Selected Employee</option>
          </select>
        </div>
        <div style="min-width: 170px;">
          <label class="lam-label">Export Content</label>
          <select id="lamExportType" class="lam-select">
            <option value="both">Summary + Incident Logs (CSV)</option>
            <option value="summary">Employee Summary Only (CSV)</option>
            <option value="incidents">Incident Logs Only (CSV)</option>
          </select>
        </div>
        <div>
          <a href="#" id="lamExportCsvBtn" class="lam-btn lam-btn-exp">Export CSV</a>
        </div>
        <div>
          <button type="button" id="lamPrintBtn" class="lam-btn lam-btn-sec">Print / Save PDF</button>
        </div>
      </div>
    </div>
  </div>

  <div class="lam-card">
    <div class="lam-card-head">
      <h2>Monthly Late &amp; Absence Records and Rates (Last 6 Months)</h2>
      <span style="font-size:12px; color:#94a3b8;">Counts vs. % of recorded logs per month</span>
    </div>
    <div class="lam-charts-2">
      <div class="lam-chart-box">
        <h3 class="lam-chart-title">Monthly Late &amp; Absence Counts</h3>
        <canvas id="lamMonthlyCountsChart" height="150"></canvas>
      </div>
      <div class="lam-chart-box">
        <h3 class="lam-chart-title">Monthly Late Rate (%) &amp; Absence Rate (%)</h3>
        <canvas id="lamMonthlyRatesChart" height="150"></canvas>
      </div>
    </div>
  </div>

  <div class="lam-card">
    <div class="lam-card-head">
      <h2>Employee Late &amp; Absence Summary</h2>
      <a href="#" id="lamQuickExportSummary" class="lam-btn lam-btn-exp" style="padding:6px 12px; font-size:12px;">Export Summary CSV</a>
    </div>
    <div class="lam-table-wrap">
      <table class="lam-table" id="lamSummaryTable">
        <thead>
          <tr>
            <th>Employee</th>
            <th>Department</th>
            <th>Late Count</th>
            <th>Total Late Hours</th>
            <th>Absence Count</th>
            <th>Last Incident</th>
            <th>Frequency Flag</th>
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
              <td><strong style="color:#fbbf24;"><?= (int)$row['late_count'] ?></strong></td>
              <td><?= htmlspecialchars((string)$row['total_late_hours_fmt'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><strong style="color:#f87171;"><?= (int)$row['absent_count'] ?></strong></td>
              <td><?= htmlspecialchars((string)$row['last_incident'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <span class="lam-badge <?= $row['flag_tier'] === 'freq' ? 'lam-b-freq' : 'lam-b-watch' ?>">
                  <?= htmlspecialchars((string)$row['flag_label'], ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="lam-card">
    <div class="lam-card-head">
      <h2>Late &amp; Absence Incident Logs</h2>
      <a href="#" id="lamQuickExportIncidents" class="lam-btn lam-btn-exp" style="padding:6px 12px; font-size:12px;">Export Logs CSV</a>
    </div>
    <div class="lam-table-wrap">
      <table class="lam-table" id="lamIncidentsTable">
        <thead>
          <tr>
            <th>Date</th>
            <th>Employee</th>
            <th>Department</th>
            <th>Type</th>
            <th>Late Duration</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($report['incidents'] as $inc): ?>
            <tr>
              <td><?= htmlspecialchars((string)$inc['work_date'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <strong><?= htmlspecialchars((string)$inc['emp_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if (!empty($inc['employee_code'])): ?>
                  <div style="font-size:11.5px;color:#94a3b8;"><?= htmlspecialchars((string)$inc['employee_code'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars((string)$inc['department_name'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <span class="lam-badge <?= $inc['type'] === 'Absent' ? 'lam-b-abs' : 'lam-b-late' ?>">
                  <?= htmlspecialchars((string)$inc['type'], ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td><?= (int)$inc['late_minutes'] > 0 ? (int)$inc['late_minutes'] . 'm' : '&mdash;' ?></td>
              <td><?= htmlspecialchars((string)$inc['detail'], ENT_QUOTES, 'UTF-8') ?></td>
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
    from_date: <?= json_encode($fromDate, JSON_UNESCAPED_UNICODE) ?>,
    to_date: <?= json_encode($toDate, JSON_UNESCAPED_UNICODE) ?>,
    department: <?= json_encode($deptFilter, JSON_UNESCAPED_UNICODE) ?>,
    employee_id: <?= (int)$empIdFilter ?>,
    q: <?= json_encode($searchQ, JSON_UNESCAPED_UNICODE) ?>,
    min_late: <?= (int)$minLate ?>,
    min_absent: <?= (int)$minAbsent ?>
  };

  let initialMonthly = <?= json_encode($report['monthly_chart'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
  let countsChart = null;
  let ratesChart = null;
  let summaryDt = null;
  let incidentsDt = null;
  let isPolling = false;

  function escHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function buildExportUrl(overrideScope, overrideType) {
    const scopeEl = document.getElementById('lamExportScope');
    const typeEl = document.getElementById('lamExportType');
    const deptEl = document.getElementById('lamDeptSelect');
    const empEl = document.getElementById('lamEmpSelect');

    const scope = overrideScope || (scopeEl ? scopeEl.value : 'auto');
    const exportType = overrideType || (typeEl ? typeEl.value : 'both');
    const dept = deptEl ? deptEl.value : params.department;
    const empId = empEl ? empEl.value : String(params.employee_id);

    const qs = new URLSearchParams({
      export: 'csv',
      scope: scope,
      export_type: exportType,
      from_date: params.from_date,
      to_date: params.to_date,
      department: dept,
      employee_id: String(empId),
      q: params.q,
      min_late: String(params.min_late),
      min_absent: String(params.min_absent)
    });
    return '?' + qs.toString();
  }

  function initOrUpdateMonthlyCharts(monthlyData) {
    if (typeof Chart === 'undefined' || !Array.isArray(monthlyData)) return;

    const labels = monthlyData.map(m => m.label);
    const lateCounts = monthlyData.map(m => Number(m.late_count || 0));
    const absentCounts = monthlyData.map(m => Number(m.absent_count || 0));
    const lateRates = monthlyData.map(m => Number(m.late_rate || 0));
    const absentRates = monthlyData.map(m => Number(m.absent_rate || 0));

    if (!countsChart) {
      const cEl = document.getElementById('lamMonthlyCountsChart');
      if (cEl) {
        countsChart = new Chart(cEl, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [
              { label: 'Late Count', data: lateCounts, backgroundColor: '#f59e0b', borderRadius: 6 },
              { label: 'Absence Count', data: absentCounts, backgroundColor: '#ef4444', borderRadius: 6 }
            ]
          },
          options: {
            scales: {
              y: { beginAtZero: true, ticks: { color: '#94a3b8', stepSize: 1 }, grid: { color: 'rgba(148,163,184,0.12)' } },
              x: { ticks: { color: '#94a3b8' }, grid: { display: false } }
            },
            plugins: { legend: { labels: { color: '#cbd5e1', boxWidth: 10 } } }
          }
        });
      }
    } else {
      countsChart.data.labels = labels;
      countsChart.data.datasets[0].data = lateCounts;
      countsChart.data.datasets[1].data = absentCounts;
      countsChart.update('none');
    }

    if (!ratesChart) {
      const rEl = document.getElementById('lamMonthlyRatesChart');
      if (rEl) {
        ratesChart = new Chart(rEl, {
          type: 'line',
          data: {
            labels: labels,
            datasets: [
              { label: 'Late Rate (%)', data: lateRates, borderColor: '#fbbf24', backgroundColor: 'rgba(251,191,36,0.18)', tension: 0.3, fill: true },
              { label: 'Absence Rate (%)', data: absentRates, borderColor: '#f87171', backgroundColor: 'rgba(248,113,113,0.18)', tension: 0.3, fill: true }
            ]
          },
          options: {
            scales: {
              y: { beginAtZero: true, max: 100, ticks: { color: '#94a3b8', callback: v => v + '%' }, grid: { color: 'rgba(148,163,184,0.12)' } },
              x: { ticks: { color: '#94a3b8' }, grid: { display: false } }
            },
            plugins: {
              legend: { labels: { color: '#cbd5e1', boxWidth: 10 } },
              tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y}%` } }
            }
          }
        });
      }
    } else {
      ratesChart.data.labels = labels;
      ratesChart.data.datasets[0].data = lateRates;
      ratesChart.data.datasets[1].data = absentRates;
      ratesChart.update('none');
    }
  }

  function syncEmployeeDropdown(employees) {
    const empEl = document.getElementById('lamEmpSelect');
    if (!empEl || !Array.isArray(employees)) return;
    const currentVal = String(empEl.value || params.employee_id || '0');
    const optionsHtml = ['<option value="0">All Employees</option>'].concat(
      employees.map((e) => {
        const idStr = String(e.employee_id);
        const label = e.employee_code ? `${e.emp_name} (${e.employee_code})` : e.emp_name;
        const sel = idStr === currentVal ? ' selected' : '';
        return `<option value="${escHtml(idStr)}"${sel}>${escHtml(label)}</option>`;
      })
    );
    empEl.innerHTML = optionsHtml.join('');
  }

  function mapSummaryCells(row) {
    const empHtml = row.employee_code
      ? `<strong>${escHtml(row.emp_name)}</strong><div style="font-size:11.5px;color:#94a3b8;">${escHtml(row.employee_code)}</div>`
      : `<strong>${escHtml(row.emp_name)}</strong>`;
    const badgeCls = row.flag_tier === 'freq' ? 'lam-b-freq' : 'lam-b-watch';
    return [
      empHtml,
      escHtml(row.department_name || 'Unassigned'),
      `<strong style="color:#fbbf24;">${Number(row.late_count || 0)}</strong>`,
      escHtml(row.total_late_hours_fmt || '0m (0.0h)'),
      `<strong style="color:#f87171;">${Number(row.absent_count || 0)}</strong>`,
      escHtml(row.last_incident || '—'),
      `<span class="lam-badge ${badgeCls}">${escHtml(row.flag_label || 'Watchlist')}</span>`
    ];
  }

  function mapIncidentCells(inc) {
    const empHtml = inc.employee_code
      ? `<strong>${escHtml(inc.emp_name)}</strong><div style="font-size:11.5px;color:#94a3b8;">${escHtml(inc.employee_code)}</div>`
      : `<strong>${escHtml(inc.emp_name)}</strong>`;
    const typeCls = inc.type === 'Absent' ? 'lam-b-abs' : 'lam-b-late';
    const lateDur = Number(inc.late_minutes || 0) > 0 ? `${Number(inc.late_minutes)}m` : '—';
    return [
      escHtml(inc.work_date),
      empHtml,
      escHtml(inc.department_name || 'Unassigned'),
      `<span class="lam-badge ${typeCls}">${escHtml(inc.type)}</span>`,
      escHtml(lateDur),
      escHtml(inc.detail || '')
    ];
  }

  function initOrSyncTables(report) {
    if (!window.jQuery || !$.fn || !$.fn.DataTable) return;

    const sData = Array.isArray(report && report.summary_rows) ? report.summary_rows.map(mapSummaryCells) : null;
    const iData = Array.isArray(report && report.incidents) ? report.incidents.map(mapIncidentCells) : null;

    if (!summaryDt) {
      summaryDt = $('#lamSummaryTable').DataTable({
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        order: [[4, 'desc'], [2, 'desc']],
        language: {
          emptyTable: 'No late arrivals or absences found for this filter.',
          zeroRecords: 'No matching employees found.'
        }
      });
    } else if (sData) {
      summaryDt.clear().rows.add(sData).draw(false);
    }

    if (!incidentsDt) {
      incidentsDt = $('#lamIncidentsTable').DataTable({
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        order: [[0, 'desc']],
        language: {
          emptyTable: 'No late or absence incident records found.',
          zeroRecords: 'No matching incident records found.'
        }
      });
    } else if (iData) {
      incidentsDt.clear().rows.add(iData).draw(false);
    }
  }

  function applyReport(payload) {
    if (!payload || !payload.ok || !payload.report) return;
    const rep = payload.report;
    const kpis = rep.kpis || {};
    const setTxt = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = String(v); };

    setTxt('kpiFreqCount', kpis.frequent_offenders ?? 0);
    setTxt('kpiLateCount', kpis.total_late_arrivals ?? 0);
    setTxt('kpiLateHours', kpis.total_late_hours_fmt ?? '0m (0.0h)');
    setTxt('kpiLateMins', kpis.total_late_minutes ?? 0);
    setTxt('kpiAbsCount', kpis.total_absences ?? 0);

    if (Array.isArray(rep.employees)) {
      syncEmployeeDropdown(rep.employees);
    }

    if (Array.isArray(rep.monthly_chart)) {
      initOrUpdateMonthlyCharts(rep.monthly_chart);
    }

    if (payload.server_time) {
      setTxt('lamSyncClock', 'Real-time synced at ' + payload.server_time);
    }

    initOrSyncTables(rep);
  }

  async function pollLive() {
    if (isPolling) return;
    isPolling = true;
    try {
      const deptEl = document.getElementById('lamDeptSelect');
      const empEl = document.getElementById('lamEmpSelect');
      const activeDept = deptEl ? deptEl.value : params.department;
      const activeEmp = empEl ? empEl.value : String(params.employee_id);

      const qs = new URLSearchParams({
        ajax: 'live_state',
        from_date: params.from_date,
        to_date: params.to_date,
        department: activeDept,
        employee_id: String(activeEmp),
        q: params.q,
        min_late: String(params.min_late),
        min_absent: String(params.min_absent),
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
    initOrUpdateMonthlyCharts(initialMonthly);
    initOrSyncTables(null);
    pollLive();
    setInterval(pollLive, 2000);

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) pollLive();
    });
    window.addEventListener('focus', pollLive);

    const forceBtn = document.getElementById('lamForceSyncBtn');
    if (forceBtn) {
      forceBtn.addEventListener('click', () => {
        const clock = document.getElementById('lamSyncClock');
        if (clock) clock.textContent = 'Syncing now...';
        pollLive();
      });
    }

    const expCsvBtn = document.getElementById('lamExportCsvBtn');
    if (expCsvBtn) {
      expCsvBtn.addEventListener('click', (e) => {
        e.preventDefault();
        window.location.href = buildExportUrl();
      });
    }

    const quickSummaryBtn = document.getElementById('lamQuickExportSummary');
    if (quickSummaryBtn) {
      quickSummaryBtn.addEventListener('click', (e) => {
        e.preventDefault();
        window.location.href = buildExportUrl(null, 'summary');
      });
    }

    const quickIncidentsBtn = document.getElementById('lamQuickExportIncidents');
    if (quickIncidentsBtn) {
      quickIncidentsBtn.addEventListener('click', (e) => {
        e.preventDefault();
        window.location.href = buildExportUrl(null, 'incidents');
      });
    }

    const printBtn = document.getElementById('lamPrintBtn');
    if (printBtn) {
      printBtn.addEventListener('click', () => window.print());
    }

    try {
      if ('BroadcastChannel' in window) {
        const bc = new BroadcastChannel('hris_realtime_sync');
        bc.onmessage = (ev) => {
          const msg = ev && ev.data;
          if (!msg || typeof msg.type !== 'string') return;
          if (msg.type.startsWith('attendance.') || msg.type.startsWith('leave.')) {
            pollLive();
          }
        };
      }
    } catch (_) {}

    (async function attachWs() {
      const liveText = document.getElementById('lamLiveText');
      try {
        for (const u of ['/api/realtime_token.php', '../api/realtime_token.php']) {
          const r = await fetch(u, { cache: 'no-store' });
          if (!r.ok) continue;
          const j = await r.json();
          const token = j.token || (j.data && j.data.token) || '';
          if (!token) continue;
          const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
          const ws = new WebSocket(`${proto}//${window.location.hostname}:8090/ws?token=${encodeURIComponent(token)}`);
          ws.onopen = () => {
            if (liveText) liveText.textContent = 'LIVE • WS';
            pollLive();
          };
          ws.onmessage = (ev) => {
            try {
              const pkt = JSON.parse(ev.data);
              const t = (pkt && pkt.event && pkt.event.type) || '';
              if (t.startsWith('attendance.') || t.startsWith('leave.')) {
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