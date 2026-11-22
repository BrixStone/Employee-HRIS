<?php
// hr/schedule_management.php

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
    sched_ensure_schema($pdo);
    sched_auto_record_absences($pdo, date('Y-m-d'));
} catch (Throwable $e) {}

function sm_map_work_days(string $label): array {
    switch ($label) {
        case 'Monday - Saturday':
            return ['Monday - Saturday', 'Mon,Tue,Wed,Thu,Fri,Sat', 'Sun'];
        case 'Saturday - Sunday':
            return ['Saturday - Sunday', 'Sat,Sun', 'Mon,Tue,Wed,Thu,Fri'];
        case 'Monday - Sunday (7 Days)':
            return ['Monday - Sunday', 'Mon,Tue,Wed,Thu,Fri,Sat,Sun', ''];
        case 'Monday - Friday':
        default:
            return ['Monday - Friday', 'Mon,Tue,Wed,Thu,Fri', 'Sat,Sun'];
    }
}

function sm_normalize_time_input(string $raw, string $default = '08:00:00'): string {
    $raw = trim($raw);
    if ($raw === '') return $default;
    if (preg_match('/^\d{2}:\d{2}$/', $raw)) return $raw . ':00';
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $raw)) return $raw;
    $ts = strtotime($raw);
    return $ts !== false ? date('H:i:s', $ts) : $default;
}

function sm_format_12h(?string $timeStr): string {
    if (!$timeStr) return '08:00 AM';
    $ts = strtotime($timeStr);
    return $ts !== false ? date('h:i A', $ts) : $timeStr;
}

function sm_format_date_human(?string $dateStr): string {
    if (!$dateStr) return 'Immediate';
    $ts = strtotime($dateStr);
    return $ts !== false ? date('F j, Y', $ts) : $dateStr;
}

$flashOk = '';
$flashErr = '';
$actor = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'HR Officer'));

$hasEmp  = sched_has_table($pdo, 'employees');
$hasFn   = $hasEmp && sched_has_col($pdo, 'employees', 'first_name');
$hasLn   = $hasEmp && sched_has_col($pdo, 'employees', 'last_name');
$hasCode = $hasEmp && sched_has_col($pdo, 'employees', 'employee_code');

$fnExpr   = $hasFn ? "COALESCE(e.`first_name`, '')" : "''";
$lnExpr   = $hasLn ? "COALESCE(e.`last_name`, '')" : "''";
$codeExpr = $hasCode ? "COALESCE(e.`employee_code`, '')" : "''";

$hasDeptTbl     = sched_has_table($pdo, 'departments');
$hasEmpDeptId   = $hasEmp && sched_has_col($pdo, 'employees', 'department_id');
$empDeptTextCol = $hasEmp
    ? (sched_has_col($pdo, 'employees', 'department') ? 'department' : (sched_has_col($pdo, 'employees', 'department_name') ? 'department_name' : ''))
    : '';
$hasPosTbl      = sched_has_table($pdo, 'positions');
$hasPosId       = $hasEmp && sched_has_col($pdo, 'employees', 'position_id');
$hasPosDeptId   = $hasPosTbl && sched_has_col($pdo, 'positions', 'department_id');

$joins = [];
$deptParts = [];
if ($hasDeptTbl && $hasEmpDeptId) {
    $joins[] = "LEFT JOIN `departments` d_emp ON d_emp.`id` = e.`department_id`";
    $deptParts[] = "NULLIF(TRIM(d_emp.`name`), '')";
}
if ($hasPosTbl && $hasPosId && $hasPosDeptId && $hasDeptTbl) {
    $joins[] = "LEFT JOIN `positions` p_emp ON p_emp.`id` = e.`position_id`";
    $joins[] = "LEFT JOIN `departments` d_pos ON d_pos.`id` = p_emp.`department_id`";
    $deptParts[] = "NULLIF(TRIM(d_pos.`name`), '')";
}
if ($empDeptTextCol !== '') {
    $deptParts[] = "NULLIF(TRIM(e.`{$empDeptTextCol}`), '')";
}
$deptSelectExpr = !empty($deptParts) ? 'COALESCE(' . implode(', ', $deptParts) . ", 'General')" : "'General'";
$joinDeptSql = implode(' ', $joins);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['sm_action'] ?? '');
    try {
        if ($act === 'save_schedule') {
            $editId     = (int)($_POST['schedule_id'] ?? 0);
            $name       = trim((string)($_POST['schedule_name'] ?? ''));
            $daysPreset = trim((string)($_POST['work_days'] ?? 'Monday - Friday'));
            $timeIn     = sm_normalize_time_input((string)($_POST['time_in'] ?? '08:00'), '08:00:00');
            $timeOut    = sm_normalize_time_input((string)($_POST['time_out'] ?? '17:00'), '17:00:00');
            $breakLabel = trim((string)($_POST['break_time'] ?? '12:00 PM - 01:00 PM'));
            $otAfter    = max(0, (int)($_POST['overtime_after_minutes'] ?? 0));
            $otMult     = max(1.0, min(3.0, (float)($_POST['overtime_multiplier'] ?? 1.25)));
            $effDate    = trim((string)($_POST['effective_date'] ?? date('Y-m-d')));
            $status     = in_array(($_POST['status'] ?? 'Active'), ['Active', 'Inactive'], true) ? $_POST['status'] : 'Active';
            $isActive   = ($status === 'Active') ? 1 : 0;

            if ($name === '') {
                throw new RuntimeException('Schedule Name is required.');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effDate)) {
                $effDate = date('Y-m-d');
            }

            [$daysLabel, $workDaysCsv, $restDaysCsv] = sm_map_work_days($daysPreset);

            if ($editId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE `work_shifts`
                    SET `shift_name` = ?, `work_days_label` = ?, `working_days` = ?, `rest_days` = ?,
                        `start_time` = ?, `end_time` = ?, `break_time_label` = ?,
                        `overtime_after_minutes` = ?, `overtime_multiplier` = ?,
                        `effective_date` = ?, `status` = ?, `is_active` = ?
                    WHERE `id` = ?
                ");
                $stmt->execute([
                    $name, $daysLabel, $workDaysCsv, $restDaysCsv,
                    $timeIn, $timeOut, $breakLabel,
                    $otAfter, $otMult, $effDate, $status, $isActive, $editId
                ]);

                $pdo->prepare("
                    UPDATE `employee_work_schedules`
                    SET `shift_name` = ?, `work_days_label` = ?, `working_days` = ?, `rest_days` = ?,
                        `shift_start` = ?, `shift_end` = ?, `break_time_label` = ?,
                        `overtime_after_minutes` = ?, `overtime_multiplier` = ?, `effective_from` = ?
                    WHERE `shift_id` = ?
                ")->execute([
                    $name, $daysLabel, $workDaysCsv, $restDaysCsv,
                    $timeIn, $timeOut, $breakLabel, $otAfter, $otMult, $effDate, $editId
                ]);

                $flashOk = "Schedule '{$name}' updated with overtime rule ({$otMult}x).";
            } else {
                $code = 'SCH-' . strtoupper(substr(md5($name . microtime()), 0, 5));
                $stmt = $pdo->prepare("
                    INSERT INTO `work_shifts`
                        (`shift_name`, `shift_code`, `work_days_label`, `working_days`, `rest_days`, `start_time`, `end_time`, `break_time_label`, `break_minutes`, `grace_minutes`, `overtime_after_minutes`, `overtime_multiplier`, `effective_date`, `status`, `is_active`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 60, 15, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $name, $code, $daysLabel, $workDaysCsv, $restDaysCsv,
                    $timeIn, $timeOut, $breakLabel, $otAfter, $otMult, $effDate, $status, $isActive
                ]);
                $flashOk = "Schedule '{$name}' created with overtime rule ({$otMult}x).";
            }
        } elseif ($act === 'duplicate_schedule') {
            $srcId = (int)($_POST['schedule_id'] ?? 0);
            $s = $pdo->prepare("SELECT * FROM `work_shifts` WHERE `id` = ? LIMIT 1");
            $s->execute([$srcId]);
            if ($row = $s->fetch(PDO::FETCH_ASSOC)) {
                $copyName = $row['shift_name'] . ' (Copy)';
                $code = 'SCH-' . strtoupper(substr(md5($copyName . microtime()), 0, 5));
                $pdo->prepare("
                    INSERT INTO `work_shifts`
                        (`shift_name`, `shift_code`, `work_days_label`, `working_days`, `rest_days`, `start_time`, `end_time`, `break_time_label`, `break_minutes`, `grace_minutes`, `overtime_after_minutes`, `overtime_multiplier`, `effective_date`, `status`, `is_active`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $copyName, $code, $row['work_days_label'], $row['working_days'], $row['rest_days'],
                    $row['start_time'], $row['end_time'], $row['break_time_label'],
                    (int)$row['break_minutes'], (int)$row['grace_minutes'],
                    (int)($row['overtime_after_minutes'] ?? 0), (float)($row['overtime_multiplier'] ?? 1.25),
                    $row['effective_date'] ?: date('Y-m-d'), $row['status'] ?: 'Active', (int)$row['is_active']
                ]);
                $flashOk = "Duplicated '{$row['shift_name']}'.";
            }
        } elseif ($act === 'delete_schedule') {
            $delId = (int)($_POST['schedule_id'] ?? 0);
            if ($delId > 0) {
                $pdo->prepare("DELETE FROM `work_shifts` WHERE `id` = ?")->execute([$delId]);
                $flashOk = 'Schedule removed.';
            }
        } elseif ($act === 'assign_schedule') {
            $targetMode = (string)($_POST['assign_mode'] ?? 'department');
            $deptChoice = trim((string)($_POST['department_name'] ?? ''));
            $empId      = (int)($_POST['employee_id'] ?? 0);
            $shiftId    = (int)($_POST['shift_id'] ?? 0);
            $startDt    = trim((string)($_POST['start_date'] ?? date('Y-m-d')));

            if ($shiftId <= 0) throw new RuntimeException('Please select a schedule.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDt)) $startDt = date('Y-m-d');

            $shStmt = $pdo->prepare("SELECT * FROM `work_shifts` WHERE `id` = ? LIMIT 1");
            $shStmt->execute([$shiftId]);
            $sh = $shStmt->fetch(PDO::FETCH_ASSOC);
            if (!$sh) throw new RuntimeException('Selected schedule does not exist.');

            $targetEmployees = [];

            if ($targetMode === 'department') {
                if ($deptChoice === '') {
                    throw new RuntimeException('Please select a department to schedule.');
                }
                if ($hasEmp) {
                    $dStmt = $pdo->prepare("
                        SELECT e.`id`, {$deptSelectExpr} AS dept
                        FROM `employees` e
                        {$joinDeptSql}
                        WHERE LOWER(TRIM({$deptSelectExpr})) = LOWER(?)
                    ");
                    $dStmt->execute([$deptChoice]);
                    foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $targetEmployees[] = ['id' => (int)$r['id'], 'department' => (string)$r['dept']];
                    }
                }
                if (empty($targetEmployees)) {
                    throw new RuntimeException("No employees found in department '{$deptChoice}'.");
                }
            } else {
                if ($empId <= 0) throw new RuntimeException('Please select an employee.');
                $empDept = sched_resolve_employee_department($pdo, $empId);
                $targetEmployees[] = ['id' => $empId, 'department' => $empDept];
            }

            $upsert = $pdo->prepare("
                INSERT INTO `employee_work_schedules`
                    (`employee_id`, `department_name`, `shift_id`, `shift_name`, `work_days_label`, `working_days`, `rest_days`,
                     `shift_start`, `shift_end`, `break_time_label`, `grace_minutes`, `overtime_after_minutes`, `overtime_multiplier`,
                     `effective_from`, `assigned_by`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    `department_name`        = VALUES(`department_name`),
                    `shift_id`               = VALUES(`shift_id`),
                    `shift_name`             = VALUES(`shift_name`),
                    `work_days_label`        = VALUES(`work_days_label`),
                    `working_days`           = VALUES(`working_days`),
                    `rest_days`              = VALUES(`rest_days`),
                    `shift_start`            = VALUES(`shift_start`),
                    `shift_end`              = VALUES(`shift_end`),
                    `break_time_label`       = VALUES(`break_time_label`),
                    `grace_minutes`          = VALUES(`grace_minutes`),
                    `overtime_after_minutes` = VALUES(`overtime_after_minutes`),
                    `overtime_multiplier`    = VALUES(`overtime_multiplier`),
                    `effective_from`         = VALUES(`effective_from`),
                    `assigned_by`            = VALUES(`assigned_by`)
            ");

            $hist = $pdo->prepare("
                INSERT INTO `employee_schedule_history`
                    (`employee_id`, `department_name`, `shift_id`, `shift_name`, `shift_start`, `shift_end`,
                     `working_days`, `rest_days`, `overtime_after_minutes`, `overtime_multiplier`,
                     `effective_from`, `action_type`, `changed_by`, `notes`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Assigned', ?, ?)
            ");

            foreach ($targetEmployees as $te) {
                $eid = $te['id'];
                $dName = $te['department'];
                $upsert->execute([
                    $eid, $dName, $shiftId, $sh['shift_name'], $sh['work_days_label'],
                    $sh['working_days'], $sh['rest_days'], $sh['start_time'], $sh['end_time'],
                    $sh['break_time_label'], (int)$sh['grace_minutes'],
                    (int)($sh['overtime_after_minutes'] ?? 0), (float)($sh['overtime_multiplier'] ?? 1.25),
                    $startDt, $actor
                ]);
                $hist->execute([
                    $eid, $dName, $shiftId, $sh['shift_name'], $sh['start_time'], $sh['end_time'],
                    $sh['working_days'], $sh['rest_days'],
                    (int)($sh['overtime_after_minutes'] ?? 0), (float)($sh['overtime_multiplier'] ?? 1.25),
                    $startDt, $actor,
                    $targetMode === 'department' ? "Department schedule ({$dName})" : 'Employee schedule'
                ]);

                $attChk = $pdo->prepare("SELECT `time_in`, `time_out`, `status` FROM `attendance_records` WHERE `employee_id` = ? AND `work_date` = ? LIMIT 1");
                $attChk->execute([$eid, date('Y-m-d')]);
                if ($ar = $attChk->fetch(PDO::FETCH_ASSOC)) {
                    $ev = sched_evaluate_punch($pdo, $eid, date('Y-m-d'), $ar['time_in'] ?: null, $ar['time_out'] ?: null, (string)($ar['status'] ?? 'Present'));
                    $pdo->prepare("
                        UPDATE `attendance_records`
                        SET `scheduled_start` = ?, `scheduled_end` = ?, `late_minutes` = ?, `overtime_minutes` = ?, `status` = ?
                        WHERE `employee_id` = ? AND `work_date` = ?
                    ")->execute([$ev['scheduled_start'], $ev['scheduled_end'], $ev['late_minutes'], $ev['overtime_minutes'], $ev['status'], $eid, date('Y-m-d')]);
                }
            }

            sched_auto_record_absences($pdo, date('Y-m-d'));

            if ($targetMode === 'department') {
                $flashOk = "Assigned '{$sh['shift_name']}' to all " . count($targetEmployees) . " employees in '{$deptChoice}'. Late & absence monitoring is active.";
            } else {
                $flashOk = "Assigned '{$sh['shift_name']}' starting {$startDt}. Late & absence monitoring is active.";
            }
        }
    } catch (Throwable $e) {
        $flashErr = $e->getMessage();
    }
}

$employees = [];
$departments = [];

if ($hasEmp) {
    $employees = $pdo->query("
        SELECT e.`id`, {$codeExpr} AS employee_code,
               COALESCE(NULLIF(TRIM(CONCAT({$fnExpr}, ' ', {$lnExpr})), ''), CONCAT('Employee #', e.`id`)) AS full_name,
               {$deptSelectExpr} AS department
        FROM `employees` e
        {$joinDeptSql}
        ORDER BY full_name ASC, e.`id` ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

if ($hasDeptTbl) {
    $dRows = $pdo->query("
        SELECT `name`
        FROM `departments`
        WHERE LOWER(COALESCE(`status`, 'active')) <> 'inactive'
        ORDER BY `id` ASC
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($dRows as $dn) {
        $clean = trim((string)$dn);
        if ($clean !== '' && !in_array($clean, $departments, true)) {
            $departments[] = $clean;
        }
    }
}

foreach ($employees as $em) {
    $d = trim((string)($em['department'] ?? ''));
    if ($d !== '' && strcasecmp($d, 'General') !== 0 && !in_array($d, $departments, true)) {
        $departments[] = $d;
    }
}

$rawShifts = $pdo->query("SELECT * FROM `work_shifts` WHERE LOWER(`shift_name`) <> 'night shift' ORDER BY `id` ASC")->fetchAll(PDO::FETCH_ASSOC);
$schedulesList = [];
foreach ($rawShifts as $s) {
    $stLabel = ($s['status'] ?? ((int)$s['is_active'] === 1 ? 'Active' : 'Inactive')) === 'Inactive' ? 'Inactive' : 'Active';
    $daysDisp = $s['work_days_label'] ?: ($s['working_days'] ?: 'Mon - Fri');
    $shortDays = 'Mon - Fri';
    if (stripos($daysDisp, 'Sat') !== false && stripos($daysDisp, 'Sun') !== false && stripos($daysDisp, 'Mon') === false) {
        $shortDays = 'Sat - Sun';
    } elseif (stripos($daysDisp, 'Sat') !== false && stripos($daysDisp, 'Mon') !== false) {
        $shortDays = 'Mon - Sat';
    }
    $otMult = number_format((float)($s['overtime_multiplier'] ?? 1.25), 2);
    $schedulesList[] = [
        'id'                     => (int)$s['id'],
        'schedule_name'          => (string)$s['shift_name'],
        'work_days_preset'       => $daysDisp,
        'work_days_short'        => $shortDays,
        'work_days_full'         => $daysDisp,
        'time_in_raw'            => substr((string)$s['start_time'], 0, 5),
        'time_out_raw'           => substr((string)$s['end_time'], 0, 5),
        'time_in_12h'            => sm_format_12h((string)$s['start_time']),
        'time_out_12h'           => sm_format_12h((string)$s['end_time']),
        'time_range_12h'         => sm_format_12h((string)$s['start_time']) . ' – ' . sm_format_12h((string)$s['end_time']),
        'break_time'             => (string)($s['break_time_label'] ?: '12:00 PM - 01:00 PM'),
        'overtime_after_minutes' => (int)($s['overtime_after_minutes'] ?? 0),
        'overtime_multiplier'    => $otMult,
        'overtime_label'         => ((int)($s['overtime_after_minutes'] ?? 0) > 0 ? '+' . (int)$s['overtime_after_minutes'] . 'm buffer · ' : 'Immediate after out · ') . $otMult . 'x OT',
        'effective_date'         => (string)($s['effective_date'] ?: date('Y-m-d')),
        'effective_human'        => sm_format_date_human($s['effective_date'] ?? date('Y-m-d')),
        'status'                 => $stLabel,
    ];
}

$assignedRows = $pdo->query("SELECT `employee_id`, `department_name`, `shift_name`, `effective_from` FROM `employee_work_schedules`")->fetchAll(PDO::FETCH_ASSOC);
$assignedMap = [];
foreach ($assignedRows as $ar) {
    $assignedMap[(int)$ar['employee_id']] = $ar;
}

$totalEmpCount    = count($employees);
$withSchedCount   = count($assignedMap);
$noSchedCount     = max(0, $totalEmpCount - $withSchedCount);
$activeSchedCount = count(array_filter($schedulesList, static fn($x) => $x['status'] === 'Active'));

$kpis = [
    'total_employees'  => $totalEmpCount,
    'with_schedule'    => $withSchedCount,
    'no_schedule'      => $noSchedCount,
    'active_schedules' => $activeSchedCount,
];

if (isset($_GET['ajax']) && $_GET['ajax'] === 'live_schedule_state') {
    sched_auto_record_absences($pdo, date('Y-m-d'));
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode([
        'ok'        => true,
        'kpis'      => $kpis,
        'schedules' => $schedulesList,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$hrHeader = __DIR__ . '/../templates/hrTemplates/header.php';
if (is_file($hrHeader)) {
    require_once $hrHeader;
}

$firstSched = $schedulesList[0] ?? [
    'id'              => 0,
    'schedule_name'   => 'Regular Day Shift',
    'work_days_full'  => 'Monday - Friday',
    'time_in_12h'     => '08:00 AM',
    'time_out_12h'    => '05:00 PM',
    'break_time'      => '12:00 PM - 01:00 PM',
    'overtime_label'  => 'Immediate after out · 1.25x OT',
    'effective_human' => date('F j, Y'),
    'status'          => 'Active',
];
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js" defer></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js" defer></script>

<style>
  .sm-page {
    min-height: calc(100vh - 64px);
    background: radial-gradient(circle at 75% 8%, rgba(124, 58, 237, 0.24), transparent 45%),
                radial-gradient(circle at 15% 85%, rgba(99, 102, 241, 0.16), transparent 50%),
                #0b0718;
    color: #f5f3ff;
    padding: 22px 26px 54px;
    font-family: inherit;
  }
  .sm-inner { max-width: 1380px; margin: 0 auto; }

  .sm-breadcrumb {
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px;
    font-size: 13px; color: #a5b4fc; margin-bottom: 18px;
  }
  .sm-breadcrumb-trail { display: flex; align-items: center; gap: 8px; }
  .sm-breadcrumb-trail a { color: #c4b5fd; text-decoration: none; }
  .sm-breadcrumb-trail a:hover { color: #ffffff; }
  .sm-live-tag {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 999px;
    background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(52, 211, 153, 0.35);
    color: #6ee7b7; font-size: 11px; font-weight: 700; letter-spacing: 0.04em;
  }
  .sm-live-dot { width: 7px; height: 7px; border-radius: 50%; background: #34d399; }

  .sm-hero {
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px;
    margin-bottom: 20px;
  }
  .sm-hero-left { display: flex; align-items: center; gap: 14px; }
  .sm-hero-icon {
    width: 52px; height: 52px; border-radius: 14px;
    background: linear-gradient(135deg, #7c3aed, #6366f1);
    display: grid; place-items: center; font-size: 24px; box-shadow: 0 10px 24px rgba(124, 58, 237, 0.35);
  }
  .sm-title { margin: 0; font-size: 28px; font-weight: 800; color: #ffffff; letter-spacing: -0.02em; }
  .sm-sub { margin: 3px 0 0; font-size: 13.5px; color: #a78bfa; }

  .sm-btn-primary {
    background: linear-gradient(135deg, #7c3aed, #6d28d9);
    color: #ffffff; border: 1px solid rgba(196, 181, 253, 0.25); border-radius: 10px;
    padding: 10px 18px; font-size: 13.5px; font-weight: 700; cursor: pointer;
    box-shadow: 0 8px 20px rgba(109, 40, 217, 0.38); transition: transform 0.12s ease, filter 0.12s ease;
  }
  .sm-btn-primary:hover { filter: brightness(1.08); transform: translateY(-1px); }

  .sm-btn-secondary {
    background: rgba(255, 255, 255, 0.06); color: #e9d5ff;
    border: 1px solid rgba(167, 139, 250, 0.3); border-radius: 10px;
    padding: 10px 16px; font-size: 13.5px; font-weight: 600; cursor: pointer;
  }
  .sm-btn-secondary:hover { background: rgba(255, 255, 255, 0.11); }

  .sm-kpis {
    display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 20px;
  }
  @media (max-width: 1024px) { .sm-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  @media (max-width: 560px)  { .sm-kpis { grid-template-columns: 1fr; } }

  .sm-kpi {
    background: linear-gradient(145deg, rgba(30, 18, 66, 0.92), rgba(18, 11, 41, 0.95));
    border: 1px solid rgba(139, 92, 246, 0.26); border-radius: 16px;
    padding: 16px 18px; display: flex; align-items: center; gap: 14px;
    box-shadow: 0 10px 26px rgba(5, 2, 16, 0.45);
  }
  .sm-kpi-badge {
    width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; font-size: 20px; flex-shrink: 0;
  }
  .k-blue   { background: rgba(59, 130, 246, 0.2); border: 1px solid rgba(96, 165, 250, 0.35); }
  .k-green  { background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(52, 211, 153, 0.35); }
  .k-amber  { background: rgba(245, 158, 11, 0.2); border: 1px solid rgba(251, 191, 36, 0.35); }
  .k-purple { background: rgba(139, 92, 246, 0.24); border: 1px solid rgba(167, 139, 250, 0.4); }
  .sm-kpi-lbl { font-size: 12.5px; color: #c4b5fd; font-weight: 600; }
  .sm-kpi-val { font-size: 28px; font-weight: 800; color: #ffffff; line-height: 1.15; margin-top: 2px; }

  .sm-top-grid {
    display: grid; grid-template-columns: 1.45fr 1fr; gap: 18px; margin-bottom: 18px; align-items: start;
  }
  @media (max-width: 1080px) { .sm-top-grid { grid-template-columns: 1fr; } }

  .sm-bottom-grid {
    display: grid; grid-template-columns: 1.65fr 1fr; gap: 18px; align-items: start;
  }
  @media (max-width: 1080px) { .sm-bottom-grid { grid-template-columns: 1fr; } }

  .sm-panel {
    background: linear-gradient(160deg, rgba(25, 15, 56, 0.95), rgba(15, 9, 34, 0.98));
    border: 1px solid rgba(139, 92, 246, 0.28); border-radius: 16px;
    overflow: hidden; box-shadow: 0 14px 32px rgba(4, 2, 14, 0.55);
  }
  .sm-panel-hdr {
    background: linear-gradient(90deg, rgba(109, 40, 217, 0.55), rgba(79, 70, 229, 0.35));
    border-bottom: 1px solid rgba(167, 139, 250, 0.25);
    padding: 13px 18px; display: flex; justify-content: space-between; align-items: center; gap: 10px;
  }
  .sm-panel-hdr h2, .sm-panel-hdr h3 {
    margin: 0; font-size: 15.5px; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 8px;
  }
  .sm-panel-body { padding: 18px; }

  .sm-form-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-bottom: 14px; }
  .sm-form-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
  @media (max-width: 640px) { .sm-form-2, .sm-form-3 { grid-template-columns: 1fr; } }

  .sm-field label { display: block; font-size: 12.5px; font-weight: 600; color: #ddd6fe; margin-bottom: 6px; }
  .sm-req { color: #f87171; }
  .sm-control {
    width: 100%; padding: 10px 12px; border-radius: 10px;
    border: 1px solid rgba(139, 92, 246, 0.35); background: rgba(11, 7, 25, 0.85);
    color: #ffffff; font-size: 13.5px; outline: none;
  }
  .sm-control:focus { border-color: #a78bfa; box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.25); }

  .sm-mode-switch { display: inline-flex; gap: 6px; margin-bottom: 12px; background: rgba(11,7,25,0.7); padding: 4px; border-radius: 10px; border: 1px solid rgba(139,92,246,0.25); }
  .sm-mode-btn { padding: 5px 12px; border-radius: 7px; border: none; background: transparent; color: #c4b5fd; font-size: 12px; font-weight: 700; cursor: pointer; }
  .sm-mode-btn.is-active { background: #7c3aed; color: #ffffff; }

  .sm-form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }
  .sm-info-banner {
    margin-top: 14px; padding: 11px 13px; border-radius: 10px;
    background: rgba(99, 102, 241, 0.14); border: 1px solid rgba(129, 140, 248, 0.3);
    color: #c7d2fe; font-size: 12.5px; display: flex; align-items: center; gap: 9px;
  }

  .sm-toolbar { display: flex; gap: 8px; align-items: center; }
  .sm-search {
    padding: 7px 12px; border-radius: 9px; border: 1px solid rgba(167, 139, 250, 0.32);
    background: rgba(11, 7, 25, 0.8); color: #ffffff; font-size: 12.5px; width: 170px;
  }
  .sm-filter-sel {
    padding: 7px 10px; border-radius: 9px; border: 1px solid rgba(167, 139, 250, 0.32);
    background: rgba(11, 7, 25, 0.8); color: #ffffff; font-size: 12.5px;
  }

  .sm-table-wrap { overflow-x: auto; }
  .sm-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .sm-table th {
    text-align: left; padding: 10px 10px; color: #c4b5fd; font-size: 12px; font-weight: 700;
    border-bottom: 1px solid rgba(139, 92, 246, 0.25); white-space: nowrap;
  }
  .sm-table td {
    padding: 11px 10px; border-bottom: 1px solid rgba(139, 92, 246, 0.14); color: #f5f3ff; vertical-align: middle;
  }
  .sm-table tr:hover td { background: rgba(124, 58, 237, 0.08); cursor: pointer; }

  .dataTables_wrapper {
    color: #e9d5ff;
    font-size: 12.5px;
    margin-top: 4px;
  }
  .dataTables_wrapper .dataTables_length,
  .dataTables_wrapper .dataTables_filter {
    margin-bottom: 10px;
    color: #c4b5fd;
  }
  .dataTables_wrapper .dataTables_filter {
    display: none;
  }
  .dataTables_wrapper .dataTables_length select {
    background: rgba(11, 7, 25, 0.85);
    color: #ffffff;
    border: 1px solid rgba(167, 139, 250, 0.35);
    border-radius: 8px;
    padding: 4px 8px;
    margin: 0 4px;
  }
  .dataTables_wrapper .dataTables_info {
    color: #a78bfa !important;
    padding-top: 10px !important;
  }
  .dataTables_wrapper .dataTables_paginate {
    padding-top: 10px !important;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button {
    color: #ddd6fe !important;
    border: 1px solid rgba(139, 92, 246, 0.25) !important;
    background: rgba(18, 11, 41, 0.8) !important;
    border-radius: 7px !important;
    padding: 4px 10px !important;
    margin-left: 4px !important;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button.current,
  .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
    background: linear-gradient(135deg, #7c3aed, #6d28d9) !important;
    color: #ffffff !important;
    border-color: rgba(196, 181, 253, 0.4) !important;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    background: rgba(124, 58, 237, 0.3) !important;
    color: #ffffff !important;
  }
  table.dataTable.no-footer {
    border-bottom: 1px solid rgba(139, 92, 246, 0.25) !important;
  }

  .sm-pill-active {
    display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700;
    background: rgba(16, 185, 129, 0.2); color: #6ee7b7; border: 1px solid rgba(52, 211, 153, 0.4);
  }
  .sm-pill-inactive {
    display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700;
    background: rgba(148, 163, 184, 0.18); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.35);
  }

  .sm-act-btns { display: inline-flex; gap: 6px; }
  .sm-icon-btn {
    width: 30px; height: 30px; border-radius: 8px; border: 1px solid rgba(167, 139, 250, 0.3);
    background: rgba(124, 58, 237, 0.22); color: #ffffff; cursor: pointer; font-size: 12.5px;
    display: inline-grid; place-items: center;
  }
  .sm-icon-btn:hover { background: rgba(124, 58, 237, 0.45); }
  .sm-icon-btn.del { background: rgba(239, 68, 68, 0.2); border-color: rgba(248, 113, 113, 0.35); }
  .sm-icon-btn.del:hover { background: rgba(239, 68, 68, 0.4); }

  .sm-detail-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 14px; }
  .sm-detail-name { font-size: 18px; font-weight: 800; color: #ffffff; }
  .sm-detail-rows { display: grid; gap: 11px; font-size: 13.5px; }
  .sm-detail-row { display: flex; justify-content: space-between; gap: 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(139, 92, 246, 0.14); }
  .sm-detail-k { color: #c4b5fd; display: flex; align-items: center; gap: 8px; }
  .sm-detail-v { font-weight: 700; color: #ffffff; text-align: right; }

  .sm-alert-ok { background: rgba(16,185,129,0.16); border: 1px solid rgba(52,211,153,0.4); color: #a7f3d0; padding: 10px 14px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; }
  .sm-alert-err { background: rgba(239,68,68,0.16); border: 1px solid rgba(248,113,113,0.4); color: #fecaca; padding: 10px 14px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; }
</style>

<div class="sm-page">
  <div class="sm-inner">
    <div class="sm-breadcrumb">
      <div class="sm-breadcrumb-trail">
        <a href="/hr/dashboard.php">🏠</a>
        <span>&rsaquo;</span>
        <a href="/hr/attendance.php">Attendance Management</a>
        <span>&rsaquo;</span>
        <strong style="color:#ffffff;">Schedule Management</strong>
      </div>
      <span class="sm-live-tag"><span class="sm-live-dot"></span> <span id="smLiveBadgeText">LIVE • WS</span></span>
    </div>

    <div class="sm-hero">
      <div class="sm-hero-left">
        <div class="sm-hero-icon">📅</div>
        <div>
          <h1 class="sm-title">Schedule Management</h1>
          <p class="sm-sub">Create, assign by department or employee, and auto-track late, absence, and overtime.</p>
        </div>
      </div>
      <button type="button" class="sm-btn-primary" onclick="focusCreateSchedule()">+ Create Schedule</button>
    </div>

    <?php if ($flashOk !== ''): ?>
      <div class="sm-alert-ok"><?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
      <div class="sm-alert-err"><?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="sm-kpis">
      <div class="sm-kpi">
        <div class="sm-kpi-badge k-blue">👥</div>
        <div>
          <div class="sm-kpi-lbl">Total Employees</div>
          <div class="sm-kpi-val" id="kpiTotalEmp"><?= (int)$kpis['total_employees'] ?></div>
        </div>
      </div>
      <div class="sm-kpi">
        <div class="sm-kpi-badge k-green">🗓️</div>
        <div>
          <div class="sm-kpi-lbl">With Schedule</div>
          <div class="sm-kpi-val" id="kpiWithSched"><?= (int)$kpis['with_schedule'] ?></div>
        </div>
      </div>
      <div class="sm-kpi">
        <div class="sm-kpi-badge k-amber">🕒</div>
        <div>
          <div class="sm-kpi-lbl">No Schedule</div>
          <div class="sm-kpi-val" id="kpiNoSched"><?= (int)$kpis['no_schedule'] ?></div>
        </div>
      </div>
      <div class="sm-kpi">
        <div class="sm-kpi-badge k-purple">📆</div>
        <div>
          <div class="sm-kpi-lbl">Active Schedules</div>
          <div class="sm-kpi-val" id="kpiActiveSched"><?= (int)$kpis['active_schedules'] ?></div>
        </div>
      </div>
    </div>

    <div class="sm-top-grid">
      <div class="sm-panel">
        <div class="sm-panel-hdr">
          <h2 id="smFormHeading">➕ Create Schedule</h2>
        </div>
        <div class="sm-panel-body">
          <form method="post" id="smCreateForm">
            <input type="hidden" name="sm_action" value="save_schedule">
            <input type="hidden" name="schedule_id" id="smEditId" value="0">
            <input type="hidden" name="status" id="smEditStatus" value="Active">

            <div class="sm-form-2">
              <div class="sm-field">
                <label>Schedule Name <span class="sm-req">*</span></label>
                <input type="text" name="schedule_name" id="smSchedName" class="sm-control" placeholder="Regular Day Shift" required>
              </div>
              <div class="sm-field">
                <label>Work Days <span class="sm-req">*</span></label>
                <select name="work_days" id="smWorkDays" class="sm-control">
                  <option value="Monday - Friday">Monday – Friday</option>
                  <option value="Monday - Saturday">Monday – Saturday</option>
                  <option value="Saturday - Sunday">Saturday – Sunday</option>
                  <option value="Monday - Sunday (7 Days)">Monday – Sunday</option>
                </select>
              </div>
            </div>

            <div class="sm-form-3">
              <div class="sm-field">
                <label>Time In <span class="sm-req">*</span></label>
                <input type="time" name="time_in" id="smTimeIn" value="08:00" class="sm-control" required>
              </div>
              <div class="sm-field">
                <label>Time Out <span class="sm-req">*</span></label>
                <input type="time" name="time_out" id="smTimeOut" value="17:00" class="sm-control" required>
              </div>
              <div class="sm-field">
                <label>Break Time</label>
                <select name="break_time" id="smBreakTime" class="sm-control">
                  <option value="12:00 PM - 01:00 PM">12:00 PM – 01:00 PM</option>
                  <option value="11:30 AM - 12:30 PM">11:30 AM – 12:30 PM</option>
                  <option value="05:00 PM - 06:00 PM">05:00 PM – 06:00 PM</option>
                  <option value="No Break">No Break</option>
                </select>
              </div>
            </div>

            <div class="sm-form-3">
              <div class="sm-field">
                <label>OT Buffer After Out (mins)</label>
                <input type="number" name="overtime_after_minutes" id="smOtAfter" value="0" min="0" max="240" class="sm-control">
              </div>
              <div class="sm-field">
                <label>Overtime Rate</label>
                <select name="overtime_multiplier" id="smOtMult" class="sm-control">
                  <option value="1.25">1.25x (Regular Overtime)</option>
                  <option value="1.30">1.30x (Rest Day Overtime)</option>
                  <option value="1.50">1.50x (Extended Overtime)</option>
                </select>
              </div>
              <div class="sm-field">
                <label>Effective Date <span class="sm-req">*</span></label>
                <input type="date" name="effective_date" id="smEffectiveDate" value="<?= date('Y-m-d') ?>" class="sm-control" required>
              </div>
            </div>

            <div class="sm-form-actions">
              <button type="button" class="sm-btn-secondary" onclick="resetScheduleForm()">↻ Reset</button>
              <button type="submit" class="sm-btn-primary" id="smSaveBtn">💾 Save Schedule</button>
            </div>
          </form>
        </div>
      </div>

      <div class="sm-panel">
        <div class="sm-panel-hdr">
          <h3>👥 Assign Schedule to Department / Employee</h3>
        </div>
        <div class="sm-panel-body">
          <div class="sm-mode-switch" role="tablist">
            <button type="button" class="sm-mode-btn is-active" id="modeDeptBtn" onclick="setAssignMode('department')">By Department (All Staff)</button>
            <button type="button" class="sm-mode-btn" id="modeEmpBtn" onclick="setAssignMode('employee')">Single Employee</button>
          </div>

          <form method="post" id="smAssignForm">
            <input type="hidden" name="sm_action" value="assign_schedule">
            <input type="hidden" name="assign_mode" id="smAssignModeInput" value="department">

            <div class="sm-form-2">
              <div class="sm-field" id="deptAssignField">
                <label>Department <span class="sm-req">*</span></label>
                <select name="department_name" id="smDeptSelect" class="sm-control" required>
                  <option value="">Select department...</option>
                  <?php foreach ($departments as $dept): ?>
                    <option value="<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="sm-field" id="empAssignField" style="display:none;">
                <label>Employee <span class="sm-req">*</span></label>
                <select name="employee_id" id="smEmpSelect" class="sm-control">
                  <option value="">Select employee...</option>
                  <?php foreach ($employees as $emp): ?>
                    <?php $eid = (int)$emp['id']; ?>
                    <option value="<?= $eid ?>">
                      <?= htmlspecialchars($emp['full_name'] . ' (' . $emp['department'] . ')' . (isset($assignedMap[$eid]) ? ' — ' . $assignedMap[$eid]['shift_name'] : ''), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="sm-field">
                <label>Schedule <span class="sm-req">*</span></label>
                <select name="shift_id" class="sm-control" required>
                  <?php foreach ($schedulesList as $sc): ?>
                    <option value="<?= (int)$sc['id'] ?>">
                      <?= htmlspecialchars($sc['schedule_name'] . ' (' . $sc['time_in_12h'] . ' - ' . $sc['time_out_12h'] . ')', ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="sm-field" style="max-width:260px;">
              <label>Start Date <span class="sm-req">*</span></label>
              <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" class="sm-control" required>
            </div>

            <div style="text-align:center; margin-top:16px;">
              <button type="submit" class="sm-btn-primary">✓ Assign Schedule</button>
            </div>

            <div class="sm-info-banner">
              <span>ℹ️</span>
              <span>Linked to employees &amp; departments: late arrivals past grace time, absences on scheduled workdays, and overtime beyond shift end are recorded automatically.</span>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="sm-bottom-grid">
      <div class="sm-panel">
        <div class="sm-panel-hdr">
          <h3>📋 Schedule List</h3>
          <div class="sm-toolbar">
            <input type="text" id="smSearchInput" class="sm-search" placeholder="🔍 Search schedule...">
            <select id="smStatusFilter" class="sm-filter-sel">
              <option value="All">All</option>
              <option value="Active">Active</option>
              <option value="Inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="sm-panel-body" style="padding:12px 16px;">
          <div class="sm-table-wrap">
            <table class="sm-table" id="smSchedulesDataTable">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Schedule Name</th>
                  <th>Work Days</th>
                  <th>Time In – Time Out</th>
                  <th>Break Time</th>
                  <th>Overtime</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="smScheduleTbody">
                <?php foreach ($schedulesList as $idx => $sc): ?>
                  <tr data-status="<?= htmlspecialchars($sc['status'], ENT_QUOTES, 'UTF-8') ?>" onclick='selectScheduleDetail(<?= json_encode($sc, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                    <td><?= $idx + 1 ?></td>
                    <td><strong><?= htmlspecialchars($sc['schedule_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td><?= htmlspecialchars($sc['work_days_short'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><strong><?= htmlspecialchars($sc['time_range_12h'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td><?= htmlspecialchars($sc['break_time'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span style="color:#c4b5fd;font-weight:600;"><?= htmlspecialchars($sc['overtime_multiplier'] . 'x', ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td>
                      <span class="<?= $sc['status'] === 'Active' ? 'sm-pill-active' : 'sm-pill-inactive' ?>">
                        <?= htmlspecialchars($sc['status'], ENT_QUOTES, 'UTF-8') ?>
                      </span>
                    </td>
                    <td onclick="event.stopPropagation()">
                      <div class="sm-act-btns">
                        <button type="button" class="sm-icon-btn" title="Edit Schedule" onclick='editScheduleItem(<?= json_encode($sc, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✏️</button>
                        <form method="post" style="display:inline;">
                          <input type="hidden" name="sm_action" value="duplicate_schedule">
                          <input type="hidden" name="schedule_id" value="<?= (int)$sc['id'] ?>">
                          <button type="submit" class="sm-icon-btn" title="Duplicate Schedule">📋</button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this schedule?');">
                          <input type="hidden" name="sm_action" value="delete_schedule">
                          <input type="hidden" name="schedule_id" value="<?= (int)$sc['id'] ?>">
                          <button type="submit" class="sm-icon-btn del" title="Delete Schedule">🗑️</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="sm-panel">
        <div class="sm-panel-hdr">
          <h3>🕒 Schedule Details</h3>
        </div>
        <div class="sm-panel-body" id="smDetailsCard">
          <div class="sm-detail-top">
            <div class="sm-detail-name" id="detName"><?= htmlspecialchars($firstSched['schedule_name'], ENT_QUOTES, 'UTF-8') ?></div>
            <span class="<?= $firstSched['status'] === 'Active' ? 'sm-pill-active' : 'sm-pill-inactive' ?>" id="detStatus">
              <?= htmlspecialchars($firstSched['status'], ENT_QUOTES, 'UTF-8') ?>
            </span>
          </div>
          <div class="sm-detail-rows">
            <div class="sm-detail-row">
              <span class="sm-detail-k">📅 Work Days:</span>
              <span class="sm-detail-v" id="detDays"><?= htmlspecialchars($firstSched['work_days_full'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="sm-detail-row">
              <span class="sm-detail-k">🕒 Time In:</span>
              <span class="sm-detail-v" id="detIn"><?= htmlspecialchars($firstSched['time_in_12h'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="sm-detail-row">
              <span class="sm-detail-k">🕔 Time Out:</span>
              <span class="sm-detail-v" id="detOut"><?= htmlspecialchars($firstSched['time_out_12h'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="sm-detail-row">
              <span class="sm-detail-k">☕ Break Time:</span>
              <span class="sm-detail-v" id="detBreak"><?= htmlspecialchars($firstSched['break_time'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="sm-detail-row">
              <span class="sm-detail-k">⏱️ Overtime Rule:</span>
              <span class="sm-detail-v" id="detOt"><?= htmlspecialchars($firstSched['overtime_label'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="sm-detail-row" style="border-bottom:none; padding-bottom:0;">
              <span class="sm-detail-k">📅 Effective Date:</span>
              <span class="sm-detail-v" id="detEff"><?= htmlspecialchars($firstSched['effective_human'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
let schedulesCache = <?= json_encode($schedulesList, JSON_UNESCAPED_UNICODE) ?>;
let selectedScheduleId = <?= (int)($firstSched['id'] ?? 0) ?>;
let scheduleDataTable = null;

function esc(v) {
  return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function setAssignMode(mode) {
  const isDept = mode === 'department';
  document.getElementById('smAssignModeInput').value = isDept ? 'department' : 'employee';
  document.getElementById('modeDeptBtn')?.classList.toggle('is-active', isDept);
  document.getElementById('modeEmpBtn')?.classList.toggle('is-active', !isDept);

  const deptField = document.getElementById('deptAssignField');
  const empField  = document.getElementById('empAssignField');
  const deptSel   = document.getElementById('smDeptSelect');
  const empSel    = document.getElementById('smEmpSelect');

  if (deptField) deptField.style.display = isDept ? '' : 'none';
  if (empField)  empField.style.display  = isDept ? 'none' : '';
  if (deptSel)   deptSel.required = isDept;
  if (empSel)    empSel.required  = !isDept;
}

function focusCreateSchedule() {
  resetScheduleForm();
  document.getElementById('smSchedName')?.focus();
  document.getElementById('smCreateForm')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function resetScheduleForm() {
  document.getElementById('smFormHeading').textContent = '➕ Create Schedule';
  document.getElementById('smCreateForm').reset();
  document.getElementById('smEditId').value = '0';
  document.getElementById('smEditStatus').value = 'Active';
  document.getElementById('smSaveBtn').textContent = '💾 Save Schedule';
}

function editScheduleItem(sc) {
  selectedScheduleId = Number(sc.id || 0);
  selectScheduleDetail(sc);
  document.getElementById('smFormHeading').textContent = '✏️ Edit Schedule: ' + (sc.schedule_name || '');
  document.getElementById('smEditId').value = sc.id || 0;
  document.getElementById('smEditStatus').value = sc.status || 'Active';
  document.getElementById('smSchedName').value = sc.schedule_name || '';
  document.getElementById('smWorkDays').value = sc.work_days_preset || 'Monday - Friday';
  document.getElementById('smTimeIn').value = sc.time_in_raw || '08:00';
  document.getElementById('smTimeOut').value = sc.time_out_raw || '17:00';
  document.getElementById('smBreakTime').value = sc.break_time || '12:00 PM - 01:00 PM';
  document.getElementById('smOtAfter').value = sc.overtime_after_minutes ?? 0;
  document.getElementById('smOtMult').value = sc.overtime_multiplier || '1.25';
  document.getElementById('smEffectiveDate').value = sc.effective_date || '';
  document.getElementById('smSaveBtn').textContent = '💾 Update Schedule';
  document.getElementById('smCreateForm')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function selectScheduleDetail(sc) {
  if (!sc) return;
  selectedScheduleId = Number(sc.id || 0);
  const nameEl = document.getElementById('detName');
  const stEl   = document.getElementById('detStatus');
  if (nameEl) nameEl.textContent = sc.schedule_name || 'Schedule';
  if (stEl) {
    const isAct = (sc.status || 'Active') === 'Active';
    stEl.textContent = sc.status || 'Active';
    stEl.className = isAct ? 'sm-pill-active' : 'sm-pill-inactive';
  }
  const setD = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v || '—'; };
  setD('detDays', sc.work_days_full);
  setD('detIn', sc.time_in_12h);
  setD('detOut', sc.time_out_12h);
  setD('detBreak', sc.break_time);
  setD('detOt', sc.overtime_label);
  setD('detEff', sc.effective_human);
}

function buildScheduleRowsHtml() {
  return schedulesCache.map((sc, idx) => {
    const isAct = sc.status === 'Active';
    const badge = `<span class="${isAct ? 'sm-pill-active' : 'sm-pill-inactive'}">${esc(sc.status)}</span>`;
    const jsonStr = JSON.stringify(sc).replace(/'/g, '&#39;');
    return `
      <tr data-status="${esc(sc.status)}" onclick='selectScheduleDetail(${jsonStr})'>
        <td>${idx + 1}</td>
        <td><strong>${esc(sc.schedule_name)}</strong></td>
        <td>${esc(sc.work_days_short)}</td>
        <td><strong>${esc(sc.time_range_12h)}</strong></td>
        <td>${esc(sc.break_time)}</td>
        <td><span style="color:#c4b5fd;font-weight:600;">${esc(sc.overtime_multiplier)}x</span></td>
        <td>${badge}</td>
        <td onclick="event.stopPropagation()">
          <div class="sm-act-btns">
            <button type="button" class="sm-icon-btn" title="Edit Schedule" onclick='editScheduleItem(${jsonStr})'>✏️</button>
            <form method="post" style="display:inline;">
              <input type="hidden" name="sm_action" value="duplicate_schedule">
              <input type="hidden" name="schedule_id" value="${Number(sc.id)}">
              <button type="submit" class="sm-icon-btn" title="Duplicate Schedule">📋</button>
            </form>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this schedule?');">
              <input type="hidden" name="sm_action" value="delete_schedule">
              <input type="hidden" name="schedule_id" value="${Number(sc.id)}">
              <button type="submit" class="sm-icon-btn del" title="Delete Schedule">🗑️</button>
            </form>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function applyDataTableFilters() {
  const q = (document.getElementById('smSearchInput')?.value || '').trim();
  const stFilter = document.getElementById('smStatusFilter')?.value || 'All';

  if (scheduleDataTable) {
    scheduleDataTable.search(q);
    if (stFilter === 'All') {
      scheduleDataTable.column(6).search('');
    } else {
      scheduleDataTable.column(6).search('^' + stFilter + '$', true, false);
    }
    scheduleDataTable.draw();
  }
}

function initOrRefreshDataTable() {
  const tableEl = document.getElementById('smSchedulesDataTable');
  const tbody   = document.getElementById('smScheduleTbody');
  if (!tableEl || !tbody) return;

  if (window.jQuery && $.fn && $.fn.DataTable) {
    if ($.fn.DataTable.isDataTable(tableEl)) {
      $(tableEl).DataTable().destroy();
    }
    tbody.innerHTML = buildScheduleRowsHtml();
    scheduleDataTable = $(tableEl).DataTable({
      pageLength: 5,
      lengthMenu: [5, 10, 25, 50],
      order: [[0, 'asc']],
      columnDefs: [
        { orderable: false, searchable: false, targets: 7 }
      ],
      language: {
        lengthMenu: 'Show _MENU_ schedules',
        info: 'Showing _START_ to _END_ of _TOTAL_ schedules',
        infoEmpty: 'No schedules to show',
        zeroRecords: 'No matching schedules found',
        paginate: { previous: '‹', next: '›' }
      }
    });
    applyDataTableFilters();
  } else {
    tbody.innerHTML = buildScheduleRowsHtml() || '<tr><td colspan="8" style="text-align:center;color:#a78bfa;padding:20px;">No schedules created yet.</td></tr>';
  }

  const curSel = schedulesCache.find(x => Number(x.id) === Number(selectedScheduleId)) || schedulesCache[0];
  if (curSel) selectScheduleDetail(curSel);
}

document.getElementById('smSearchInput')?.addEventListener('input', applyDataTableFilters);
document.getElementById('smStatusFilter')?.addEventListener('change', applyDataTableFilters);

window.addEventListener('load', function () {
  initOrRefreshDataTable();
});

(function () {
  async function pollLiveState() {
    try {
      const res = await fetch('?ajax=live_schedule_state&_t=' + Date.now(), { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      if (!data || !data.ok) return;

      const k = data.kpis || {};
      const setK = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = String(v ?? 0); };
      setK('kpiTotalEmp', k.total_employees);
      setK('kpiWithSched', k.with_schedule);
      setK('kpiNoSched', k.no_schedule);
      setK('kpiActiveSched', k.active_schedules);

      if (Array.isArray(data.schedules)) {
        schedulesCache = data.schedules;
        initOrRefreshDataTable();
      }
    } catch (_) {}
  }

  setInterval(pollLiveState, 5000);

  (async function attachWs() {
    const txt = document.getElementById('smLiveBadgeText');
    try {
      for (const u of ['/api/realtime_token.php', '../api/realtime_token.php']) {
        const r = await fetch(u, { cache: 'no-store' });
        if (!r.ok) continue;
        const j = await r.json();
        const token = j.token || (j.data && j.data.token) || '';
        if (!token) continue;
        const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const ws = new WebSocket(`${proto}//${window.location.hostname}:8090/ws?token=${encodeURIComponent(token)}`);
        ws.onopen = () => { if (txt) txt.textContent = 'LIVE • WS'; };
        ws.onmessage = () => pollLiveState();
        ws.onclose = () => { if (txt) txt.textContent = 'LIVE POLL'; };
        break;
      }
    } catch (_) {}
  })();
})();
</script>