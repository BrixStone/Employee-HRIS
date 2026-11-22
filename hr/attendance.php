<?php
// hr/attendance.php

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

function att_has_table(PDO $pdo, string $table): bool {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
        $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function att_has_col(PDO $pdo, string $table, string $col): bool {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $col)) return false;
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($col));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function att_ensure_tables(PDO $pdo): void {
    sched_ensure_schema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `attendance_records` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT UNSIGNED NOT NULL,
            `work_date` DATE NOT NULL,
            `time_in` TIME NULL,
            `time_out` TIME NULL,
            `break_start` TIME NULL,
            `break_end` TIME NULL,
            `scheduled_start` TIME NOT NULL DEFAULT '08:00:00',
            `scheduled_end` TIME NOT NULL DEFAULT '17:00:00',
            `late_minutes` INT NOT NULL DEFAULT 0,
            `overtime_minutes` INT NOT NULL DEFAULT 0,
            `status` VARCHAR(32) NOT NULL DEFAULT 'Present',
            `notes` VARCHAR(255) NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_emp_date` (`employee_id`, `work_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `attendance_corrections` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT UNSIGNED NOT NULL,
            `work_date` DATE NOT NULL,
            `requested_time_in` TIME NULL,
            `requested_time_out` TIME NULL,
            `reason` TEXT NOT NULL,
            `status` VARCHAR(24) NOT NULL DEFAULT 'Pending',
            `reviewed_by` VARCHAR(120) NULL,
            `reviewed_at` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function att_has_valid_time_in(?string $timeIn): bool {
    $t = trim((string)($timeIn ?? ''));
    return $t !== '' && $t !== '00:00:00' && $t !== '00:00';
}

function att_normalize_record_status(?string $timeIn, int $lateMin, string $rawSt, bool $isOnLeave): string {
    if ($isOnLeave) {
        return 'On Leave';
    }
    if (!att_has_valid_time_in($timeIn)) {
        if (strcasecmp($rawSt, 'Absent') === 0) {
            return 'Absent';
        }
        if (strcasecmp($rawSt, 'Late') === 0 || $lateMin > 0) {
            return 'Late';
        }
        return 'Pending';
    }
    if (strcasecmp($rawSt, 'Late') === 0 || $lateMin > 0) {
        return 'Late';
    }
    if (in_array(strtolower($rawSt), ['on break', 'rest day ot'], true)) {
        return ucwords(strtolower($rawSt));
    }
    return 'Present';
}

function att_matches_export_status(array $row, string $exportStatus): bool {
    $sf = strtolower(trim($exportStatus));
    if ($sf === '' || $sf === 'all') {
        return true;
    }
    $st = strtolower(trim((string)($row['status'] ?? '')));
    $lateMins = (int)($row['late_minutes'] ?? 0);

    if ($sf === 'late') {
        return $st === 'late' || $lateMins > 0;
    }
    if ($sf === 'absent') {
        return $st === 'absent';
    }
    if ($sf === 'present') {
        return $st === 'present';
    }
    return $st === $sf;
}

function att_dept_sql_meta(PDO $pdo): array {
    $hasEmp     = att_has_table($pdo, 'employees');
    $hasDeptTbl = att_has_table($pdo, 'departments');
    $hasDeptId  = $hasEmp && att_has_col($pdo, 'employees', 'department_id');
    $hasDeptStr = $hasEmp && att_has_col($pdo, 'employees', 'department');

    $deptNameCol = 'name';
    if ($hasDeptTbl) {
        if (att_has_col($pdo, 'departments', 'department_name')) {
            $deptNameCol = 'department_name';
        } elseif (att_has_col($pdo, 'departments', 'title')) {
            $deptNameCol = 'title';
        }
    }

    $joinDept = ($hasEmp && $hasDeptTbl && $hasDeptId)
        ? "LEFT JOIN `departments` d ON d.`id` = e.`department_id`"
        : "";

    if ($joinDept !== '' && $hasDeptStr) {
        $deptExpr = "COALESCE(NULLIF(TRIM(d.`{$deptNameCol}`), ''), NULLIF(TRIM(e.`department`), ''), 'Unassigned')";
    } elseif ($joinDept !== '') {
        $deptExpr = "COALESCE(NULLIF(TRIM(d.`{$deptNameCol}`), ''), 'Unassigned')";
    } elseif ($hasDeptStr) {
        $deptExpr = "COALESCE(NULLIF(TRIM(e.`department`), ''), 'Unassigned')";
    } else {
        $deptExpr = "'Unassigned'";
    }

    $deptIdExpr = $hasDeptId ? "COALESCE(e.`department_id`, 0)" : "0";

    return [
        'join_dept'    => $joinDept,
        'dept_expr'    => $deptExpr,
        'dept_id_expr' => $deptIdExpr,
    ];
}

function att_fetch_unified_records(PDO $pdo, string $startDate, string $endDate, array $onLeaveByDate = []): array {
    $hasEmp  = att_has_table($pdo, 'employees');
    $hasFn   = $hasEmp && att_has_col($pdo, 'employees', 'first_name');
    $hasLn   = $hasEmp && att_has_col($pdo, 'employees', 'last_name');
    $hasCode = $hasEmp && att_has_col($pdo, 'employees', 'employee_code');

    $fnExpr   = $hasFn ? "COALESCE(e.`first_name`, '')" : "''";
    $lnExpr   = $hasLn ? "COALESCE(e.`last_name`, '')" : "''";
    $codeExpr = $hasCode ? "COALESCE(e.`employee_code`, '')" : "''";

    $deptMeta   = att_dept_sql_meta($pdo);
    $joinDept   = $deptMeta['join_dept'];
    $deptExpr   = $deptMeta['dept_expr'];
    $deptIdExpr = $deptMeta['dept_id_expr'];

    $recordsMap = [];

    if (att_has_table($pdo, 'attendance_records')) {
        $joinEmp = $hasEmp ? "LEFT JOIN `employees` e ON e.`id` = ar.`employee_id` {$joinDept}" : "";
        $nameExpr = $hasEmp
            ? "COALESCE(NULLIF(TRIM(CONCAT({$fnExpr}, ' ', {$lnExpr})), ''), CONCAT('Employee #', ar.`employee_id`))"
            : "CONCAT('Employee #', ar.`employee_id`)";
        $notesExpr = att_has_col($pdo, 'attendance_records', 'notes') ? "COALESCE(ar.`notes`, '')" : "''";

        try {
            $stmt = $pdo->prepare("
                SELECT ar.`work_date`, ar.`employee_id`, ar.`time_in`, ar.`time_out`,
                       ar.`break_start`, ar.`break_end`, ar.`late_minutes`, ar.`overtime_minutes`,
                       ar.`status`, {$notesExpr} AS notes,
                       {$nameExpr} AS emp_name, {$codeExpr} AS employee_code,
                       {$deptIdExpr} AS department_id, {$deptExpr} AS department_name
                FROM `attendance_records` ar
                {$joinEmp}
                WHERE ar.`work_date` BETWEEN :s_d AND :e_d
            ");
            $stmt->execute([':s_d' => $startDate, ':e_d' => $endDate]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = (string)$r['work_date'];
                $eid = (int)$r['employee_id'];
                $timeIn = $r['time_in'] ?: null;
                $lateMin = max(0, (int)($r['late_minutes'] ?? 0));
                $rawSt = trim((string)($r['status'] ?? ''));
                $normStatus = att_normalize_record_status($timeIn, $lateMin, $rawSt, !empty($onLeaveByDate[$d][$eid]));

                $recordsMap[$d . '|' . $eid] = [
                    'work_date'        => $d,
                    'employee_id'      => $eid,
                    'emp_name'         => (string)$r['emp_name'],
                    'employee_code'    => (string)$r['employee_code'],
                    'department_id'    => (int)($r['department_id'] ?? 0),
                    'department_name'  => (string)($r['department_name'] ?? 'Unassigned'),
                    'time_in'          => $timeIn,
                    'time_out'         => $r['time_out'] ?: null,
                    'break_start'      => $r['break_start'] ?: null,
                    'break_end'        => $r['break_end'] ?: null,
                    'late_minutes'     => $lateMin,
                    'overtime_minutes' => max(0, (int)($r['overtime_minutes'] ?? 0)),
                    'status'           => $normStatus,
                    'notes'            => (string)($r['notes'] ?? ''),
                ];
            }
        } catch (Throwable $e) {}
    }

    if (att_has_table($pdo, 'attendance') && att_has_col($pdo, 'attendance', 'employee_id')) {
        $hasTimeIn   = att_has_col($pdo, 'attendance', 'time_in');
        $hasTimeOut  = att_has_col($pdo, 'attendance', 'time_out');
        $hasWorkDate = att_has_col($pdo, 'attendance', 'work_date');
        $hasStatus   = att_has_col($pdo, 'attendance', 'status');
        $hasBreakIn  = att_has_col($pdo, 'attendance', 'break_start') || att_has_col($pdo, 'attendance', 'break_in');
        $hasBreakOut = att_has_col($pdo, 'attendance', 'break_end') || att_has_col($pdo, 'attendance', 'break_out');
        $hasLate     = att_has_col($pdo, 'attendance', 'late_minutes');
        $hasOt       = att_has_col($pdo, 'attendance', 'overtime_minutes');

        $dateExpr = $hasWorkDate
            ? "COALESCE(DATE(a.`work_date`), " . ($hasTimeIn ? "DATE(a.`time_in`)" : "CURRENT_DATE()") . ")"
            : ($hasTimeIn ? "DATE(a.`time_in`)" : "CURRENT_DATE()");
        $inExpr   = $hasTimeIn ? "TIME(a.`time_in`)" : "NULL";
        $outExpr  = $hasTimeOut ? "TIME(a.`time_out`)" : "NULL";
        $bInCol   = att_has_col($pdo, 'attendance', 'break_start') ? 'break_start' : (att_has_col($pdo, 'attendance', 'break_in') ? 'break_in' : '');
        $bOutCol  = att_has_col($pdo, 'attendance', 'break_end') ? 'break_end' : (att_has_col($pdo, 'attendance', 'break_out') ? 'break_out' : '');
        $bInExpr  = ($hasBreakIn && $bInCol !== '') ? "TIME(a.`{$bInCol}`)" : "NULL";
        $bOutExpr = ($hasBreakOut && $bOutCol !== '') ? "TIME(a.`{$bOutCol}`)" : "NULL";
        $stExpr   = $hasStatus ? "COALESCE(NULLIF(a.`status`, ''), '')" : "''";
        $lateExpr = $hasLate ? "COALESCE(a.`late_minutes`, 0)" : "0";
        $otExpr   = $hasOt ? "COALESCE(a.`overtime_minutes`, 0)" : "0";

        $joinEmp = $hasEmp ? "LEFT JOIN `employees` e ON e.`id` = a.`employee_id` {$joinDept}" : "";
        $nameExpr = $hasEmp
            ? "COALESCE(NULLIF(TRIM(CONCAT({$fnExpr}, ' ', {$lnExpr})), ''), CONCAT('Employee #', a.`employee_id`))"
            : "CONCAT('Employee #', a.`employee_id`)";

        try {
            $stmt = $pdo->prepare("
                SELECT {$dateExpr} AS work_date, a.`employee_id`,
                       {$inExpr} AS time_in, {$outExpr} AS time_out,
                       {$bInExpr} AS break_start, {$bOutExpr} AS break_end,
                       {$lateExpr} AS late_minutes, {$otExpr} AS overtime_minutes,
                       {$stExpr} AS status,
                       {$nameExpr} AS emp_name, {$codeExpr} AS employee_code,
                       {$deptIdExpr} AS department_id, {$deptExpr} AS department_name
                FROM `attendance` a
                {$joinEmp}
                WHERE {$dateExpr} BETWEEN :s_d AND :e_d
            ");
            $stmt->execute([':s_d' => $startDate, ':e_d' => $endDate]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = (string)$r['work_date'];
                if ($d === '') continue;
                $eid = (int)$r['employee_id'];
                $key = $d . '|' . $eid;
                $timeIn = $r['time_in'] ?: null;
                $lateMin = max(0, (int)($r['late_minutes'] ?? 0));
                $rawSt = trim((string)($r['status'] ?? ''));
                $normStatus = att_normalize_record_status($timeIn, $lateMin, $rawSt, !empty($onLeaveByDate[$d][$eid]));

                if (!isset($recordsMap[$key])) {
                    $recordsMap[$key] = [
                        'work_date'        => $d,
                        'employee_id'      => $eid,
                        'emp_name'         => (string)$r['emp_name'],
                        'employee_code'    => (string)$r['employee_code'],
                        'department_id'    => (int)($r['department_id'] ?? 0),
                        'department_name'  => (string)($r['department_name'] ?? 'Unassigned'),
                        'time_in'          => $timeIn,
                        'time_out'         => $r['time_out'] ?: null,
                        'break_start'      => $r['break_start'] ?: null,
                        'break_end'        => $r['break_end'] ?: null,
                        'late_minutes'     => $lateMin,
                        'overtime_minutes' => max(0, (int)($r['overtime_minutes'] ?? 0)),
                        'status'           => $normStatus,
                        'notes'            => '',
                    ];
                } else {
                    if (!att_has_valid_time_in($recordsMap[$key]['time_in']) && att_has_valid_time_in($timeIn)) {
                        $recordsMap[$key]['time_in'] = $timeIn;
                        $recordsMap[$key]['status'] = ($lateMin > 0 || strcasecmp($rawSt, 'Late') === 0) ? 'Late' : 'Present';
                    }
                    if (empty($recordsMap[$key]['time_out']) && !empty($r['time_out'])) {
                        $recordsMap[$key]['time_out'] = $r['time_out'];
                    }
                    if (empty($recordsMap[$key]['break_start']) && !empty($r['break_start'])) {
                        $recordsMap[$key]['break_start'] = $r['break_start'];
                    }
                    if (empty($recordsMap[$key]['break_end']) && !empty($r['break_end'])) {
                        $recordsMap[$key]['break_end'] = $r['break_end'];
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    $list = array_values($recordsMap);
    usort($list, static function (array $a, array $b): int {
        $cmp = strcmp((string)$b['work_date'], (string)$a['work_date']);
        return $cmp !== 0 ? $cmp : strcmp((string)$a['emp_name'], (string)$b['emp_name']);
    });

    return $list;
}

function att_build_analytics(PDO $pdo, string $selectedDate, string $monthYm, int $totalEmployees): array {
    $attByDate = [];
    $absentByDate = [];
    $leaveByDate = [];
    $onLeaveLookup = [];

    $monthStart = $monthYm . '-01';
    $monthEnd   = date('Y-m-t', strtotime($monthStart));
    $trendStart = date('Y-m-d', strtotime($selectedDate . ' -6 days'));
    $rangeStart = min($monthStart, $trendStart);
    $rangeEnd   = max($monthEnd, $selectedDate);

    $hasEmp = att_has_table($pdo, 'employees');
    $hasFn  = $hasEmp && att_has_col($pdo, 'employees', 'first_name');
    $hasLn  = $hasEmp && att_has_col($pdo, 'employees', 'last_name');
    $fnExpr = $hasFn ? "COALESCE(e.`first_name`, '')" : "''";
    $lnExpr = $hasLn ? "COALESCE(e.`last_name`, '')" : "''";

    if (
        att_has_table($pdo, 'leave_requests') &&
        att_has_col($pdo, 'leave_requests', 'employee_id') &&
        att_has_col($pdo, 'leave_requests', 'start_date') &&
        att_has_col($pdo, 'leave_requests', 'end_date')
    ) {
        $hasStatus = att_has_col($pdo, 'leave_requests', 'status');
        $typeExpr = att_has_col($pdo, 'leave_requests', 'leave_type')
            ? "COALESCE(lr.`leave_type`, 'Leave')"
            : (att_has_col($pdo, 'leave_requests', 'type') ? "COALESCE(lr.`type`, 'Leave')" : "'Leave'");
        $joinLvEmp = $hasEmp ? "LEFT JOIN `employees` e ON e.`id` = lr.`employee_id`" : "";
        $lvNameExpr = $hasEmp
            ? "COALESCE(NULLIF(TRIM(CONCAT({$fnExpr}, ' ', {$lnExpr})), ''), CONCAT('Employee #', lr.`employee_id`))"
            : "CONCAT('Employee #', lr.`employee_id`)";
        $stWhere = $hasStatus ? "AND LOWER(COALESCE(lr.`status`, '')) IN ('approved', 'active')" : "";

        try {
            $lStmt = $pdo->prepare("
                SELECT lr.`employee_id`, {$lvNameExpr} AS emp_name, {$typeExpr} AS leave_type,
                       DATE(lr.`start_date`) AS start_d, DATE(lr.`end_date`) AS end_d
                FROM `leave_requests` lr
                {$joinLvEmp}
                WHERE DATE(lr.`start_date`) <= :e_d
                  AND DATE(lr.`end_date`) >= :s_d
                  {$stWhere}
            ");
            $lStmt->execute([':s_d' => $rangeStart, ':e_d' => $rangeEnd]);
            foreach ($lStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                $s = max($rangeStart, (string)$lr['start_d']);
                $e = min($rangeEnd, (string)$lr['end_d']);
                $cur = strtotime($s);
                $last = strtotime($e);
                while ($cur !== false && $last !== false && $cur <= $last) {
                    $dk = date('Y-m-d', $cur);
                    $eid = (int)$lr['employee_id'];
                    $leaveByDate[$dk][] = [
                        'employee_id' => $eid,
                        'emp_name'    => (string)$lr['emp_name'],
                        'leave_type'  => (string)$lr['leave_type'],
                    ];
                    $onLeaveLookup[$dk][$eid] = true;
                    $cur = strtotime('+1 day', $cur);
                }
            }
        } catch (Throwable $e) {}
    }

    $unifiedRows = att_fetch_unified_records($pdo, $rangeStart, $rangeEnd, $onLeaveLookup);
    foreach ($unifiedRows as $r) {
        $d = (string)$r['work_date'];
        $eid = (int)$r['employee_id'];
        if (!isset($attByDate[$d])) {
            $attByDate[$d] = ['present' => 0, 'absent' => 0, 'late' => 0, 'overtime' => 0];
        }

        if (!empty($onLeaveLookup[$d][$eid])) {
            continue;
        }

        $st = (string)($r['status'] ?? '');
        $hasIn = att_has_valid_time_in($r['time_in'] ?? null);
        $isAbsent = ($st === 'Absent');
        $isLate = ($st === 'Late' || (int)($r['late_minutes'] ?? 0) > 0);
        $isPresent = $hasIn && !$isLate && in_array($st, ['Present', 'On Break', 'Rest Day OT'], true);
        $hasOt = ((int)($r['overtime_minutes'] ?? 0) > 0);

        if ($isPresent) $attByDate[$d]['present']++;
        if ($isAbsent) {
            $attByDate[$d]['absent']++;
            $absentByDate[$d][] = [
                'employee_id' => $eid,
                'emp_name'    => (string)$r['emp_name'],
                'reason'      => (string)$r['notes'] !== '' ? (string)$r['notes'] : 'Absent',
            ];
        }
        if ($isLate) $attByDate[$d]['late']++;
        if ($hasOt)  $attByDate[$d]['overtime']++;
    }

    $selStats  = $attByDate[$selectedDate] ?? ['present' => 0, 'absent' => 0, 'late' => 0, 'overtime' => 0];
    $selLeaves = $leaveByDate[$selectedDate] ?? [];

    $trend7Days = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime($selectedDate . " -{$i} days"));
        $stDay = $attByDate[$d] ?? ['present' => 0, 'absent' => 0, 'late' => 0, 'overtime' => 0];
        $lvCnt = count($leaveByDate[$d] ?? []);
        $denom = max(1, $totalEmployees > 0 ? $totalEmployees : ((int)$stDay['present'] + (int)$stDay['absent'] + $lvCnt));
        $trend7Days[] = [
            'date'        => $d,
            'label'       => date('M j', strtotime($d)),
            'present'     => (int)$stDay['present'],
            'absent'      => (int)$stDay['absent'],
            'late'        => (int)$stDay['late'],
            'overtime'    => (int)$stDay['overtime'],
            'on_leave'    => $lvCnt,
            'present_pct' => round(((int)$stDay['present'] / $denom) * 100, 1),
            'absent_pct'  => round(((int)$stDay['absent'] / $denom) * 100, 1),
            'leave_pct'   => round(($lvCnt / $denom) * 100, 1),
        ];
    }

    $daysInMonth = (int)date('t', strtotime($monthStart));
    $calendarDays = [];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $d = sprintf('%s-%02d', $monthYm, $day);
        $absList = $absentByDate[$d] ?? [];
        $lvList  = $leaveByDate[$d] ?? [];
        $calendarDays[$d] = [
            'date'         => $d,
            'day'          => $day,
            'absent_count' => count($absList),
            'leave_count'  => count($lvList),
            'absentees'    => $absList,
            'on_leave'     => $lvList,
        ];
    }

    $latest = end($trend7Days) ?: ['present_pct' => 0, 'absent_pct' => 0, 'leave_pct' => 0];

    return [
        'selected_date' => $selectedDate,
        'month'         => $monthYm,
        'overview'      => [
            'total_employees' => $totalEmployees,
            'present'         => (int)$selStats['present'],
            'absent'          => (int)$selStats['absent'],
            'late'            => (int)$selStats['late'],
            'on_leave'        => count($selLeaves),
            'overtime'        => (int)$selStats['overtime'],
        ],
        'trend_summary' => [
            'present_pct' => $latest['present_pct'],
            'absent_pct'  => $latest['absent_pct'],
            'leave_pct'   => $latest['leave_pct'],
        ],
        'trend_7_days'  => $trend7Days,
        'calendar_days' => $calendarDays,
    ];
}

$flashOk = '';
$flashErr = '';

$today    = date('Y-m-d');
$rawView  = strtolower(trim((string)($_GET['view'] ?? '')));
$rawTab   = strtolower(trim((string)($_GET['tab'] ?? '')));
$rawDate  = (string)($_GET['date'] ?? '');
$rawMonth = (string)($_GET['month'] ?? '');
$rawRange = (string)($_GET['range'] ?? '');

if ($rawView === 'records' || $rawView === 'history' || $rawTab === 'reports' || $rawTab === 'records') {
    $pageView = 'records';
} else {
    $pageView = 'daily';
}

$filterDate  = ($pageView === 'daily')
    ? $today
    : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) ? $rawDate : $today);
$filterMonth = preg_match('/^\d{4}-\d{2}$/', $rawMonth) ? $rawMonth : substr($filterDate, 0, 7);
$rangeMode   = ($pageView === 'daily')
    ? 'daily'
    : (in_array($rawRange, ['daily', 'weekly', 'monthly'], true) ? $rawRange : 'monthly');
$searchQ     = trim((string)($_GET['q'] ?? ''));

try {
    att_ensure_tables($pdo);
    sched_sync_realtime_attendance($pdo, $today);
    if ($filterDate !== $today) {
        sched_sync_realtime_attendance($pdo, $filterDate);
    }
} catch (Throwable $e) {
    $flashErr = 'Unable to initialize attendance tables: ' . $e->getMessage();
}

$hasEmpTable = att_has_table($pdo, 'employees');
$hasEmpCode  = $hasEmpTable && att_has_col($pdo, 'employees', 'employee_code');
$hasFirst    = $hasEmpTable && att_has_col($pdo, 'employees', 'first_name');
$hasLast     = $hasEmpTable && att_has_col($pdo, 'employees', 'last_name');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flashErr === '') {
    $act = (string)($_POST['att_action'] ?? '');

    try {
        if ($act === 'log_punch') {
            $empId = (int)($_POST['employee_id'] ?? 0);
            $workDate = ($pageView === 'daily')
                ? $today
                : trim((string)($_POST['work_date'] ?? $today));
            $punchType = (string)($_POST['punch_type'] ?? 'time_in');
            $punchTime = trim((string)($_POST['punch_time'] ?? date('H:i:s')));
            if (strlen($punchTime) === 5) $punchTime .= ':00';

            if ($empId <= 0) {
                throw new RuntimeException('Please select an employee.');
            }

            $activeSched = sched_get_for_employee_date($pdo, $empId, $workDate);

            $ins = $pdo->prepare("
                INSERT IGNORE INTO `attendance_records` (`employee_id`, `work_date`, `scheduled_start`, `scheduled_end`, `status`)
                VALUES (?, ?, ?, ?, 'Pending')
            ");
            $ins->execute([$empId, $workDate, $activeSched['shift_start'], $activeSched['shift_end']]);

            $rStmt = $pdo->prepare("SELECT * FROM `attendance_records` WHERE `employee_id` = ? AND `work_date` = ? LIMIT 1");
            $rStmt->execute([$empId, $workDate]);
            $rec = $rStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $timeIn     = $rec['time_in'] ?? null;
            $timeOut    = $rec['time_out'] ?? null;
            $breakStart = $rec['break_start'] ?? null;
            $breakEnd   = $rec['break_end'] ?? null;
            $status     = $rec['status'] ?? 'Pending';

            if ($punchType === 'time_in') {
                $timeIn = $punchTime;
                $status = 'Present';
            } elseif ($punchType === 'time_out') {
                $timeOut = $punchTime;
                if ($status === 'On Break') $status = 'Present';
            } elseif ($punchType === 'break_start') {
                $breakStart = $punchTime;
                if (!$timeOut) $status = 'On Break';
            } elseif ($punchType === 'break_end') {
                $breakEnd = $punchTime;
                if (!$timeOut) $status = 'Present';
            } elseif ($punchType === 'mark_absent') {
                $status = 'Absent';
                $timeIn = null;
                $timeOut = null;
            }

            $eval = sched_evaluate_punch($pdo, $empId, $workDate, $timeIn, $timeOut, $status);

            $upd = $pdo->prepare("
                UPDATE `attendance_records`
                SET `time_in` = ?, `time_out` = ?, `break_start` = ?, `break_end` = ?,
                    `scheduled_start` = ?, `scheduled_end` = ?,
                    `late_minutes` = ?, `overtime_minutes` = ?, `status` = ?
                WHERE `employee_id` = ? AND `work_date` = ?
            ");
            $upd->execute([
                $timeIn, $timeOut, $breakStart, $breakEnd,
                $eval['scheduled_start'], $eval['scheduled_end'],
                $eval['late_minutes'], $eval['overtime_minutes'], $eval['status'],
                $empId, $workDate
            ]);

            $flashOk = 'Attendance record updated for ' . $workDate . ' (' . $eval['status'] . ').';
        } elseif ($act === 'export_csv') {
            $exportScope  = (string)($_POST['export_scope'] ?? 'all');
            $exportEmpId  = (int)($_POST['export_employee_id'] ?? 0);
            $exportDept   = trim((string)($_POST['export_department'] ?? ''));
            $exportStatus = trim((string)($_POST['export_status'] ?? ''));
            $fromDate     = trim((string)($_POST['from_date'] ?? ''));
            $toDate       = trim((string)($_POST['to_date'] ?? ''));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
                $fromDate = '2000-01-01';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
                $toDate = $today;
            }

            $exportRows = att_fetch_unified_records($pdo, $fromDate, $toDate);
            $fileTag = 'all_employees';

            if ($exportScope === 'employee' && $exportEmpId > 0) {
                $exportRows = array_values(array_filter($exportRows, static function (array $r) use ($exportEmpId): bool {
                    return (int)($r['employee_id'] ?? 0) === $exportEmpId;
                }));
                $empSlug = !empty($exportRows[0]['emp_name'])
                    ? preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower((string)$exportRows[0]['emp_name']))
                    : 'emp_' . $exportEmpId;
                $fileTag = trim((string)$empSlug, '_');
            } elseif ($exportScope === 'department' && $exportDept !== '') {
                $exportRows = array_values(array_filter($exportRows, static function (array $r) use ($exportDept): bool {
                    return strcasecmp(trim((string)($r['department_name'] ?? 'Unassigned')), $exportDept) === 0;
                }));
                $deptSlug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($exportDept));
                $fileTag = 'dept_' . trim((string)$deptSlug, '_');
            }

            if ($exportStatus !== '' && strcasecmp($exportStatus, 'all') !== 0) {
                $exportRows = array_values(array_filter($exportRows, static function (array $r) use ($exportStatus): bool {
                    return att_matches_export_status($r, $exportStatus);
                }));
                $stSlug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($exportStatus));
                $fileTag .= '_' . trim((string)$stSlug, '_');
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="attendance_' . $fileTag . '_' . $fromDate . '_to_' . $toDate . '.csv"');

            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Employee Code', 'Employee Name', 'Department', 'Time In', 'Time Out', 'Break Start', 'Break End', 'Late (mins)', 'Overtime (mins)', 'Status']);

            foreach ($exportRows as $row) {
                fputcsv($out, [
                    $row['work_date'],
                    $row['employee_code'],
                    $row['emp_name'],
                    $row['department_name'] ?? 'Unassigned',
                    $row['time_in'],
                    $row['time_out'],
                    $row['break_start'],
                    $row['break_end'],
                    $row['late_minutes'],
                    $row['overtime_minutes'],
                    $row['status']
                ]);
            }
            fclose($out);
            exit;
        }
    } catch (Throwable $e) {
        $flashErr = $e->getMessage();
    }
}

$employees = [];
$departments = [];
if ($hasEmpTable) {
    $eCode = $hasEmpCode ? "`employee_code`" : "''";
    $eFn   = $hasFirst ? "`first_name`" : "''";
    $eLn   = $hasLast ? "`last_name`" : "''";
    $deptMeta = att_dept_sql_meta($pdo);

    $employees = $pdo->query("
        SELECT e.`id`, {$eCode} AS employee_code,
               TRIM(CONCAT({$eFn}, ' ', {$eLn})) AS full_name,
               {$deptMeta['dept_expr']} AS department_name
        FROM `employees` e
        {$deptMeta['join_dept']}
        ORDER BY full_name ASC, e.`id` ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $deptSet = [];
    foreach ($employees as $emp) {
        $dn = trim((string)($emp['department_name'] ?? ''));
        if ($dn !== '') {
            $deptSet[$dn] = $dn;
        }
    }
    if (att_has_table($pdo, 'departments')) {
        $dCol = att_has_col($pdo, 'departments', 'department_name')
            ? 'department_name'
            : (att_has_col($pdo, 'departments', 'name') ? 'name' : '');
        if ($dCol !== '') {
            foreach ($pdo->query("SELECT DISTINCT `{$dCol}` AS dname FROM `departments` WHERE `{$dCol}` IS NOT NULL AND TRIM(`{$dCol}`) <> ''")->fetchAll(PDO::FETCH_ASSOC) as $dr) {
                $dn = trim((string)$dr['dname']);
                if ($dn !== '') $deptSet[$dn] = $dn;
            }
        }
    }
    ksort($deptSet, SORT_NATURAL | SORT_FLAG_CASE);
    $departments = array_values($deptSet);
}

$analytics = att_build_analytics($pdo, $filterDate, $filterMonth, count($employees));
$ov = $analytics['overview'];
$ts = $analytics['trend_summary'];
$pendingCorrections = (int)$pdo->query("SELECT COUNT(*) FROM `attendance_corrections` WHERE `status` = 'Pending'")->fetchColumn();

$dateFrom = $filterDate;
$dateTo   = $filterDate;
if ($pageView === 'records') {
    if ($rangeMode === 'weekly') {
        $dateFrom = date('Y-m-d', strtotime($filterDate . ' -6 days'));
    } elseif ($rangeMode === 'monthly') {
        $dateFrom = date('Y-m-01', strtotime($filterDate));
        $dateTo   = date('Y-m-t', strtotime($filterDate));
    }
}

$attendanceRows = att_fetch_unified_records($pdo, $dateFrom, $dateTo);
if ($searchQ !== '') {
    $needle = strtolower($searchQ);
    $attendanceRows = array_values(array_filter($attendanceRows, static function (array $r) use ($needle): bool {
        return strpos(strtolower((string)$r['emp_name']), $needle) !== false
            || strpos(strtolower((string)$r['employee_code']), $needle) !== false
            || strpos(strtolower((string)($r['department_name'] ?? '')), $needle) !== false
            || strpos(strtolower((string)$r['status']), $needle) !== false
            || strpos((string)$r['employee_id'], $needle) !== false;
    }));
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'live_state') {
    try {
        sched_sync_realtime_attendance($pdo, $today);
        if ($filterDate !== $today) {
            sched_sync_realtime_attendance($pdo, $filterDate);
        }
        $analytics = att_build_analytics($pdo, $filterDate, $filterMonth, count($employees));
        $ov = $analytics['overview'];
        $ts = $analytics['trend_summary'];
        $attendanceRows = att_fetch_unified_records($pdo, $dateFrom, $dateTo);
        if ($searchQ !== '') {
            $needle = strtolower($searchQ);
            $attendanceRows = array_values(array_filter($attendanceRows, static function (array $r) use ($needle): bool {
                return strpos(strtolower((string)$r['emp_name']), $needle) !== false
                    || strpos(strtolower((string)$r['employee_code']), $needle) !== false
                    || strpos(strtolower((string)($r['department_name'] ?? '')), $needle) !== false
                    || strpos(strtolower((string)$r['status']), $needle) !== false
                    || strpos((string)$r['employee_id'], $needle) !== false;
            }));
        }
    } catch (Throwable $e) {}

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode([
        'ok'            => true,
        'server_time'   => date('H:i:s'),
        'selected_date' => $filterDate,
        'month'         => $filterMonth,
        'overview'      => $ov,
        'trend_summary' => $ts,
        'bundle'        => $analytics,
        'rows'          => array_slice($attendanceRows, 0, 200),
        'pending_corr'  => $pendingCorrections,
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
  .att-wrap { max-width: 1320px; margin: 20px auto 56px; padding: 0 20px; color: #f8fafc; }
  .att-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
  .att-title { margin: 0; font-size: 24px; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 10px; }
  .att-sub { margin: 4px 0 0; font-size: 13px; color: #94a3b8; }
  .att-live-pill {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 700; background: rgba(16, 185, 129, 0.16); color: #34d399;
    border: 1px solid rgba(52, 211, 153, 0.35);
  }
  .att-live-dot { width: 7px; height: 7px; border-radius: 50%; background: #34d399; }

  .att-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 18px; }
  .att-kpi { background: #111827; border: 1px solid rgba(148,163,184,0.18); border-radius: 14px; padding: 14px 16px; }
  .att-kpi-lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8; font-weight: 700; }
  .att-kpi-val { font-size: 24px; font-weight: 700; color: #ffffff; margin-top: 6px; }
  .att-kpi-sub { font-size: 11.5px; color: #64748b; margin-top: 2px; }

  .att-board-3 { display: grid; grid-template-columns: 1.05fr 1.25fr 1.2fr; gap: 14px; margin-bottom: 18px; }
  .att-board-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 18px; }
  @media (max-width: 1100px) { .att-board-3, .att-board-2 { grid-template-columns: 1fr; } }

  .att-cal-section { display: grid; grid-template-columns: 1.35fr 1fr; gap: 16px; margin-bottom: 18px; }
  @media (max-width: 1020px) { .att-cal-section { grid-template-columns: 1fr; } }

  .att-card { background: #111827; border: 1px solid rgba(148,163,184,0.18); border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
  .att-card h2, .att-card h3 { margin: 0 0 10px; font-size: 16px; color: #ffffff; }
  .att-form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 12px; }
  .att-input, .att-select {
    width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid #334155;
    background: #0f172a; color: #f8fafc; font-size: 13px;
  }
  .att-btn {
    padding: 8px 14px; border-radius: 8px; border: none; background: #2563eb;
    color: #ffffff; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
  }
  .att-btn:hover { background: #1d4ed8; }
  .att-btn-export { background: #059669; }
  .att-btn-export:hover { background: #047857; }

  .att-export-bar {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px;
    background: #0f172a; border: 1px solid rgba(148,163,184,0.16); border-radius: 10px;
    padding: 10px 14px; margin-bottom: 14px;
  }
  .att-export-fields { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }

  .att-combo-wrap { position: relative; display: inline-block; min-width: 240px; }
  .att-combo-menu {
    position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 50;
    max-height: 240px; overflow-y: auto; background: #0f172a;
    border: 1px solid #475569; border-radius: 8px; box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45);
    display: none;
  }
  .att-combo-menu.is-open { display: block; }
  .att-combo-opt {
    width: 100%; padding: 8px 10px; border: none; background: transparent;
    color: #f8fafc; font-size: 12.5px; text-align: left; cursor: pointer;
    display: flex; flex-direction: column; gap: 2px; border-bottom: 1px solid rgba(148, 163, 184, 0.1);
  }
  .att-combo-opt:last-child { border-bottom: none; }
  .att-combo-opt:hover, .att-combo-opt.is-active { background: rgba(37, 99, 235, 0.28); }
  .att-combo-sub { font-size: 11px; color: #94a3b8; }
  .att-combo-empty { padding: 10px; font-size: 12px; color: #94a3b8; }

  .att-cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; margin-top: 8px; }
  .att-cal-dow { text-align: center; font-size: 11px; font-weight: 700; color: #94a3b8; padding: 4px 0; }
  .att-cal-day {
    border: 1px solid rgba(148,163,184,0.16); background: #0f172a; border-radius: 10px;
    padding: 7px 6px; min-height: 62px; cursor: pointer; text-align: left; color: #e2e8f0;
    display: flex; flex-direction: column; justify-content: space-between;
  }
  .att-cal-day.has-leave { border-color: rgba(59,130,246,0.45); background: rgba(37,99,235,0.1); }
  .att-cal-day.has-absent { border-color: rgba(239,68,68,0.45); background: rgba(239,68,68,0.1); }
  .att-cal-day.is-selected { border-color: #60a5fa; box-shadow: 0 0 0 1px #60a5fa; }
  .att-cal-num { font-size: 12px; font-weight: 700; }
  .att-cal-tags { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 4px; }
  .att-tag-lv { font-size: 10px; font-weight: 700; padding: 1px 5px; border-radius: 999px; background: rgba(59,130,246,0.25); color: #bfdbfe; }
  .att-tag-abs { font-size: 10px; font-weight: 700; padding: 1px 5px; border-radius: 999px; background: rgba(239,68,68,0.25); color: #fca5a5; }

  .att-table-wrap { overflow-x: auto; }
  .att-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .att-table th, .att-table td { padding: 10px 10px; border-bottom: 1px solid rgba(148,163,184,0.14); text-align: left; }
  .att-table th { font-size: 11.5px; text-transform: uppercase; color: #94a3b8; background: #0f172a; }
  .att-badge { padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; display: inline-block; }
  .b-ok { background: rgba(22,163,74,0.2); color: #4ade80; }
  .b-late { background: rgba(245,158,11,0.2); color: #fbbf24; }
  .b-abs { background: rgba(239,68,68,0.2); color: #f87171; }
  .b-pend { background: rgba(148,163,184,0.2); color: #cbd5e1; }
  .att-alert-ok { background: rgba(22,163,74,0.18); border: 1px solid rgba(74,222,128,0.4); color: #bbf7d0; padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; }
  .att-alert-err { background: rgba(239,68,68,0.18); border: 1px solid rgba(248,113,113,0.4); color: #fecaca; padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; }

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
</style>

<div class="att-wrap">
  <div class="att-top">
    <div>
      <h1 class="att-title">
        <span><?= $pageView === 'records' ? 'Attendance Records' : 'Daily Attendance' ?></span>
        <span class="att-live-pill" id="attLiveBadge"><span class="att-live-dot"></span> <span id="attLiveText">LIVE SYNC</span></span>
      </h1>
      <p class="att-sub">
        <?php if ($pageView === 'records'): ?>
          Daily Attendance Trend, Daily Chart Board, Leave Calendar &amp; Attendance Logs &amp; History
        <?php else: ?>
          Today Only (<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>) — Real-time Employee Overview, Daily Trend, Daily Chart Board &amp; Daily Logs DataTable
        <?php endif; ?>
      </p>
    </div>
  </div>

  <?php if ($flashOk !== ''): ?>
    <div class="att-alert-ok"><?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($flashErr !== ''): ?>
    <div class="att-alert-err"><?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <?php if ($pageView === 'daily'): ?>
    <div class="att-kpis">
      <div class="att-kpi">
        <div class="att-kpi-lbl">Total Employees</div>
        <div class="att-kpi-val" id="kpiTotalEmp"><?= (int)$ov['total_employees'] ?></div>
        <div class="att-kpi-sub">Registered workforce</div>
      </div>
      <div class="att-kpi">
        <div class="att-kpi-lbl">Present Today</div>
        <div class="att-kpi-val" id="kpiPresentToday"><?= (int)$ov['present'] ?></div>
        <div class="att-kpi-sub" id="kpiPresentDateSub">Reported on <?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div class="att-kpi">
        <div class="att-kpi-lbl">Absent Today</div>
        <div class="att-kpi-val" id="kpiAbsentToday"><?= (int)$ov['absent'] ?></div>
        <div class="att-kpi-sub">Did not report to work</div>
      </div>
      <div class="att-kpi">
        <div class="att-kpi-lbl">Late Today</div>
        <div class="att-kpi-val" id="kpiLateToday"><?= (int)$ov['late'] ?></div>
        <div class="att-kpi-sub">Arrived past grace time</div>
      </div>
      <div class="att-kpi">
        <div class="att-kpi-lbl">On Leave</div>
        <div class="att-kpi-val" id="kpiOnLeave"><?= (int)$ov['on_leave'] ?></div>
        <div class="att-kpi-sub">Approved leave</div>
      </div>
      <div class="att-kpi">
        <div class="att-kpi-lbl">Overtime</div>
        <div class="att-kpi-val" id="kpiOvertime"><?= (int)$ov['overtime'] ?></div>
        <div class="att-kpi-sub">Worked extra hours</div>
      </div>
      <div class="att-kpi">
        <div class="att-kpi-lbl">Pending Corrections</div>
        <div class="att-kpi-val" id="kpiPendingCorr"><?= $pendingCorrections ?></div>
        <div class="att-kpi-sub">Awaiting HR review</div>
      </div>
    </div>

    <div class="att-board-3">
      <div class="att-card" style="margin-bottom:0;">
        <h3>Employee Overview</h3>
        <div style="display:grid; grid-template-columns:135px 1fr; gap:12px; align-items:center;">
          <canvas id="attOverviewDonut" height="135"></canvas>
          <div style="font-size:13px; line-height:1.8;">
            <div>Present: <strong style="color:#4ade80;" id="ovPresentVal"><?= (int)$ov['present'] ?></strong></div>
            <div>On Leave: <strong style="color:#93c5fd;" id="ovLeaveVal"><?= (int)$ov['on_leave'] ?></strong></div>
            <div>Absent: <strong style="color:#f87171;" id="ovAbsentVal"><?= (int)$ov['absent'] ?></strong></div>
            <div>Late: <strong style="color:#fbbf24;" id="ovLateVal"><?= (int)$ov['late'] ?></strong></div>
          </div>
        </div>
      </div>

      <div class="att-card" style="margin-bottom:0;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
          <h3 style="margin:0;">Daily Attendance Trend</h3>
          <span style="font-size:11.5px; color:#94a3b8;">
            P: <strong style="color:#4ade80;" id="trPresentPct"><?= htmlspecialchars((string)$ts['present_pct'], ENT_QUOTES, 'UTF-8') ?>%</strong> ·
            A: <strong style="color:#f87171;" id="trAbsentPct"><?= htmlspecialchars((string)$ts['absent_pct'], ENT_QUOTES, 'UTF-8') ?>%</strong> ·
            L: <strong style="color:#93c5fd;" id="trLeavePct"><?= htmlspecialchars((string)$ts['leave_pct'], ENT_QUOTES, 'UTF-8') ?>%</strong>
          </span>
        </div>
        <canvas id="attTrendLine" height="145" style="margin-top:8px;"></canvas>
      </div>

      <div class="att-card" style="margin-bottom:0;">
        <h3>Daily Chart Board</h3>
        <canvas id="attBoardBar" height="145"></canvas>
      </div>
    </div>

    <div class="att-card">
      <h2>Record Today's Attendance (<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>)</h2>
      <form method="post">
        <input type="hidden" name="att_action" value="log_punch">
        <input type="hidden" name="work_date" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>">
        <div class="att-form-row">
          <div>
            <label class="att-kpi-lbl">Employee</label>
            <select name="employee_id" class="att-select" required>
              <option value="">Select employee...</option>
              <?php foreach ($employees as $emp): ?>
                <option value="<?= (int)$emp['id'] ?>">
                  <?= htmlspecialchars(($emp['employee_code'] ? $emp['employee_code'] . ' — ' : '') . ($emp['full_name'] ?: 'Employee #' . $emp['id']), ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="att-kpi-lbl">Today's Date</label>
            <input type="date" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" class="att-input" readonly disabled>
          </div>
          <div>
            <label class="att-kpi-lbl">Action</label>
            <select name="punch_type" class="att-select">
              <option value="time_in">Time In</option>
              <option value="time_out">Time Out</option>
              <option value="break_start">Break Start</option>
              <option value="break_end">Break End</option>
              <option value="mark_absent">Mark Absent</option>
            </select>
          </div>
          <div>
            <label class="att-kpi-lbl">Time</label>
            <input type="time" name="punch_time" value="<?= date('H:i') ?>" class="att-input" required>
          </div>
        </div>
        <button type="submit" class="att-btn">Save Attendance Punch</button>
      </form>
    </div>

    <div class="att-card">
      <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;">
        <h2 style="margin:0;">Daily Attendance Logs — Today Only (<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>)</h2>
        <span style="font-size:12px;color:#94a3b8;" id="attLastSyncClock">Real-time sync active</span>
      </div>

      <div class="att-table-wrap">
        <table class="att-table" id="attDailyLogsTable">
          <thead>
            <tr>
              <th>Date</th>
              <th>Employee</th>
              <th>Department</th>
              <th>Time In</th>
              <th>Time Out</th>
              <th>Break</th>
              <th>Late</th>
              <th>Overtime</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody id="attLogsTbody">
            <?php foreach ($attendanceRows as $r): ?>
              <?php
                $hasIn = att_has_valid_time_in($r['time_in'] ?? null);
                $st = (string)($r['status'] ?? 'Pending');
                if (!$hasIn && strcasecmp($st, 'Present') === 0) {
                    $st = 'Pending';
                }
                $badgeClass = $st === 'Absent'
                  ? 'b-abs'
                  : (((int)($r['late_minutes'] ?? 0) > 0 || $st === 'Late')
                      ? 'b-late'
                      : ($st === 'Pending' ? 'b-pend' : 'b-ok'));
              ?>
              <tr>
                <td><?= htmlspecialchars((string)$r['work_date'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <strong><?= htmlspecialchars((string)$r['emp_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                  <?php if (!empty($r['employee_code'])): ?>
                    <div style="font-size:11.5px;color:#94a3b8;"><?= htmlspecialchars((string)$r['employee_code'], ENT_QUOTES, 'UTF-8') ?></div>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string)($r['department_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($r['time_in'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($r['time_out'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(($r['break_start'] ?: '—') . ' - ' . ($r['break_end'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int)($r['late_minutes'] ?? 0) ?>m</td>
                <td><?= (int)($r['overtime_minutes'] ?? 0) ?>m</td>
                <td><span class="att-badge <?= $badgeClass ?>"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php else: ?>
    <div class="att-board-2">
      <div class="att-card" style="margin-bottom:0;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
          <h3 style="margin:0;">Daily Attendance Trend</h3>
          <span style="font-size:11.5px; color:#94a3b8;">
            P: <strong style="color:#4ade80;" id="trPresentPct"><?= htmlspecialchars((string)$ts['present_pct'], ENT_QUOTES, 'UTF-8') ?>%</strong> ·
            A: <strong style="color:#f87171;" id="trAbsentPct"><?= htmlspecialchars((string)$ts['absent_pct'], ENT_QUOTES, 'UTF-8') ?>%</strong> ·
            L: <strong style="color:#93c5fd;" id="trLeavePct"><?= htmlspecialchars((string)$ts['leave_pct'], ENT_QUOTES, 'UTF-8') ?>%</strong>
          </span>
        </div>
        <canvas id="attTrendLine" height="150" style="margin-top:8px;"></canvas>
      </div>

      <div class="att-card" style="margin-bottom:0;">
        <h3>Daily Chart Board</h3>
        <canvas id="attBoardBar" height="150"></canvas>
      </div>
    </div>

    <div class="att-cal-section">
      <div class="att-card" style="margin-bottom:0;">
        <div style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:10px; margin-bottom:8px;">
          <h3 style="margin:0;">Leave Calendar (<?= htmlspecialchars($filterMonth, ENT_QUOTES, 'UTF-8') ?>)</h3>
          <form method="get" style="display:flex; gap:8px; align-items:center;">
            <input type="hidden" name="view" value="records">
            <input type="hidden" name="date" value="<?= htmlspecialchars($filterDate, ENT_QUOTES, 'UTF-8') ?>">
            <input type="month" name="month" value="<?= htmlspecialchars($filterMonth, ENT_QUOTES, 'UTF-8') ?>" onchange="this.form.submit()" class="att-input" style="width:auto; padding:5px 9px;">
          </form>
        </div>
        <div class="att-cal-grid">
          <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
            <div class="att-cal-dow"><?= $dow ?></div>
          <?php endforeach; ?>
          <?php
            $firstWeekday = (int)date('w', strtotime($filterMonth . '-01'));
            for ($pad = 0; $pad < $firstWeekday; $pad++) {
                echo '<div></div>';
            }
            foreach ($analytics['calendar_days'] as $dKey => $cDay):
                $cls = ['att-cal-day'];
                if ($cDay['leave_count'] > 0)  $cls[] = 'has-leave';
                if ($cDay['absent_count'] > 0) $cls[] = 'has-absent';
                if ($dKey === $filterDate)     $cls[] = 'is-selected';
          ?>
            <button type="button" class="<?= implode(' ', $cls) ?>" data-date="<?= htmlspecialchars($dKey, ENT_QUOTES, 'UTF-8') ?>">
              <span class="att-cal-num"><?= (int)$cDay['day'] ?></span>
              <span class="att-cal-tags">
                <?php if ($cDay['leave_count'] > 0): ?>
                  <span class="att-tag-lv"><?= (int)$cDay['leave_count'] ?> Leave</span>
                <?php endif; ?>
                <?php if ($cDay['absent_count'] > 0): ?>
                  <span class="att-tag-abs"><?= (int)$cDay['absent_count'] ?> Absent</span>
                <?php endif; ?>
              </span>
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="att-card" id="attSelectedDayPanel" style="margin-bottom:0;">
        <h3>Schedule for <?= htmlspecialchars($filterDate, ENT_QUOTES, 'UTF-8') ?></h3>
        <p style="font-size:13px; color:#94a3b8;">Loading daily leave and absence data table...</p>
      </div>
    </div>

    <div class="att-card">
      <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;">
        <h2 style="margin:0;">Attendance Logs &amp; History (<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>)</h2>
        <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
          <input type="hidden" name="view" value="records">
          <input type="hidden" name="month" value="<?= htmlspecialchars($filterMonth, ENT_QUOTES, 'UTF-8') ?>">
          <input type="date" name="date" value="<?= htmlspecialchars($filterDate, ENT_QUOTES, 'UTF-8') ?>" class="att-input" style="width:auto;">
          <select name="range" class="att-select" style="width:auto;">
            <option value="daily" <?= $rangeMode === 'daily' ? 'selected' : '' ?>>Daily</option>
            <option value="weekly" <?= $rangeMode === 'weekly' ? 'selected' : '' ?>>Weekly (7d)</option>
            <option value="monthly" <?= $rangeMode === 'monthly' ? 'selected' : '' ?>>Monthly</option>
          </select>
          <input type="text" name="q" value="<?= htmlspecialchars($searchQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search employee, dept, status..." class="att-input" style="width:210px;">
          <button type="submit" class="att-btn">Filter</button>
        </form>
      </div>

      <form method="post" class="att-export-bar" id="attExportForm">
        <input type="hidden" name="att_action" value="export_csv">
        <div class="att-export-fields">
          <span class="att-kpi-lbl" style="margin-right:4px;">Export Records:</span>
          <select name="export_scope" id="attExportScope" class="att-select" style="width:auto;">
            <option value="all">All Employees</option>
            <option value="employee">One Employee</option>
            <option value="department">By Department</option>
          </select>

          <select name="export_status" id="attExportStatus" class="att-select" style="width:auto;" title="Filter exported records by status">
            <option value="">All Statuses</option>
            <option value="Present">Present</option>
            <option value="Late">Late</option>
            <option value="Absent">Absent</option>
          </select>

          <div id="attExportEmpWrap" class="att-combo-wrap" style="display:none;">
            <input
              type="text"
              id="attExportEmpSearch"
              class="att-input"
              placeholder="Type employee name or code..."
              autocomplete="off"
              aria-label="Search employee to export"
            >
            <input type="hidden" name="export_employee_id" id="attExportEmpId" value="">
            <div id="attExportEmpDropdown" class="att-combo-menu" role="listbox"></div>
          </div>

          <select name="export_department" id="attExportDeptWrap" class="att-select" style="width:auto; display:none;">
            <option value="">Select department...</option>
            <?php foreach ($departments as $deptName): ?>
              <option value="<?= htmlspecialchars($deptName, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($deptName, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>

          <input type="date" name="from_date" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>" class="att-input" style="width:auto;" title="From date">
          <span style="color:#64748b;font-size:12px;">to</span>
          <input type="date" name="to_date" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>" class="att-input" style="width:auto;" title="To date">
        </div>

        <button type="submit" class="att-btn att-btn-export">Export CSV</button>
      </form>

      <div class="att-table-wrap">
        <table class="att-table" id="attRecordsLogsTable">
          <thead>
            <tr>
              <th>Date</th>
              <th>Employee</th>
              <th>Department</th>
              <th>Time In</th>
              <th>Time Out</th>
              <th>Break</th>
              <th>Late</th>
              <th>Overtime</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody id="attLogsTbody">
            <?php foreach ($attendanceRows as $r): ?>
              <?php
                $hasIn = att_has_valid_time_in($r['time_in'] ?? null);
                $st = (string)($r['status'] ?? 'Pending');
                if (!$hasIn && strcasecmp($st, 'Present') === 0) {
                    $st = 'Pending';
                }
                $badgeClass = $st === 'Absent'
                  ? 'b-abs'
                  : (((int)($r['late_minutes'] ?? 0) > 0 || $st === 'Late')
                      ? 'b-late'
                      : ($st === 'Pending' ? 'b-pend' : 'b-ok'));
              ?>
              <tr>
                <td><?= htmlspecialchars((string)$r['work_date'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <strong><?= htmlspecialchars((string)$r['emp_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                  <?php if (!empty($r['employee_code'])): ?>
                    <div style="font-size:11.5px;color:#94a3b8;"><?= htmlspecialchars((string)$r['employee_code'], ENT_QUOTES, 'UTF-8') ?></div>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string)($r['department_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($r['time_in'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($r['time_out'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(($r['break_start'] ?: '—') . ' - ' . ($r['break_end'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int)($r['late_minutes'] ?? 0) ?>m</td>
                <td><?= (int)($r['overtime_minutes'] ?? 0) ?>m</td>
                <td><span class="att-badge <?= $badgeClass ?>"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
(function () {
  const state = {
    pageView: <?= json_encode($pageView, JSON_UNESCAPED_UNICODE) ?>,
    analytics: <?= json_encode($analytics, JSON_UNESCAPED_UNICODE) ?>,
    selectedDate: <?= json_encode($filterDate, JSON_UNESCAPED_UNICODE) ?>,
    month: <?= json_encode($filterMonth, JSON_UNESCAPED_UNICODE) ?>,
    range: <?= json_encode($rangeMode, JSON_UNESCAPED_UNICODE) ?>,
    q: <?= json_encode($searchQ, JSON_UNESCAPED_UNICODE) ?>,
    donut: null,
    line: null,
    bar: null,
    dayDt: null,
    logsDt: null
  };

  function escHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function hasValidTimeIn(v) {
    const s = String(v ?? '').trim();
    return s !== '' && s !== '—' && s !== '00:00:00' && s !== '00:00';
  }

  const exportScopeEl = document.getElementById('attExportScope');
  const exportEmpEl   = document.getElementById('attExportEmpWrap');
  const exportDeptEl  = document.getElementById('attExportDeptWrap');
  if (exportScopeEl) {
    const syncExportFields = () => {
      const val = exportScopeEl.value;
      if (exportEmpEl) {
        exportEmpEl.style.display = val === 'employee' ? 'inline-block' : 'none';
      }
      if (exportDeptEl) {
        exportDeptEl.style.display = val === 'department' ? 'inline-block' : 'none';
        exportDeptEl.required = val === 'department';
      }
    };
    exportScopeEl.addEventListener('change', syncExportFields);
    syncExportFields();
  }

  const employeesList = <?= json_encode(array_map(static function (array $emp): array {
    return [
      'id'   => (int)$emp['id'],
      'code' => (string)($emp['employee_code'] ?? ''),
      'name' => (string)($emp['full_name'] ?: ('Employee #' . $emp['id'])),
      'dept' => (string)($emp['department_name'] ?? 'Unassigned'),
    ];
  }, $employees), JSON_UNESCAPED_UNICODE) ?>;

  const empSearchInput = document.getElementById('attExportEmpSearch');
  const empIdInput     = document.getElementById('attExportEmpId');
  const empDropdown    = document.getElementById('attExportEmpDropdown');
  const exportForm     = document.getElementById('attExportForm');

  if (empSearchInput && empIdInput && empDropdown) {
    let activeIdx = -1;
    let currentMatches = [];

    function renderEmpOptions(query) {
      const q = String(query || '').trim().toLowerCase();
      currentMatches = (q === ''
        ? employeesList
        : employeesList.filter(item =>
            item.name.toLowerCase().includes(q) ||
            item.code.toLowerCase().includes(q) ||
            item.dept.toLowerCase().includes(q)
          )
      ).slice(0, 50);

      activeIdx = currentMatches.length > 0 ? 0 : -1;

      if (currentMatches.length === 0) {
        empDropdown.innerHTML = '<div class="att-combo-empty">No matching employees found.</div>';
        empDropdown.classList.add('is-open');
        return;
      }

      empDropdown.innerHTML = currentMatches.map((item, idx) => {
        const meta = [item.code, item.dept].filter(Boolean).join(' · ');
        return `
          <button type="button" class="att-combo-opt${idx === 0 ? ' is-active' : ''}" data-idx="${idx}">
            <span>${escHtml(item.name)}</span>
            ${meta ? `<span class="att-combo-sub">${escHtml(meta)}</span>` : ''}
          </button>
        `;
      }).join('');

      empDropdown.classList.add('is-open');
    }

    function selectEmployee(item) {
      if (!item) return;
      empIdInput.value = String(item.id);
      empSearchInput.value = item.code ? `${item.name} (${item.code})` : item.name;
      empDropdown.classList.remove('is-open');
    }

    empSearchInput.addEventListener('focus', () => renderEmpOptions(empSearchInput.value));
    empSearchInput.addEventListener('input', () => {
      empIdInput.value = '';
      renderEmpOptions(empSearchInput.value);
    });

    empSearchInput.addEventListener('keydown', (e) => {
      if (!empDropdown.classList.contains('is-open') && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
        renderEmpOptions(empSearchInput.value);
        return;
      }
      if (!currentMatches.length) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIdx = (activeIdx + 1) % currentMatches.length;
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIdx = (activeIdx - 1 + currentMatches.length) % currentMatches.length;
      } else if (e.key === 'Enter' && empDropdown.classList.contains('is-open')) {
        e.preventDefault();
        selectEmployee(currentMatches[activeIdx] || currentMatches[0]);
        return;
      } else if (e.key === 'Escape') {
        empDropdown.classList.remove('is-open');
        return;
      } else {
        return;
      }

      empDropdown.querySelectorAll('.att-combo-opt').forEach((btn, idx) => {
        btn.classList.toggle('is-active', idx === activeIdx);
        if (idx === activeIdx) btn.scrollIntoView({ block: 'nearest' });
      });
    });

    empDropdown.addEventListener('mousedown', (e) => {
      const btn = e.target.closest('.att-combo-opt');
      if (!btn) return;
      e.preventDefault();
      const idx = Number(btn.getAttribute('data-idx'));
      selectEmployee(currentMatches[idx]);
    });

    document.addEventListener('click', (e) => {
      if (!empDropdown.contains(e.target) && e.target !== empSearchInput) {
        empDropdown.classList.remove('is-open');
      }
    });

    if (exportForm) {
      exportForm.addEventListener('submit', (e) => {
        if (exportScopeEl && exportScopeEl.value === 'employee' && !empIdInput.value) {
          e.preventDefault();
          renderEmpOptions(empSearchInput.value);
          empSearchInput.focus();
          alert('Please type and select an employee from the list before exporting.');
        }
      });
    }
  }

  function formatLogRowCells(r) {
    const hasIn = hasValidTimeIn(r.time_in);
    let st = String(r.status || 'Pending');
    if (!hasIn && st.toLowerCase() === 'present') st = 'Pending';
    const cls = st === 'Absent'
      ? 'b-abs'
      : ((Number(r.late_minutes || 0) > 0 || st === 'Late')
          ? 'b-late'
          : (st === 'Pending' ? 'b-pend' : 'b-ok'));
    const empHtml = r.employee_code
      ? `<strong>${escHtml(r.emp_name)}</strong><div style="font-size:11.5px;color:#94a3b8;">${escHtml(r.employee_code)}</div>`
      : `<strong>${escHtml(r.emp_name)}</strong>`;

    return [
      escHtml(r.work_date),
      empHtml,
      escHtml(r.department_name || 'Unassigned'),
      escHtml(r.time_in || '—'),
      escHtml(r.time_out || '—'),
      escHtml((r.break_start || '—') + ' - ' + (r.break_end || '—')),
      `${Number(r.late_minutes || 0)}m`,
      `${Number(r.overtime_minutes || 0)}m`,
      `<span class="att-badge ${cls}">${escHtml(st)}</span>`
    ];
  }

  function initOrSyncLogsDataTable(rows) {
    const tableId = state.pageView === 'daily' ? '#attDailyLogsTable' : '#attRecordsLogsTable';
    if (!window.jQuery || !$.fn || !$.fn.DataTable) return;

    const dataArr = Array.isArray(rows) ? rows.map(formatLogRowCells) : null;

    if (!state.logsDt) {
      if ($.fn.DataTable.isDataTable(tableId)) {
        state.logsDt = $(tableId).DataTable();
        if (dataArr) {
          state.logsDt.clear().rows.add(dataArr).draw(false);
        }
      } else {
        state.logsDt = $(tableId).DataTable({
          pageLength: 10,
          lengthMenu: [10, 25, 50, 100],
          order: [[0, 'desc'], [1, 'asc']],
          language: {
            emptyTable: state.pageView === 'daily'
              ? 'No attendance logs recorded today.'
              : 'No attendance records found for this period.',
            zeroRecords: 'No matching attendance logs found.'
          }
        });
      }
      return;
    }

    if (dataArr) {
      state.logsDt.clear().rows.add(dataArr).draw(false);
    }
  }

  function renderDayPanel(dateStr) {
    const panel = document.getElementById('attSelectedDayPanel');
    if (!panel) return;
    const calDays = (state.analytics && state.analytics.calendar_days) || {};
    const day = calDays[dateStr] || { date: dateStr, on_leave: [], absentees: [] };
    const leaves = Array.isArray(day.on_leave) ? day.on_leave : [];
    const abs    = Array.isArray(day.absentees) ? day.absentees : [];

    if (window.jQuery && $.fn && $.fn.DataTable && $.fn.DataTable.isDataTable('#attDayBreakdownTable')) {
      $('#attDayBreakdownTable').DataTable().destroy();
      state.dayDt = null;
    }

    const rowsHtml = [
      ...leaves.map(x => `
        <tr>
          <td><strong>${escHtml(x.emp_name)}</strong></td>
          <td><span class="att-badge" style="background:rgba(59,130,246,0.22);color:#93c5fd;">On Leave</span></td>
          <td>${escHtml(x.leave_type || 'Leave')}</td>
        </tr>
      `),
      ...abs.map(x => `
        <tr>
          <td><strong>${escHtml(x.emp_name)}</strong></td>
          <td><span class="att-badge b-abs">Absent</span></td>
          <td>${escHtml(x.reason || 'Absent')}</td>
        </tr>
      `)
    ].join('');

    panel.innerHTML = `
      <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:8px;">
        <h3 style="margin:0;">Schedule for ${escHtml(dateStr)}</h3>
        <a href="attendance_records.php?date=${encodeURIComponent(dateStr)}&month=${encodeURIComponent(dateStr.slice(0,7))}" style="color:#60a5fa; font-size:12px; font-weight:600; text-decoration:none;">Filter Logs →</a>
      </div>
      <div class="att-table-wrap">
        <table class="att-table" id="attDayBreakdownTable">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Type</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            ${rowsHtml || '<tr><td colspan="3" style="text-align:center;color:#64748b;padding:14px;">No employees on leave or absent for this date.</td></tr>'}
          </tbody>
        </table>
      </div>
    `;

    if (rowsHtml && window.jQuery && $.fn && $.fn.DataTable) {
      state.dayDt = $('#attDayBreakdownTable').DataTable({
        pageLength: 5,
        lengthMenu: [5, 10, 25],
        order: [[1, 'asc'], [0, 'asc']],
        language: {
          lengthMenu: 'Show _MENU_',
          info: '_START_–_END_ of _TOTAL_',
          infoEmpty: 'No entries',
          zeroRecords: 'No matching entries',
          paginate: { previous: '‹', next: '›' }
        }
      });
    }
  }

  function updateCharts() {
    if (typeof Chart === 'undefined') return;
    const ov = (state.analytics && state.analytics.overview) || {};
    const trend = Array.isArray(state.analytics && state.analytics.trend_7_days) ? state.analytics.trend_7_days : [];

    if (state.donut) {
      state.donut.data.datasets[0].data = [ov.present || 0, ov.on_leave || 0, ov.absent || 0, ov.late || 0];
      state.donut.update('none');
    }
    if (state.line) {
      state.line.data.labels = trend.map(t => t.label);
      state.line.data.datasets[0].data = trend.map(t => t.present_pct);
      state.line.data.datasets[1].data = trend.map(t => t.absent_pct);
      state.line.data.datasets[2].data = trend.map(t => t.leave_pct);
      state.line.update('none');
    }
    if (state.bar) {
      state.bar.data.labels = trend.map(t => t.label);
      state.bar.data.datasets[0].data = trend.map(t => t.present);
      state.bar.data.datasets[1].data = trend.map(t => t.on_leave);
      state.bar.data.datasets[2].data = trend.map(t => t.absent);
      state.bar.data.datasets[3].data = trend.map(t => t.late);
      state.bar.update('none');
    }
  }

  function applyLivePayload(payload) {
    if (!payload || !payload.ok || !payload.bundle) return;
    state.analytics = payload.bundle;

    const ov = payload.overview || {};
    const ts = payload.trend_summary || {};

    const setTxt = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = String(val); };
    setTxt('kpiTotalEmp', ov.total_employees ?? 0);
    setTxt('kpiPresentToday', ov.present ?? 0);
    setTxt('kpiPresentDateSub', 'Reported on ' + (payload.selected_date || state.selectedDate));
    setTxt('kpiAbsentToday', ov.absent ?? 0);
    setTxt('kpiLateToday', ov.late ?? 0);
    setTxt('kpiOnLeave', ov.on_leave ?? 0);
    setTxt('kpiOvertime', ov.overtime ?? 0);
    setTxt('kpiPendingCorr', payload.pending_corr ?? 0);

    setTxt('ovPresentVal', ov.present ?? 0);
    setTxt('ovLeaveVal', ov.on_leave ?? 0);
    setTxt('ovAbsentVal', ov.absent ?? 0);
    setTxt('ovLateVal', ov.late ?? 0);

    setTxt('trPresentPct', (ts.present_pct ?? 0) + '%');
    setTxt('trAbsentPct', (ts.absent_pct ?? 0) + '%');
    setTxt('trLeavePct', (ts.leave_pct ?? 0) + '%');

    if (payload.server_time) {
      setTxt('attLastSyncClock', 'Live synced at ' + payload.server_time);
    }

    updateCharts();
    if (state.pageView === 'records') {
      renderDayPanel(state.selectedDate);
    }
    if (Array.isArray(payload.rows)) {
      initOrSyncLogsDataTable(payload.rows);
    }
  }

  async function pollLiveAttendance() {
    try {
      const url = `?ajax=live_state&view=${encodeURIComponent(state.pageView)}&date=${encodeURIComponent(state.selectedDate)}&month=${encodeURIComponent(state.month)}&range=${encodeURIComponent(state.range)}&q=${encodeURIComponent(state.q)}&_t=${Date.now()}`;
      const res = await fetch(url, { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      applyLivePayload(data);
    } catch (_) {}
  }

  document.querySelectorAll('.att-cal-day[data-date]').forEach(btn => {
    btn.addEventListener('click', () => {
      const d = btn.getAttribute('data-date');
      if (!d) return;
      state.selectedDate = d;
      document.querySelectorAll('.att-cal-day').forEach(b => b.classList.remove('is-selected'));
      btn.classList.add('is-selected');
      renderDayPanel(d);
      pollLiveAttendance();
    });
  });

  window.addEventListener('load', function () {
    initOrSyncLogsDataTable(null);

    if (state.pageView === 'records') {
      renderDayPanel(state.selectedDate);
    }

    if (typeof Chart !== 'undefined') {
      const ov = (state.analytics && state.analytics.overview) || {};
      const trend = Array.isArray(state.analytics && state.analytics.trend_7_days) ? state.analytics.trend_7_days : [];

      const donutEl = document.getElementById('attOverviewDonut');
      if (donutEl) {
        state.donut = new Chart(donutEl, {
          type: 'doughnut',
          data: {
            labels: ['Present', 'On Leave', 'Absent', 'Late'],
            datasets: [{
              data: [ov.present || 0, ov.on_leave || 0, ov.absent || 0, ov.late || 0],
              backgroundColor: ['#22c55e', '#3b82f6', '#ef4444', '#f59e0b'],
              borderWidth: 0
            }]
          },
          options: { plugins: { legend: { display: false } }, cutout: '68%' }
        });
      }

      const lineEl = document.getElementById('attTrendLine');
      if (lineEl) {
        state.line = new Chart(lineEl, {
          type: 'line',
          data: {
            labels: trend.map(t => t.label),
            datasets: [
              { label: 'Present %', data: trend.map(t => t.present_pct), borderColor: '#22c55e', tension: 0.3 },
              { label: 'Absent %',  data: trend.map(t => t.absent_pct),  borderColor: '#ef4444', tension: 0.3 },
              { label: 'Leave %',   data: trend.map(t => t.leave_pct),   borderColor: '#3b82f6', tension: 0.3 }
            ]
          },
          options: {
            scales: {
              y: { beginAtZero: true, max: 100, ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148,163,184,0.12)' } },
              x: { ticks: { color: '#94a3b8' }, grid: { display: false } }
            },
            plugins: { legend: { labels: { color: '#cbd5e1', boxWidth: 10 } } }
          }
        });
      }

      const barEl = document.getElementById('attBoardBar');
      if (barEl) {
        state.bar = new Chart(barEl, {
          type: 'bar',
          data: {
            labels: trend.map(t => t.label),
            datasets: [
              { label: 'Present',  data: trend.map(t => t.present),  backgroundColor: '#22c55e' },
              { label: 'On Leave', data: trend.map(t => t.on_leave), backgroundColor: '#3b82f6' },
              { label: 'Absent',   data: trend.map(t => t.absent),   backgroundColor: '#ef4444' },
              { label: 'Late',     data: trend.map(t => t.late),     backgroundColor: '#f59e0b' }
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
    }

    pollLiveAttendance();
    setInterval(pollLiveAttendance, 3000);

    (async function attachWs() {
      const liveText = document.getElementById('attLiveText');
      try {
        for (const u of ['/api/realtime_token.php', '../api/realtime_token.php']) {
          const r = await fetch(u, { cache: 'no-store' });
          if (!r.ok) continue;
          const j = await r.json();
          const token = j.token || (j.data && j.data.token) || '';
          if (!token) continue;
          const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
          const ws = new WebSocket(`${proto}//${window.location.hostname}:8090/ws?token=${encodeURIComponent(token)}`);
          ws.onopen = () => { if (liveText) liveText.textContent = 'LIVE • WS'; };
          ws.onmessage = (ev) => {
            try {
              const pkt = JSON.parse(ev.data);
              const t = (pkt && pkt.event && pkt.event.type) || '';
              if (t.startsWith('attendance.') || t.startsWith('leave.')) {
                pollLiveAttendance();
              }
            } catch (_) {}
          };
          ws.onclose = () => { if (liveText) liveText.textContent = 'LIVE POLL'; };
          break;
        }
      } catch (_) {}
    })();
  });
})();
</script>