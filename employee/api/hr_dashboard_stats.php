<?php
// api/hr_dashboard_stats.php
define('HRIS_DB_THROW_ON_FAIL', true);
require_once __DIR__ . '/../authentication/guard.php';
require_access('dashboard', 'view', null, ['admin', 'hr']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    require_once __DIR__ . '/../config/database.php';
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Database unavailable', 'daily_attendance' => []]);
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(503);
    echo json_encode(['error' => 'Database connection not initialized', 'daily_attendance' => []]);
    exit;
}

$rawDate = trim((string)($_GET['date'] ?? date('Y-m-d')));
$targetDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) && strtotime($rawDate) !== false)
    ? $rawDate
    : date('Y-m-d');

function tableColumns(PDO $pdo, string $table): array {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return [];
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['Field'])) {
                $cols[strtolower((string)$row['Field'])] = (string)$row['Field'];
            }
        }
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

$empCols   = tableColumns($pdo, 'employees');
$attCols   = tableColumns($pdo, 'attendance');
$leaveCols = tableColumns($pdo, 'leave_requests');
$posCols   = tableColumns($pdo, 'positions');
$deptCols  = tableColumns($pdo, 'departments');

// Detect attendance date/time_in column & employee link
$attTimeInCol = $attCols['time_in'] ?? ($attCols['clock_in'] ?? ($attCols['created_at'] ?? null));
$attTimeOutCol = $attCols['time_out'] ?? ($attCols['clock_out'] ?? null);
$attBreakCol = $attCols['break_time'] ?? ($attCols['break_start'] ?? null);
$attHoursCol = $attCols['workhours'] ?? ($attCols['work_hours'] ?? ($attCols['total_hours'] ?? null));

$attJoinCond = '1=0';
if (!empty($attCols['employee_id']) && !empty($empCols['id'])) {
    $attJoinCond = "a.`{$attCols['employee_id']}` = e.`{$empCols['id']}`";
} elseif (!empty($attCols['email']) && !empty($empCols['email'])) {
    $attJoinCond = "LOWER(TRIM(a.`{$attCols['email']}`)) = LOWER(TRIM(e.`{$empCols['email']}`))";
}

$attDateExpr = $attTimeInCol ? "DATE(a.`{$attTimeInCol}`)" : null;

// Total employees
$totalEmployees = 0;
if (!empty($empCols)) {
    try {
        $totalEmployees = (int)$pdo->query("SELECT COUNT(*) FROM `employees`")->fetchColumn();
    } catch (Throwable $e) {}
}

// Active / Present employees for $targetDate
$activeForDate = 0;
if (!empty($attCols) && $attDateExpr) {
    try {
        $countExpr = !empty($attCols['employee_id'])
            ? "COUNT(DISTINCT a.`{$attCols['employee_id']}`)"
            : (!empty($attCols['email']) ? "COUNT(DISTINCT LOWER(TRIM(a.`{$attCols['email']}`)))" : "COUNT(*)");
        $stmt = $pdo->prepare("
            SELECT {$countExpr}
            FROM `attendance` a
            WHERE {$attDateExpr} = :target_date
        ");
        $stmt->execute([':target_date' => $targetDate]);
        $activeForDate = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}
}

// On Leave employees for $targetDate
$onLeaveForDate = 0;
if (!empty($leaveCols) && !empty($leaveCols['start_date']) && !empty($leaveCols['end_date'])) {
    try {
        $statusFilter = !empty($leaveCols['status'])
            ? "AND LOWER(COALESCE(lr.`{$leaveCols['status']}`, '')) IN ('approved', 'active')"
            : "";
        $leaveCountExpr = !empty($leaveCols['employee_id'])
            ? "COUNT(DISTINCT lr.`{$leaveCols['employee_id']}`)"
            : (!empty($leaveCols['email']) ? "COUNT(DISTINCT LOWER(TRIM(lr.`{$leaveCols['email']}`)))" : "COUNT(*)");
        $stmt = $pdo->prepare("
            SELECT {$leaveCountExpr}
            FROM `leave_requests` lr
            WHERE 1=1
              {$statusFilter}
              AND :target_date BETWEEN DATE(lr.`{$leaveCols['start_date']}`) AND DATE(lr.`{$leaveCols['end_date']}`)
        ");
        $stmt->execute([':target_date' => $targetDate]);
        $onLeaveForDate = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}
}

// Pending leave requests
$pendingRequests = 0;
if (!empty($leaveCols) && !empty($leaveCols['status'])) {
    try {
        $pendingRequests = (int)$pdo->query("
            SELECT COUNT(*) FROM `leave_requests` WHERE LOWER(COALESCE(`{$leaveCols['status']}`, '')) = 'pending'
        ")->fetchColumn();
    } catch (Throwable $e) {}
}

// Build Employee ID, Name, Position, Department, Status list for $targetDate
$dailyRows = [];
if (!empty($empCols)) {
    $idCol = $empCols['id'] ?? ($empCols['employee_id'] ?? null);
    $idSelect = $idCol ? "e.`{$idCol}`" : "0";

    $nameParts = [];
    if (!empty($empCols['first_name'])) $nameParts[] = "COALESCE(e.`{$empCols['first_name']}`, '')";
    if (!empty($empCols['middle_name'])) $nameParts[] = "COALESCE(e.`{$empCols['middle_name']}`, '')";
    if (!empty($empCols['last_name'])) $nameParts[] = "COALESCE(e.`{$empCols['last_name']}`, '')";

    $concatExpr = count($nameParts) > 0
        ? "TRIM(CONCAT_WS(' ', " . implode(', ', $nameParts) . "))"
        : "''";

    $fallbackNameCols = [];
    if (!empty($empCols['full_name'])) $fallbackNameCols[] = "NULLIF(TRIM(e.`{$empCols['full_name']}`), '')";
    if (!empty($empCols['name'])) $fallbackNameCols[] = "NULLIF(TRIM(e.`{$empCols['name']}`), '')";
    if (!empty($empCols['email'])) $fallbackNameCols[] = "NULLIF(TRIM(e.`{$empCols['email']}`), '')";
    $fallbackNameCols[] = $idCol ? "CONCAT('Employee #', e.`{$idCol}`)" : "'Employee'";

    $nameSelect = "COALESCE(NULLIF({$concatExpr}, ''), " . implode(', ', $fallbackNameCols) . ")";

    // Position expression
    $posJoin = '';
    $posCandidates = [];
    if (!empty($posCols)) {
        $posTitleCol = $posCols['title'] ?? ($posCols['name'] ?? ($posCols['position_name'] ?? null));
        $posPkCol = $posCols['id'] ?? null;
        $empPosFk = $empCols['position_id'] ?? ($empCols['pos_id'] ?? null);
        if ($posTitleCol && $posPkCol && $empPosFk) {
            $posJoin = "LEFT JOIN `positions` p ON p.`{$posPkCol}` = e.`{$empPosFk}`";
            $posCandidates[] = "NULLIF(TRIM(p.`{$posTitleCol}`), '')";
        }
    }
    if (!empty($empCols['position'])) $posCandidates[] = "NULLIF(TRIM(e.`{$empCols['position']}`), '')";
    if (!empty($empCols['job_title'])) $posCandidates[] = "NULLIF(TRIM(e.`{$empCols['job_title']}`), '')";
    if (!empty($empCols['rank'])) $posCandidates[] = "NULLIF(TRIM(e.`{$empCols['rank']}`), '')";
    $posCandidates[] = "'Unassigned'";
    $posSelect = 'COALESCE(' . implode(', ', $posCandidates) . ')';

    // Department expression
    $deptJoin = '';
    $deptCandidates = [];
    if (!empty($deptCols)) {
        $deptNameCol = $deptCols['name'] ?? ($deptCols['department_name'] ?? ($deptCols['title'] ?? null));
        $deptPkCol = $deptCols['id'] ?? null;
        $empDeptFk = $empCols['department_id'] ?? ($empCols['dept_id'] ?? null);
        if ($deptNameCol && $deptPkCol && $empDeptFk) {
            $deptJoin = "LEFT JOIN `departments` d ON d.`{$deptPkCol}` = e.`{$empDeptFk}`";
            $deptCandidates[] = "NULLIF(TRIM(d.`{$deptNameCol}`), '')";
        }
    }
    if (!empty($empCols['department'])) $deptCandidates[] = "NULLIF(TRIM(e.`{$empCols['department']}`), '')";
    if (!empty($empCols['dept'])) $deptCandidates[] = "NULLIF(TRIM(e.`{$empCols['dept']}`), '')";
    $deptCandidates[] = "'Unassigned'";
    $deptSelect = 'COALESCE(' . implode(', ', $deptCandidates) . ')';

    // Attendance & Leave joins for $targetDate
    $attJoin = '';
    $timeInSelect = 'NULL';
    $breakSelect = 'NULL';
    $timeOutSelect = 'NULL';
    $hoursSelect = 'NULL';
    $hasTimeInCond = '0';
    $hasTimeOutCond = '0';

    if (!empty($attCols) && $attDateExpr) {
        $attJoin = "LEFT JOIN `attendance` a ON ({$attJoinCond}) AND {$attDateExpr} = :target_date";
        if ($attTimeInCol) {
            $timeInSelect = "a.`{$attTimeInCol}`";
            $hasTimeInCond = "a.`{$attTimeInCol}` IS NOT NULL";
        }
        if ($attBreakCol) $breakSelect = "a.`{$attBreakCol}`";
        if ($attTimeOutCol) {
            $timeOutSelect = "a.`{$attTimeOutCol}`";
            $hasTimeOutCond = "a.`{$attTimeOutCol}` IS NOT NULL";
        }
        if ($attHoursCol) $hoursSelect = "a.`{$attHoursCol}`";
    }

    $leaveJoin = '';
    $hasLeaveCond = '0';
    if (!empty($leaveCols) && !empty($leaveCols['start_date']) && !empty($leaveCols['end_date'])) {
        $leaveJoinCond = '1=0';
        if (!empty($leaveCols['employee_id']) && $idCol) {
            $leaveJoinCond = "lr.`{$leaveCols['employee_id']}` = e.`{$idCol}`";
        } elseif (!empty($leaveCols['email']) && !empty($empCols['email'])) {
            $leaveJoinCond = "LOWER(TRIM(lr.`{$leaveCols['email']}`)) = LOWER(TRIM(e.`{$empCols['email']}`))";
        }
        $leaveStatusCond = !empty($leaveCols['status'])
            ? "AND LOWER(COALESCE(lr.`{$leaveCols['status']}`, '')) IN ('approved', 'active')"
            : "";
        $leaveJoin = "LEFT JOIN `leave_requests` lr ON ({$leaveJoinCond}) {$leaveStatusCond} AND :target_date BETWEEN DATE(lr.`{$leaveCols['start_date']}`) AND DATE(lr.`{$leaveCols['end_date']}`)";
        $leavePk = $leaveCols['id'] ?? '1';
        $hasLeaveCond = "lr.`{$leavePk}` IS NOT NULL";
    }

    $statusSelect = "
        CASE
            WHEN ({$hasTimeInCond}) AND NOT ({$hasTimeOutCond}) THEN 'Active'
            WHEN ({$hasTimeInCond}) AND ({$hasTimeOutCond}) THEN 'Present'
            WHEN ({$hasLeaveCond}) THEN 'On Leave'
            ELSE 'Absent'
        END
    ";

    $orderBy = $idCol ? "e.`{$idCol}` ASC" : "{$nameSelect} ASC";

    $sql = "
        SELECT
            {$idSelect} AS employee_id,
            {$nameSelect} AS employee_name,
            {$posSelect} AS position_name,
            {$deptSelect} AS department_name,
            {$timeInSelect} AS time_in,
            {$breakSelect} AS break_time,
            {$timeOutSelect} AS time_out,
            {$hoursSelect} AS workhours,
            {$statusSelect} AS day_status
        FROM `employees` e
        {$posJoin}
        {$deptJoin}
        {$attJoin}
        {$leaveJoin}
        ORDER BY {$orderBy}
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $params = [];
        if (strpos($sql, ':target_date') !== false) {
            $params[':target_date'] = $targetDate;
        }
        $stmt->execute($params);
        $dailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('hr_dashboard_stats daily query error: ' . $e->getMessage());
    }
}

// CSV report download for the selected calendar date
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_report_' . $targetDate . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Date',
        'Employee ID',
        'Name',
        'Position',
        'Department',
        'Status',
        'Time In',
        'Time Out',
        'Work Hours',
    ]);
    foreach ($dailyRows as $r) {
        fputcsv($out, [
            $targetDate,
            $r['employee_id'] ?? '',
            $r['employee_name'] ?? '',
            $r['position_name'] ?? 'Unassigned',
            $r['department_name'] ?? 'Unassigned',
            $r['day_status'] ?? 'Absent',
            $r['time_in'] ?? '',
            $r['time_out'] ?? '',
            $r['workhours'] !== null && $r['workhours'] !== '' ? $r['workhours'] : '',
        ]);
    }
    fclose($out);
    exit;
}

// 7-day Attendance Trend (Present % & Absent % rating)
$trendLabels  = [];
$trendPresent = [];
$trendAbsent  = [];

try {
    $dayBuckets = [];
    $baseDt = new DateTime($targetDate);
    $startDt = clone $baseDt;
    $startDt->modify('-6 days');

    $iter = clone $startDt;
    while ($iter <= $baseDt) {
        $ymd = $iter->format('Y-m-d');
        $dayBuckets[$ymd] = [
            'label'   => $iter->format('M j'),
            'present' => 0,
        ];
        $iter->modify('+1 day');
    }

    if (!empty($attCols) && $attDateExpr) {
        $countExpr = !empty($attCols['employee_id'])
            ? "COUNT(DISTINCT `{$attCols['employee_id']}`)"
            : (!empty($attCols['email']) ? "COUNT(DISTINCT LOWER(TRIM(`{$attCols['email']}`)))" : "COUNT(*)");
        $rawTrendDateExpr = str_replace('a.', '', $attDateExpr);
        $stmt = $pdo->prepare("
            SELECT {$rawTrendDateExpr} AS d, {$countExpr} AS cnt
            FROM `attendance`
            WHERE {$rawTrendDateExpr} BETWEEN :start_d AND :end_d
            GROUP BY {$rawTrendDateExpr}
        ");
        $stmt->execute([
            ':start_d' => $startDt->format('Y-m-d'),
            ':end_d'   => $baseDt->format('Y-m-d'),
        ]);

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $d = $r['d'] ?? '';
            if (isset($dayBuckets[$d])) {
                $dayBuckets[$d]['present'] = (int)($r['cnt'] ?? 0);
            }
        }
    }

    foreach ($dayBuckets as $b) {
        $trendLabels[] = $b['label'];
        if ($totalEmployees > 0) {
            $p = min(100, round(($b['present'] / $totalEmployees) * 100, 1));
            $a = max(0, round(100 - $p, 1));
        } else {
            $p = 0;
            $a = 0;
        }
        $trendPresent[] = $p;
        $trendAbsent[]  = $a;
    }
} catch (Throwable $e) {}

$selectedPresentPct = $totalEmployees > 0 ? min(100, round(($activeForDate / $totalEmployees) * 100, 1)) : 0;
$selectedAbsentPct  = max(0, round(100 - $selectedPresentPct, 1));
$avgPresentPct      = count($trendPresent) > 0 ? round(array_sum($trendPresent) / count($trendPresent), 1) : 0;
$avgAbsentPct       = count($trendAbsent) > 0 ? round(array_sum($trendAbsent) / count($trendAbsent), 1) : 0;

echo json_encode([
    'selected_date' => $targetDate,
    'kpis' => [
        'total_employees'  => $totalEmployees,
        'present_today'    => $activeForDate,
        'on_leave'         => $onLeaveForDate,
        'pending_requests' => $pendingRequests,
    ],
    'overview' => [
        'active_today' => $activeForDate,
        'on_leave'     => $onLeaveForDate,
        'total'        => $activeForDate + $onLeaveForDate,
    ],
    'attendance_trend' => [
        'labels'  => $trendLabels,
        'present' => $trendPresent,
        'absent'  => $trendAbsent,
        'summary' => [
            'selected_present_pct' => $selectedPresentPct,
            'selected_absent_pct'  => $selectedAbsentPct,
            'avg_present_pct'      => $avgPresentPct,
            'avg_absent_pct'       => $avgAbsentPct,
        ],
    ],
    'daily_attendance' => $dailyRows,
    'timestamp' => date('h:i:s A'),
]);