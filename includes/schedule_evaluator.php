<?php
// includes/schedule_evaluator.php

date_default_timezone_set('Asia/Manila');

function sched_has_table(PDO $pdo, string $table): bool {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
        $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function sched_has_col(PDO $pdo, string $table, string $col): bool {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $col)) return false;
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($col));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function sched_ensure_columns(PDO $pdo, string $table, array $definitions): void {
    if (!sched_has_table($pdo, $table)) return;
    foreach ($definitions as $col => $ddl) {
        if (!sched_has_col($pdo, $table, $col)) {
            try {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$ddl}");
            } catch (Throwable $e) {}
        }
    }
}

function sched_ensure_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `work_shifts` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `shift_name` VARCHAR(100) NOT NULL,
            `shift_code` VARCHAR(40) NOT NULL,
            `work_days_label` VARCHAR(80) NOT NULL DEFAULT 'Monday - Friday',
            `working_days` VARCHAR(100) NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri',
            `rest_days` VARCHAR(100) NOT NULL DEFAULT 'Sat,Sun',
            `start_time` TIME NOT NULL DEFAULT '08:00:00',
            `end_time` TIME NOT NULL DEFAULT '17:00:00',
            `break_time_label` VARCHAR(60) NOT NULL DEFAULT '12:00 PM - 01:00 PM',
            `break_minutes` INT NOT NULL DEFAULT 60,
            `grace_minutes` INT NOT NULL DEFAULT 15,
            `overtime_after_minutes` INT NOT NULL DEFAULT 0,
            `overtime_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 1.25,
            `effective_date` DATE NULL,
            `status` VARCHAR(24) NOT NULL DEFAULT 'Active',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    sched_ensure_columns($pdo, 'work_shifts', [
        'work_days_label'        => "VARCHAR(80) NOT NULL DEFAULT 'Monday - Friday' AFTER `shift_code`",
        'working_days'           => "VARCHAR(100) NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri' AFTER `work_days_label`",
        'rest_days'              => "VARCHAR(100) NOT NULL DEFAULT 'Sat,Sun' AFTER `working_days`",
        'break_time_label'       => "VARCHAR(60) NOT NULL DEFAULT '12:00 PM - 01:00 PM' AFTER `end_time`",
        'overtime_after_minutes' => 'INT NOT NULL DEFAULT 0 AFTER `grace_minutes`',
        'overtime_multiplier'    => 'DECIMAL(4,2) NOT NULL DEFAULT 1.25 AFTER `overtime_after_minutes`',
        'effective_date'         => 'DATE NULL AFTER `overtime_multiplier`',
        'status'                 => "VARCHAR(24) NOT NULL DEFAULT 'Active' AFTER `effective_date`",
    ]);

    try {
        $pdo->exec("DELETE FROM `work_shifts` WHERE LOWER(`shift_name`) = 'night shift' AND `shift_code` = 'SCH-NGT'");
    } catch (Throwable $e) {}

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `employee_work_schedules` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT UNSIGNED NOT NULL UNIQUE,
            `department_name` VARCHAR(100) NULL,
            `shift_id` INT UNSIGNED NULL,
            `shift_name` VARCHAR(100) NOT NULL DEFAULT 'Regular Day Shift',
            `work_days_label` VARCHAR(80) NOT NULL DEFAULT 'Monday - Friday',
            `working_days` VARCHAR(100) NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri',
            `rest_days` VARCHAR(100) NOT NULL DEFAULT 'Sat,Sun',
            `shift_start` TIME NOT NULL DEFAULT '08:00:00',
            `shift_end` TIME NOT NULL DEFAULT '17:00:00',
            `break_time_label` VARCHAR(60) NOT NULL DEFAULT '12:00 PM - 01:00 PM',
            `grace_minutes` INT NOT NULL DEFAULT 15,
            `overtime_after_minutes` INT NOT NULL DEFAULT 0,
            `overtime_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 1.25,
            `effective_from` DATE NULL,
            `assigned_by` VARCHAR(120) NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    sched_ensure_columns($pdo, 'employee_work_schedules', [
        'department_name'        => 'VARCHAR(100) NULL AFTER `employee_id`',
        'shift_id'               => 'INT UNSIGNED NULL AFTER `department_name`',
        'work_days_label'        => "VARCHAR(80) NOT NULL DEFAULT 'Monday - Friday' AFTER `shift_name`",
        'working_days'           => "VARCHAR(100) NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri' AFTER `work_days_label`",
        'rest_days'              => "VARCHAR(100) NOT NULL DEFAULT 'Sat,Sun' AFTER `working_days`",
        'break_time_label'       => "VARCHAR(60) NOT NULL DEFAULT '12:00 PM - 01:00 PM' AFTER `shift_end`",
        'overtime_after_minutes' => 'INT NOT NULL DEFAULT 0 AFTER `grace_minutes`',
        'overtime_multiplier'    => 'DECIMAL(4,2) NOT NULL DEFAULT 1.25 AFTER `overtime_after_minutes`',
        'effective_from'         => 'DATE NULL AFTER `overtime_multiplier`',
        'assigned_by'            => 'VARCHAR(120) NULL AFTER `effective_from`',
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `employee_schedule_history` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT UNSIGNED NOT NULL,
            `department_name` VARCHAR(100) NULL,
            `shift_id` INT UNSIGNED NULL,
            `shift_name` VARCHAR(100) NOT NULL,
            `shift_start` TIME NOT NULL,
            `shift_end` TIME NOT NULL,
            `working_days` VARCHAR(100) NOT NULL,
            `rest_days` VARCHAR(100) NOT NULL,
            `overtime_after_minutes` INT NOT NULL DEFAULT 0,
            `overtime_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 1.25,
            `effective_from` DATE NOT NULL,
            `action_type` VARCHAR(40) NOT NULL DEFAULT 'Assigned',
            `changed_by` VARCHAR(120) NULL,
            `notes` VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    sched_ensure_columns($pdo, 'employee_schedule_history', [
        'department_name'        => 'VARCHAR(100) NULL AFTER `employee_id`',
        'shift_id'               => 'INT UNSIGNED NULL AFTER `department_name`',
        'overtime_after_minutes' => 'INT NOT NULL DEFAULT 0 AFTER `rest_days`',
        'overtime_multiplier'    => 'DECIMAL(4,2) NOT NULL DEFAULT 1.25 AFTER `overtime_after_minutes`',
        'action_type'            => "VARCHAR(40) NOT NULL DEFAULT 'Assigned' AFTER `effective_from`",
        'changed_by'             => 'VARCHAR(120) NULL AFTER `action_type`',
        'notes'                  => 'VARCHAR(255) NULL AFTER `changed_by`',
    ]);

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

    sched_ensure_columns($pdo, 'attendance_records', [
        'break_start'      => 'TIME NULL AFTER `time_out`',
        'break_end'        => 'TIME NULL AFTER `break_start`',
        'scheduled_start'  => "TIME NOT NULL DEFAULT '08:00:00' AFTER `break_end`",
        'scheduled_end'    => "TIME NOT NULL DEFAULT '17:00:00' AFTER `scheduled_start`",
        'late_minutes'     => 'INT NOT NULL DEFAULT 0 AFTER `scheduled_end`',
        'overtime_minutes' => 'INT NOT NULL DEFAULT 0 AFTER `late_minutes`',
        'status'           => "VARCHAR(32) NOT NULL DEFAULT 'Present' AFTER `overtime_minutes`",
        'notes'            => 'VARCHAR(255) NULL AFTER `status`',
    ]);

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `work_shifts`")->fetchColumn();
    if ($cnt === 0) {
        $ins = $pdo->prepare("
            INSERT INTO `work_shifts`
                (`shift_name`, `shift_code`, `work_days_label`, `working_days`, `rest_days`, `start_time`, `end_time`, `break_time_label`, `break_minutes`, `grace_minutes`, `overtime_after_minutes`, `overtime_multiplier`, `effective_date`, `status`, `is_active`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute(['Regular Day Shift', 'SCH-DAY', 'Monday - Friday', 'Mon,Tue,Wed,Thu,Fri', 'Sat,Sun', '08:00:00', '17:00:00', '12:00 PM - 01:00 PM', 60, 15, 0, 1.25, date('Y-m-d'), 'Active', 1]);
        $ins->execute(['Morning Shift', 'SCH-AM', 'Monday - Friday', 'Mon,Tue,Wed,Thu,Fri', 'Sat,Sun', '07:00:00', '16:00:00', '11:30 AM - 12:30 PM', 60, 15, 0, 1.25, date('Y-m-d'), 'Active', 1]);
        $ins->execute(['Mid Shift', 'SCH-MID', 'Monday - Friday', 'Mon,Tue,Wed,Thu,Fri', 'Sat,Sun', '13:00:00', '22:00:00', '05:00 PM - 06:00 PM', 60, 15, 0, 1.25, date('Y-m-d'), 'Active', 1]);
        $ins->execute(['Overtime Extended Shift', 'SCH-OT', 'Monday - Friday', 'Mon,Tue,Wed,Thu,Fri', 'Sat,Sun', '08:00:00', '17:00:00', '12:00 PM - 01:00 PM', 60, 10, 0, 1.50, date('Y-m-d'), 'Active', 1]);
    }
}

function sched_resolve_employee_department(PDO $pdo, int $employeeId): string {
    if (!sched_has_table($pdo, 'employees')) return 'General';

    $hasDeptTbl     = sched_has_table($pdo, 'departments');
    $hasEmpDeptId   = sched_has_col($pdo, 'employees', 'department_id');
    $empDeptTextCol = sched_has_col($pdo, 'employees', 'department')
        ? 'department'
        : (sched_has_col($pdo, 'employees', 'department_name') ? 'department_name' : '');

    $hasPosTbl    = sched_has_table($pdo, 'positions');
    $hasPosId     = sched_has_col($pdo, 'employees', 'position_id');
    $hasPosDeptId = $hasPosTbl && sched_has_col($pdo, 'positions', 'department_id');

    $joins = [];
    $parts = [];

    if ($hasDeptTbl && $hasEmpDeptId) {
        $joins[] = "LEFT JOIN `departments` d_emp ON d_emp.`id` = e.`department_id`";
        $parts[] = "NULLIF(TRIM(d_emp.`name`), '')";
    }
    if ($hasPosTbl && $hasPosId && $hasPosDeptId && $hasDeptTbl) {
        $joins[] = "LEFT JOIN `positions` p_emp ON p_emp.`id` = e.`position_id`";
        $joins[] = "LEFT JOIN `departments` d_pos ON d_pos.`id` = p_emp.`department_id`";
        $parts[] = "NULLIF(TRIM(d_pos.`name`), '')";
    }
    if ($empDeptTextCol !== '') {
        $parts[] = "NULLIF(TRIM(e.`{$empDeptTextCol}`), '')";
    }

    $expr = !empty($parts) ? 'COALESCE(' . implode(', ', $parts) . ", 'General')" : "'General'";
    $joinSql = implode(' ', $joins);

    try {
        $stmt = $pdo->prepare("SELECT {$expr} FROM `employees` e {$joinSql} WHERE e.`id` = ? LIMIT 1");
        $stmt->execute([$employeeId]);
        return (string)($stmt->fetchColumn() ?: 'General');
    } catch (Throwable $e) {
        return 'General';
    }
}

function sched_get_for_employee_date(PDO $pdo, int $employeeId, string $workDate): array {
    $stmt = $pdo->prepare("SELECT * FROM `employee_work_schedules` WHERE `employee_id` = ? LIMIT 1");
    $stmt->execute([$employeeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $dept = sched_resolve_employee_department($pdo, $employeeId);

    if (!$row && $dept !== '' && $dept !== 'General' && sched_has_col($pdo, 'employee_work_schedules', 'department_name')) {
        $ds = $pdo->prepare("
            SELECT * FROM `employee_work_schedules`
            WHERE LOWER(TRIM(COALESCE(`department_name`, ''))) = LOWER(?)
            ORDER BY `id` DESC LIMIT 1
        ");
        $ds->execute([$dept]);
        $row = $ds->fetch(PDO::FETCH_ASSOC);
    }

    $shiftName  = $row['shift_name'] ?? 'Regular Day Shift';
    $shiftStart = $row['shift_start'] ?? '08:00:00';
    $shiftEnd   = $row['shift_end'] ?? '17:00:00';
    $graceMin   = (int)($row['grace_minutes'] ?? 15);
    $otAfterMin = (int)($row['overtime_after_minutes'] ?? 0);
    $otMult     = (float)($row['overtime_multiplier'] ?? 1.25);
    $workDays   = array_filter(array_map('trim', explode(',', (string)($row['working_days'] ?? 'Mon,Tue,Wed,Thu,Fri'))));
    $restDays   = array_filter(array_map('trim', explode(',', (string)($row['rest_days'] ?? 'Sat,Sun'))));

    $dow = date('D', strtotime($workDate));
    $isRestDay = in_array($dow, $restDays, true) || (!empty($workDays) && !in_array($dow, $workDays, true));

    return [
        'has_schedule'           => (bool)$row,
        'department_name'        => $dept,
        'shift_name'             => $shiftName,
        'shift_start'            => $shiftStart,
        'shift_end'              => $shiftEnd,
        'grace_minutes'          => $graceMin,
        'overtime_after_minutes' => $otAfterMin,
        'overtime_multiplier'    => $otMult,
        'working_days'           => $workDays,
        'rest_days'              => $restDays,
        'is_rest_day'            => $isRestDay,
    ];
}

function sched_evaluate_punch(PDO $pdo, int $employeeId, string $workDate, ?string $timeIn, ?string $timeOut, string $currentStatus = 'Present'): array {
    $sched = sched_get_for_employee_date($pdo, $employeeId, $workDate);

    if ($currentStatus === 'Absent') {
        return [
            'scheduled_start'  => $sched['shift_start'],
            'scheduled_end'    => $sched['shift_end'],
            'late_minutes'     => 0,
            'overtime_minutes' => 0,
            'status'           => 'Absent',
            'is_rest_day'      => $sched['is_rest_day'],
        ];
    }

    $startTs = strtotime($workDate . ' ' . $sched['shift_start']);
    $endTs   = strtotime($workDate . ' ' . $sched['shift_end']);
    if ($endTs <= $startTs) {
        $endTs = strtotime('+1 day', $endTs);
    }

    $lateMin = 0;
    $status = $currentStatus;

    if (!empty($timeIn) && !$sched['is_rest_day']) {
        $inTs = strtotime($workDate . ' ' . $timeIn);
        $allowedTs = $startTs + ($sched['grace_minutes'] * 60);
        if ($inTs > $allowedTs) {
            $lateMin = (int)floor(($inTs - $startTs) / 60);
            if ($status !== 'On Break') {
                $status = 'Late';
            }
        } elseif ($status !== 'On Break') {
            $status = 'Present';
        }
    } elseif (!empty($timeIn) && $sched['is_rest_day']) {
        $status = 'Rest Day OT';
    }

    $otMin = 0;
    if (!empty($timeOut)) {
        $outTs = strtotime($workDate . ' ' . $timeOut);
        if (!empty($timeIn) && $outTs < strtotime($workDate . ' ' . $timeIn)) {
            $outTs = strtotime('+1 day', $outTs);
        }
        $otThresholdTs = $endTs + ($sched['overtime_after_minutes'] * 60);
        if ($sched['is_rest_day'] && !empty($timeIn)) {
            $otMin = max(0, (int)floor(($outTs - strtotime($workDate . ' ' . $timeIn)) / 60));
        } elseif ($outTs > $otThresholdTs) {
            $otMin = (int)floor(($outTs - $endTs) / 60);
        }
    }

    return [
        'scheduled_start'  => $sched['shift_start'],
        'scheduled_end'    => $sched['shift_end'],
        'late_minutes'     => $lateMin,
        'overtime_minutes' => $otMin,
        'status'           => $status,
        'is_rest_day'      => $sched['is_rest_day'],
    ];
}

function sched_sync_realtime_attendance(PDO $pdo, string $targetDate): void {
    if (!sched_has_table($pdo, 'employees')) return;

    $today = date('Y-m-d');
    if ($targetDate > $today) return;

    $activeCol = sched_has_col($pdo, 'employees', 'status')
        ? "WHERE LOWER(COALESCE(`status`, 'active')) IN ('active', 'probationary', 'regular', '')"
        : "";
    $empIds = $pdo->query("SELECT `id` FROM `employees` {$activeCol}")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($empIds)) return;

    $onLeaveIds = [];
    if (sched_has_table($pdo, 'leave_requests')) {
        try {
            $lStmt = $pdo->prepare("
                SELECT DISTINCT `employee_id` FROM `leave_requests`
                WHERE DATE(`start_date`) <= ? AND DATE(`end_date`) >= ?
                  AND LOWER(COALESCE(`status`, 'approved')) IN ('approved', 'active')
            ");
            $lStmt->execute([$targetDate, $targetDate]);
            $onLeaveIds = array_map('intval', $lStmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {}
    }

    $nowTs = time();

    foreach ($empIds as $rawId) {
        $eid = (int)$rawId;
        if ($eid <= 0 || in_array($eid, $onLeaveIds, true)) continue;

        $sched = sched_get_for_employee_date($pdo, $eid, $targetDate);
        if (!$sched['has_schedule'] || $sched['is_rest_day']) continue;

        $shiftStartTs = strtotime($targetDate . ' ' . $sched['shift_start']);
        $shiftEndTs   = strtotime($targetDate . ' ' . $sched['shift_end']);
        if ($shiftEndTs <= $shiftStartTs) {
            $shiftEndTs = strtotime('+1 day', $shiftEndTs);
        }
        $graceDeadlineTs = $shiftStartTs + ($sched['grace_minutes'] * 60);

        $chk = $pdo->prepare("SELECT * FROM `attendance_records` WHERE `employee_id` = ? AND `work_date` = ? LIMIT 1");
        $chk->execute([$eid, $targetDate]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        if ($existing && !empty($existing['time_in'])) {
            $ev = sched_evaluate_punch(
                $pdo,
                $eid,
                $targetDate,
                (string)$existing['time_in'],
                $existing['time_out'] ?: null,
                (string)($existing['status'] ?? 'Present')
            );
            $pdo->prepare("
                UPDATE `attendance_records`
                SET `scheduled_start` = ?, `scheduled_end` = ?,
                    `late_minutes` = ?, `overtime_minutes` = ?, `status` = ?
                WHERE `id` = ?
            ")->execute([
                $ev['scheduled_start'], $ev['scheduled_end'],
                $ev['late_minutes'], $ev['overtime_minutes'], $ev['status'],
                (int)$existing['id']
            ]);
            continue;
        }

        if ($targetDate < $today || ($targetDate === $today && $nowTs >= $shiftEndTs)) {
            if (!$existing) {
                $pdo->prepare("
                    INSERT IGNORE INTO `attendance_records`
                        (`employee_id`, `work_date`, `scheduled_start`, `scheduled_end`, `late_minutes`, `overtime_minutes`, `status`, `notes`)
                    VALUES (?, ?, ?, ?, 0, 0, 'Absent', ?)
                ")->execute([
                    $eid, $targetDate, $sched['shift_start'], $sched['shift_end'],
                    'Auto-recorded Absent (missed schedule ' . substr($sched['shift_start'], 0, 5) . '-' . substr($sched['shift_end'], 0, 5) . ')'
                ]);
            } elseif (($existing['status'] ?? '') !== 'Present' && ($existing['status'] ?? '') !== 'Late') {
                $pdo->prepare("
                    UPDATE `attendance_records`
                    SET `status` = 'Absent', `scheduled_start` = ?, `scheduled_end` = ?
                    WHERE `id` = ?
                ")->execute([$sched['shift_start'], $sched['shift_end'], (int)$existing['id']]);
            }
            continue;
        }

        if ($targetDate === $today && $nowTs > $graceDeadlineTs && $nowTs < $shiftEndTs) {
            $liveLateMin = max(1, (int)floor(($nowTs - $shiftStartTs) / 60));
            if (!$existing) {
                $pdo->prepare("
                    INSERT IGNORE INTO `attendance_records`
                        (`employee_id`, `work_date`, `scheduled_start`, `scheduled_end`, `late_minutes`, `overtime_minutes`, `status`, `notes`)
                    VALUES (?, ?, ?, ?, ?, 0, 'Late', 'Unchecked-in past grace time')
                ")->execute([$eid, $targetDate, $sched['shift_start'], $sched['shift_end'], $liveLateMin]);
            } elseif (($existing['status'] ?? '') !== 'Present') {
                $pdo->prepare("
                    UPDATE `attendance_records`
                    SET `status` = 'Late', `late_minutes` = ?, `scheduled_start` = ?, `scheduled_end` = ?
                    WHERE `id` = ?
                ")->execute([$liveLateMin, $sched['shift_start'], $sched['shift_end'], (int)$existing['id']]);
            }
        }
    }
}

function sched_auto_record_absences(PDO $pdo, string $targetDate): void {
    sched_sync_realtime_attendance($pdo, $targetDate);
}