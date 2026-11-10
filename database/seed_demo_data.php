<?php
declare(strict_types=1);

// ============================================================
// HRIS Demo Data Seeder
// Covers Aug 1 – Oct 31 2026
// Demonstrates: Binary Search brackets, Stack logs, Queue FIFO
// ============================================================

require_once __DIR__ . '/../config/database.php';

// $pdo = getConnection();
// $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Starting demo data seeder...\n\n";

// ── Helpers ───────────────────────────────────────────────────

function workingDates(string $start, string $end): array
{
    $dates   = [];
    $current = new DateTime($start);
    $endDt   = new DateTime($end);
    while ($current <= $endDt) {
        if ((int) $current->format('N') <= 5) {
            $dates[] = $current->format('Y-m-d');
        }
        $current->modify('+1 day');
    }
    return $dates;
}

function countWorkingDays(string $start, string $end): int
{
    return count(workingDates($start, $end));
}

/** Binary Search on sorted thresholds — same algorithm as LateDeductionPolicy */
function bracketIndex(int $lateMinutes): int
{
    $thresholds = [0, 30, 60];
    $lo = 0; $hi = count($thresholds) - 1; $result = 0;
    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        if ($thresholds[$mid] <= $lateMinutes) { $result = $mid; $lo = $mid + 1; }
        else $hi = $mid - 1;
    }
    return $result;
}

function deductDays(int $lateMinutes): float
{
    return [0.0, 0.5, 1.0][bracketIndex($lateMinutes)];
}

// ============================================================
// PART 1 — Employees EMP-004 and EMP-005
// ============================================================

$newEmps = [
    [
        'code'     => 'EMP-004', 'first'  => 'Maria',  'last' => 'Cruz',
        'email'    => 'maria@example.com', 'dept' => 1, 'pos' => 1,
        'hired'    => '2024-01-15', 'gender' => 'Female',
        'salary'   => 35000.00,    'allow'  => 3000.00,
        'username' => 'maria',     'pass'   => 'maria123',
    ],
    [
        'code'     => 'EMP-005', 'first'  => 'Jose',   'last' => 'Aquino',
        'email'    => 'jose@example.com',  'dept' => 1, 'pos' => 5,
        'hired'    => '2024-03-01', 'gender' => 'Male',
        'salary'   => 40000.00,    'allow'  => 3500.00,
        'username' => 'jose',      'pass'   => 'jose123',
    ],
];

foreach ($newEmps as $e) {
    $exists = $pdo->prepare("SELECT id FROM employees WHERE employee_id = ?");
    $exists->execute([$e['code']]);
    if ($exists->fetch()) {
        echo "Employee {$e['code']} already exists — skipping.\n";
        continue;
    }

    $pdo->prepare("
        INSERT INTO employees
            (employee_id, first_name, last_name, email, department_id, position_id,
             date_hired, gender, employment_type, employment_status)
        VALUES (?,?,?,?,?,?,?,?,'Full-time','Active')
    ")->execute([$e['code'],$e['first'],$e['last'],$e['email'],$e['dept'],$e['pos'],$e['hired'],$e['gender']]);

    $dbId = (int) $pdo->lastInsertId();
    echo "Inserted employee: {$e['code']} — id={$dbId}\n";

    // User
    $uExists = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $uExists->execute([$e['username']]);
    if (!$uExists->fetch()) {
        $pdo->prepare("
            INSERT INTO users (employee_id, username, password, role, status)
            VALUES (?,?,?,'employee','Active')
        ")->execute([$dbId, $e['username'], password_hash($e['pass'], PASSWORD_BCRYPT)]);
        echo "Created user: {$e['username']}\n";
    }

    // Salary
    $sExists = $pdo->prepare("SELECT id FROM employee_salaries WHERE employee_id = ?");
    $sExists->execute([$dbId]);
    if (!$sExists->fetch()) {
        $pdo->prepare("
            INSERT INTO employee_salaries
                (employee_id, pay_type, basic_salary, allowance, effective_date, status)
            VALUES (?,'Monthly',?,?,'2024-01-01','Active')
        ")->execute([$dbId, $e['salary'], $e['allow']]);
    }
}

// ── Fix existing users.employee_id ───────────────────────────
foreach (['brandon' => 'EMP-001', 'kaiser' => 'EMP-002', 'donald' => 'EMP-003'] as $uname => $code) {
    $row = $pdo->prepare("SELECT id FROM employees WHERE employee_id = ?");
    $row->execute([$code]);
    $emp = $row->fetch();
    if ($emp) {
        $pdo->prepare("UPDATE users SET employee_id = ? WHERE username = ?")
            ->execute([$emp['id'], $uname]);
    }
}
echo "Fixed employee_id for brandon, kaiser, donald.\n\n";

// ── Load all 5 employees with salary ─────────────────────────
$rows = $pdo->query("
    SELECT e.id, e.employee_id AS code, e.first_name, e.last_name, e.email,
           s.basic_salary, s.allowance
    FROM employees e
    JOIN employee_salaries s ON s.employee_id = e.id AND s.status = 'Active'
    WHERE e.employee_id IN ('EMP-001','EMP-002','EMP-003','EMP-004','EMP-005')
    ORDER BY e.employee_id
")->fetchAll(PDO::FETCH_ASSOC);

$emps = [];
foreach ($rows as $r) $emps[$r['code']] = $r;

// ============================================================
// PART 2 — Attendance Aug 1 – Oct 31 2026
// Demonstrates Binary Search on [0,30,60] thresholds
// ============================================================

// date => late_minutes (0 = on time)
$lateMap = [
    'EMP-001' => ['2026-08-05'=>15, '2026-08-12'=>45, '2026-09-03'=>75, '2026-10-21'=>30],
    'EMP-002' => ['2026-09-08'=>60, '2026-09-15'=>65, '2026-10-07'=>20, '2026-10-22'=>35],
    'EMP-003' => ['2026-08-19'=>45, '2026-10-02'=>90, '2026-10-09'=>90],
    'EMP-004' => ['2026-08-10'=>25, '2026-09-14'=>50, '2026-10-05'=>65],
    'EMP-005' => ['2026-08-17'=>55, '2026-09-07'=>70, '2026-10-27'=>15],
];

$allDates = workingDates('2026-08-01', '2026-10-31');

foreach ($emps as $code => $emp) {
    // Clear existing demo records
    $pdo->prepare("
        DELETE FROM attendance
        WHERE employee_id = ?
          AND time_in BETWEEN '2026-08-01 00:00:00' AND '2026-10-31 23:59:59'
    ")->execute([$emp['id']]);

    $patterns = $lateMap[$code] ?? [];

    foreach ($allDates as $date) {
        $lateMin = $patterns[$date] ?? 0;
        $timeIn  = $lateMin > 0
            ? (new DateTime("{$date} 08:00:00"))->modify("+{$lateMin} minutes")->format('Y-m-d H:i:s')
            : "{$date} 08:00:00";
        $timeOut = "{$date} 17:00:00";
        $wh      = round((strtotime($timeOut) - strtotime($timeIn)) / 3600 - 1, 2);

        $pdo->prepare("
            INSERT INTO attendance (employee_id, email, time_in, time_out, workhours, created_at)
            VALUES (?,?,?,?,?,?)
        ")->execute([$emp['id'], $emp['email'], $timeIn, $timeOut, $wh, "{$date} 08:00:00"]);
    }
}
echo "Part 2 done: Attendance records inserted (Aug–Oct 2026).\n\n";

// ============================================================
// PART 3 — Payroll Periods
// ============================================================

$staleNames = [
    'August 2026 Payroll',
    'September 2026 Payroll',
    'October 2026 Payroll',
];
foreach ($staleNames as $staleName) {
    $oldPid = $pdo->query("SELECT id FROM payroll_periods WHERE period_name = " . $pdo->quote($staleName))->fetchColumn();
    if ($oldPid) {
        $pdo->exec("DELETE FROM payslips WHERE payroll_period_id = {$oldPid}");
        $pdo->exec("DELETE FROM payroll_periods WHERE id = {$oldPid}");
        echo "Removed stale period: {$staleName}\n";
    }
}

$periodDefs = [
    ['August 2026 1st Cut-off',    '2026-08-01','2026-08-15','2026-08-20'],
    ['August 2026 2nd Cut-off',    '2026-08-16','2026-08-31','2026-09-05'],
    ['September 2026 1st Cut-off', '2026-09-01','2026-09-15','2026-09-20'],
    ['September 2026 2nd Cut-off', '2026-09-16','2026-09-30','2026-10-05'],
    ['October 2026 1st Cut-off',   '2026-10-01','2026-10-15','2026-10-20'],
    ['October 2026 2nd Cut-off',   '2026-10-16','2026-10-31','2026-11-05'],
];

$periodIds = [];
foreach ($periodDefs as [$name, $start, $end, $pay]) {
    $r = $pdo->prepare("SELECT id FROM payroll_periods WHERE period_name = ?");
    $r->execute([$name]);
    $row = $r->fetch();
    if ($row) {
        $pdo->prepare("UPDATE payroll_periods SET status='Processed' WHERE id=?")
            ->execute([$row['id']]);
        $periodIds[$name] = (int) $row['id'];
        echo "Updated period: {$name}\n";
    } else {
        $pdo->prepare("
            INSERT INTO payroll_periods (period_name, start_date, end_date, pay_date, status)
            VALUES (?,?,?,?,'Processed')
        ")->execute([$name, $start, $end, $pay]);
        $periodIds[$name] = (int) $pdo->lastInsertId();
        echo "Created period: {$name}\n";
    }
}
echo "\nPart 3 done: Payroll periods ready.\n\n";

// ============================================================
// PART 4 — Payslips (5 employees × 4 periods = 20 payslips)
// Binary Search brackets recorded in remarks column
// ============================================================

$sssRate  = 0.045;
$philRate = 0.025;
$pagFixed = 100.00;

foreach ($periodDefs as [$name, $start, $end]) {
    $pid      = $periodIds[$name];
    $wdCount  = countWorkingDays($start, $end);
    $wdInPeriod = workingDates($start, $end);

    foreach ($emps as $code => $emp) {
        $basic  = (float) $emp['basic_salary'];
        $allow  = (float) $emp['allowance'];

        // Semi-monthly split
        $halfSalary = round($basic / 2, 2);
        $halfAllow  = round($allow  / 2, 2);
        $dailyRate  = $wdCount > 0 ? ($halfSalary / $wdCount) : 0;

        // Tally late from this period's dates
        $patterns       = $lateMap[$code] ?? [];
        $totalLateMin   = 0;
        $totalDeductDays = 0.0;
        $remarks        = [];

        foreach ($wdInPeriod as $date) {
            if (!isset($patterns[$date])) continue;
            $lm   = $patterns[$date];
            $bi   = bracketIndex($lm);
            $dd   = deductDays($lm);
            $totalLateMin    += $lm;
            $totalDeductDays += $dd;
            $remarks[] = "Binary Search bracket {$bi}: {$lm}min late → {$dd} day(s) deducted";
        }

        $lateDeduct  = round($dailyRate * $totalDeductDays, 2);
        $basicPay    = round($halfSalary - $lateDeduct, 2);
        $grossPay    = round($basicPay + $halfAllow, 2);

        $sss         = round(($basic * $sssRate) / 2, 2);
        $phil        = round(($basic * $philRate) / 2, 2);
        $pag         = round($pagFixed / 2, 2);
        $totalDed    = round($sss + $phil + $pag, 2);
        $netPay      = round($grossPay - $totalDed, 2);

        $remarkStr = !empty($remarks)
            ? implode('; ', $remarks)
            : 'No late deductions this period';

        // Upsert
        $pdo->prepare("
            DELETE FROM payslips WHERE employee_id = ? AND payroll_period_id = ?
        ")->execute([$emp['id'], $pid]);

        $pdo->prepare("
            INSERT INTO payslips (
                payroll_period_id, employee_id, pay_type,
                basic_salary, working_days, days_worked, absent_days,
                late_minutes, ot_hours, ot_rate_multiplier,
                basic_pay, allowance, overtime_pay, other_earnings,
                gross_pay, sss, philhealth, pagibig,
                withholding_tax, absences_deduction, late_deduction,
                other_deductions, total_deductions, net_pay,
                remarks, status
            ) VALUES (
                ?,?,'Monthly',
                ?,?,?,0,
                ?,0,1.25,
                ?,?,0,0,
                ?,?,?,?,
                0,0,?,
                0,?,?,
                ?,'Paid'
            )
        ")->execute([
            $pid, $emp['id'],
            $basic, $wdCount, round($wdCount - $totalDeductDays, 2),
            $totalLateMin,
            $basicPay, $halfAllow,
            $grossPay, $sss, $phil, $pag,
            $lateDeduct,
            $totalDed, $netPay,
            $remarkStr,
        ]);
    }
}
echo "Part 4 done: 20 payslips generated.\n\n";

// ============================================================
// PART 5 — Leave Types
// ============================================================

$ltDefs = [
    ['Sick Leave',     'For medical/health reasons',      15],
    ['Annual Leave',   'Vacation / personal time off',    15],
    ['Personal Leave', 'Personal matters',                 5],
];
$ltIds = [];
foreach ($ltDefs as [$lname, $desc, $days]) {
    $r = $pdo->prepare("SELECT id FROM leave_types WHERE leave_name = ?");
    $r->execute([$lname]);
    $row = $r->fetch();
    if ($row) {
        $ltIds[$lname] = (int) $row['id'];
    } else {
        $pdo->prepare("
            INSERT INTO leave_types (leave_name, description, default_days, status)
            VALUES (?,?,?,'Active')
        ")->execute([$lname, $desc, $days]);
        $ltIds[$lname] = (int) $pdo->lastInsertId();
    }
}
echo "Part 5 done: Leave types ready.\n\n";

// ============================================================
// PART 6 — Leave Balances
// ============================================================

foreach ($emps as $emp) {
    foreach ($ltDefs as [$lname,, $days]) {
        $r = $pdo->prepare("
            SELECT id FROM leave_balances
            WHERE employee_id=? AND leave_type_id=? AND year=2026
        ");
        $r->execute([$emp['id'], $ltIds[$lname]]);
        if (!$r->fetch()) {
            $pdo->prepare("
                INSERT INTO leave_balances
                    (employee_id, leave_type_id, year, allocated_days, used_days, remaining_days)
                VALUES (?,?,2026,?,0,?)
            ")->execute([$emp['id'], $ltIds[$lname], $days, $days]);
        }
    }
}
echo "Part 6 done: Leave balances set for all 5 employees.\n\n";

// ============================================================
// PART 7 — Leave Requests
// ============================================================

// Get HR user for approver fields
$hrRow = $pdo->query("
    SELECT u.id, e.email, CONCAT(e.first_name,' ',e.last_name) AS fullname
    FROM users u
    LEFT JOIN employees e ON e.id = u.employee_id
    WHERE u.username = 'hr' LIMIT 1
")->fetch();
$hrId    = $hrRow ? (int) $hrRow['id']       : null;
$hrName  = $hrRow ? $hrRow['fullname']        : 'HR Officer';
$hrEmail = $hrRow ? ($hrRow['email'] ?? 'hr@example.com') : 'hr@example.com';

// Clean existing demo leave data safely
$existing = $pdo->query("
    SELECT id FROM leave_requests
    WHERE start_date BETWEEN '2026-08-01' AND '2026-10-31'
")->fetchAll(PDO::FETCH_COLUMN);

if (!empty($existing)) {
    $in = implode(',', array_map('intval', $existing));
    $pdo->exec("DELETE FROM leave_request_logs WHERE leave_request_id IN ({$in})");
    $pdo->exec("DELETE FROM leave_requests WHERE id IN ({$in})");
}

$e1 = $emps['EMP-001'];
$e2 = $emps['EMP-002'];
$e3 = $emps['EMP-003'];
$e4 = $emps['EMP-004'];
$e5 = $emps['EMP-005'];

$lrDefs = [
    // 2 Approved
    [$e1['id'], $ltIds['Sick Leave'],     '2026-09-10','2026-09-11', 2, 'Fever and flu',                    'Approved', $hrId, '2026-09-09 10:00:00','2026-09-08 09:00:00'],
    [$e2['id'], $ltIds['Annual Leave'],   '2026-09-21','2026-09-25', 5, 'Family vacation',                  'Approved', $hrId, '2026-09-18 11:00:00','2026-09-15 08:30:00'],
    // 1 Rejected
    [$e3['id'], $ltIds['Personal Leave'], '2026-10-05','2026-10-05', 1, 'Personal errand',                  'Rejected', $hrId, '2026-10-03 14:00:00','2026-10-01 10:00:00'],
    // 1 Re-filed Approved
    [$e3['id'], $ltIds['Personal Leave'], '2026-10-12','2026-10-12', 1, 'Personal errand rescheduled',      'Approved', $hrId, '2026-10-10 09:00:00','2026-10-06 08:00:00'],
    // 3 Pending FIFO: Maria → Jose → Brandon
    [$e4['id'], $ltIds['Annual Leave'],   '2026-10-20','2026-10-20', 1, 'Personal day',                     'Pending',  null,  null,                 '2026-10-14 08:00:00'],
    [$e5['id'], $ltIds['Sick Leave'],     '2026-10-21','2026-10-21', 1, 'Medical check-up',                 'Pending',  null,  null,                 '2026-10-15 09:00:00'],
    [$e1['id'], $ltIds['Annual Leave'],   '2026-10-22','2026-10-22', 1, 'Rest day',                         'Pending',  null,  null,                 '2026-10-16 10:00:00'],
];

$lrIds = [];
$lrStmt = $pdo->prepare("
    INSERT INTO leave_requests
        (employee_id, leave_type_id, start_date, end_date, number_of_days,
         reason, status, approved_by, approved_at, created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?)
");
foreach ($lrDefs as $lr) {
    $lrStmt->execute($lr);
    $lrIds[] = (int) $pdo->lastInsertId();
}
echo "Part 7 done: " . count($lrIds) . " leave requests inserted.\n\n";

// ============================================================
// PART 8 — Leave Request Logs (Stack demo)
// Each push = one row; latest row = top of stack
// leave_request_logs columns used:
//   leave_request_id, employee_id, action, old_status, new_status,
//   performed_by_id, performed_by_name, performed_by_email,
//   performed_by_role, remarks, created_at
// ============================================================

$logStmt = $pdo->prepare("
    INSERT INTO leave_request_logs
        (leave_request_id, employee_id, action, old_status, new_status,
         performed_by_id, performed_by_name, performed_by_email,
         performed_by_role, remarks, created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)
");

$e1n = "{$e1['first_name']} {$e1['last_name']}";
$e2n = "{$e2['first_name']} {$e2['last_name']}";
$e3n = "{$e3['first_name']} {$e3['last_name']}";
$e4n = "{$e4['first_name']} {$e4['last_name']}";
$e5n = "{$e5['first_name']} {$e5['last_name']}";

// ── Request 0: Brandon Sep Sick — Approved (Stack: Approved on top)
$logStmt->execute([$lrIds[0],$e1['id'],'submitted',null,'Pending',    $e1['id'],$e1n,  $e1['email'],'employee','Leave request submitted',           '2026-09-08 09:00:00']);
$logStmt->execute([$lrIds[0],$e1['id'],'approved', 'Pending','Approved',$hrId,  $hrName,$hrEmail,   'hr',      'Approved — medical rest confirmed',  '2026-09-09 10:00:00']);

// ── Request 1: Kaiser Sep Annual — Approved
$logStmt->execute([$lrIds[1],$e2['id'],'submitted',null,'Pending',    $e2['id'],$e2n,  $e2['email'],'employee','Leave request submitted',           '2026-09-15 08:30:00']);
$logStmt->execute([$lrIds[1],$e2['id'],'approved', 'Pending','Approved',$hrId,  $hrName,$hrEmail,   'hr',      'Approved — vacation confirmed',      '2026-09-18 11:00:00']);

// ── Request 2: Donald Oct Personal — Rejected (Stack: 3 pushes, Rejected on top)
$logStmt->execute([$lrIds[2],$e3['id'],'submitted',null,'Pending',    $e3['id'],$e3n,  $e3['email'],'employee','Leave request submitted',           '2026-10-01 10:00:00']);
$logStmt->execute([$lrIds[2],$e3['id'],'reviewed', 'Pending','Pending', $hrId,  $hrName,$hrEmail,   'hr',      'Under review — checking schedule',   '2026-10-02 09:00:00']);
$logStmt->execute([$lrIds[2],$e3['id'],'rejected', 'Pending','Rejected',$hrId,  $hrName,$hrEmail,   'hr',      'Rejected — critical period',         '2026-10-03 14:00:00']);

// ── Request 3: Donald Oct Personal re-filed — Approved
$logStmt->execute([$lrIds[3],$e3['id'],'submitted',null,'Pending',    $e3['id'],$e3n,  $e3['email'],'employee','Re-filed for different date',        '2026-10-06 08:00:00']);
$logStmt->execute([$lrIds[3],$e3['id'],'approved', 'Pending','Approved',$hrId,  $hrName,$hrEmail,   'hr',      'Approved — rescheduled date clear',  '2026-10-10 09:00:00']);

// ── Requests 4,5,6: Pending — 1 push each (Queue FIFO: Maria→Jose→Brandon)
$logStmt->execute([$lrIds[4],$e4['id'],'submitted',null,'Pending',    $e4['id'],$e4n,  $e4['email'],'employee','Leave request submitted',           '2026-10-14 08:00:00']);
$logStmt->execute([$lrIds[5],$e5['id'],'submitted',null,'Pending',    $e5['id'],$e5n,  $e5['email'],'employee','Leave request submitted',           '2026-10-15 09:00:00']);
$logStmt->execute([$lrIds[6],$e1['id'],'submitted',null,'Pending',    $e1['id'],$e1n,  $e1['email'],'employee','Leave request submitted',           '2026-10-16 10:00:00']);

echo "Part 8 done: " . (2+2+3+2+1+1+1) . " leave request log entries inserted.\n\n";
echo "==============================\n";
echo "SEEDING COMPLETE!\n";
echo "==============================\n";
echo "DSA demo data ready:\n";
echo "  Binary Search → payslips.remarks column\n";
echo "  Stack         → hr/leave_management.php history dropdowns\n";
echo "  Queue FIFO    → hr/leave_management.php pending panel: Maria→Jose→Brandon\n";