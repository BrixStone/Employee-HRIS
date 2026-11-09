<?php
/**
 * HRIS SAMPLE DATA SEEDER
 * Database: hris
 * Attendance: September - October 2026
 * Payroll: September - October 2026
 *
 * Run:
 * C:\xampp\php\php.exe database\seed_sample_data.php
 */

// Command line only. This script resets the sample accounts' passwords (hr,
// admin, ...), so it must never be runnable from a web request.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Asia/Manila');

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'hris';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

$pdo->exec("SET time_zone = '+08:00'");

try {

    $pdo->beginTransaction();

    // =========================================
    // PART 1: EMPLOYEE INFORMATION
    // =========================================

    $employees = [
        [
            'employee_id' => 'EMP-001',
            'first_name' => 'Brandon',
            'middle_name' => 'Romero',
            'last_name' => 'Babatuan',
            'dob' => '1998-04-15',
            'gender' => 'Male',
            'phone' => '09171234501',
            'email' => 'brandon@example.com',
            'address' => 'Pulpogan 1, Consolacion, Cebu',
            'department' => 2,
            'position' => 4,
            'hired' => '2023-06-01',
            'salary' => 28000,
            'allowance' => 2000,
            'username' => 'brandon',
            'password' => 'brandon123'
        ],
        [
            'employee_id' => 'EMP-002',
            'first_name' => 'Kaiser',
            'middle_name' => 'James',
            'last_name' => 'Mendoza',
            'dob' => '1997-08-20',
            'gender' => 'Male',
            'phone' => '09171234502',
            'email' => 'kaiser@example.com',
            'address' => 'Lapu-Lapu City, Cebu',
            'department' => 2,
            'position' => 2,
            'hired' => '2024-03-15',
            'salary' => 30000,
            'allowance' => 2000,
            'username' => 'kaiser',
            'password' => 'kaiser123'
        ],
        [
            'employee_id' => 'EMP-003',
            'first_name' => 'Donald',
            'middle_name' => 'Ramos',
            'last_name' => 'Reyes',
            'dob' => '1995-11-10',
            'gender' => 'Male',
            'phone' => '09171234503',
            'email' => 'donald@example.com',
            'address' => 'Mandaue City, Cebu',
            'department' => 3,
            'position' => 3,
            'hired' => '2022-09-01',
            'salary' => 32000,
            'allowance' => 2500,
            'username' => 'donald',
            'password' => 'donald123'
        ]
    ];

    $employeeIds = [];

    // =========================================
    // INSERT OR UPDATE EMPLOYEES
    // =========================================

    foreach ($employees as $e) {

        // Match Brandon and existing Kai by username first.
        $stmt = $pdo->prepare("
            SELECT employee_id
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        $stmt->execute([
            $e['username'] === 'kaiser' ? 'kai' : $e['username']
        ]);

        $existingUser = $stmt->fetch();

        $id = null;

        if ($existingUser && $existingUser['employee_id']) {
            $id = (int)$existingUser['employee_id'];
        }

        // Otherwise match employee number.
        if (!$id) {
            $stmt = $pdo->prepare("
                SELECT id FROM employees
                WHERE employee_id = ?
                LIMIT 1
            ");
            $stmt->execute([$e['employee_id']]);
            $id = $stmt->fetchColumn();
        }

        if ($id) {

            $stmt = $pdo->prepare("
                UPDATE employees SET
                    employee_id = ?,
                    first_name = ?,
                    middle_name = ?,
                    last_name = ?,
                    date_of_birth = ?,
                    gender = ?,
                    phone = ?,
                    email = ?,
                    address = ?,
                    department_id = ?,
                    position_id = ?,
                    date_hired = ?,
                    employment_type = 'Full-time',
                    employment_status = 'Active'
                WHERE id = ?
            ");

            $stmt->execute([
                $e['employee_id'],
                $e['first_name'],
                $e['middle_name'],
                $e['last_name'],
                $e['dob'],
                $e['gender'],
                $e['phone'],
                $e['email'],
                $e['address'],
                $e['department'],
                $e['position'],
                $e['hired'],
                $id
            ]);

        } else {

            $stmt = $pdo->prepare("
                INSERT INTO employees (
                    employee_id, first_name, middle_name,
                    last_name, date_of_birth, gender,
                    phone, email, address,
                    department_id, position_id,
                    date_hired, employment_type,
                    employment_status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    'Full-time', 'Active'
                )
            ");

            $stmt->execute([
                $e['employee_id'],
                $e['first_name'],
                $e['middle_name'],
                $e['last_name'],
                $e['dob'],
                $e['gender'],
                $e['phone'],
                $e['email'],
                $e['address'],
                $e['department'],
                $e['position'],
                $e['hired']
            ]);

            $id = (int)$pdo->lastInsertId();
        }

        $employeeIds[$e['username']] = (int)$id;
    }


    // =========================================
    // PART 2: EMPLOYEE LOGIN ACCOUNTS
    // =========================================

    foreach ($employees as $e) {

        $id = $employeeIds[$e['username']];

        $hash = password_hash(
            $e['password'],
            PASSWORD_DEFAULT
        );

        // Find existing employee account.
        $stmt = $pdo->prepare("
            SELECT id FROM users
            WHERE username IN (?, ?)
            LIMIT 1
        ");

        $oldUsername = $e['username'] === 'kaiser'
            ? 'kai'
            : $e['username'];

        $stmt->execute([
            $e['username'],
            $oldUsername
        ]);

        $existing = $stmt->fetch();

        if ($existing) {

            $stmt = $pdo->prepare("
                UPDATE users SET
                    employee_id = ?,
                    username = ?,
                    password = ?,
                    role = 'employee',
                    status = 'Active'
                WHERE id = ?
            ");

            $stmt->execute([
                $id,
                $e['username'],
                $hash,
                $existing['id']
            ]);

        } else {

            $stmt = $pdo->prepare("
                INSERT INTO users (
                    employee_id,
                    username,
                    password,
                    role,
                    status
                ) VALUES (?, ?, ?, 'employee', 'Active')
            ");

            $stmt->execute([
                $id,
                $e['username'],
                $hash
            ]);
        }
    }

    // =========================================
    // HR AND ADMIN LOGIN ACCOUNTS
    // =========================================

    $accounts = [
        [
            'username' => 'hr',
            'password' => 'hr123',
            'role' => 'hr'
        ],
        [
            'username' => 'admin',
            'password' => 'admin123',
            'role' => 'admin'
        ]
    ];

    foreach ($accounts as $account) {

        $hash = password_hash(
            $account['password'],
            PASSWORD_DEFAULT
        );

        $stmt = $pdo->prepare("
            SELECT id FROM users
            WHERE username = ?
            LIMIT 1
        ");

        $stmt->execute([$account['username']]);

        $existing = $stmt->fetch();

        if ($existing) {

            $stmt = $pdo->prepare("
                UPDATE users SET
                    password = ?,
                    role = ?,
                    status = 'Active'
                WHERE id = ?
            ");

            $stmt->execute([
                $hash,
                $account['role'],
                $existing['id']
            ]);

        } else {

            $stmt = $pdo->prepare("
                INSERT INTO users (
                    employee_id,
                    username,
                    password,
                    role,
                    status
                ) VALUES (NULL, ?, ?, ?, 'Active')
            ");

            $stmt->execute([
                $account['username'],
                $hash,
                $account['role']
            ]);
        }
    }

    // =========================================
    // LEAVE BALANCES
    // =========================================

    foreach ($employees as $e) {

        $id = $employeeIds[$e['username']];

        // Legacy leave balance.
        $stmt = $pdo->prepare("
            SELECT id FROM `leave`
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([$e['email']]);

        if ($stmt->fetch()) {

            $stmt = $pdo->prepare("
                UPDATE `leave`
                SET annual_leave = 12,
                    sick_leave = 6,
                    personal_leave = 3
                WHERE email = ?
            ");

            $stmt->execute([$e['email']]);

        } else {

            $stmt = $pdo->prepare("
                INSERT INTO `leave` (
                    email,
                    annual_leave,
                    sick_leave,
                    personal_leave
                ) VALUES (?, 12, 6, 3)
            ");

            $stmt->execute([$e['email']]);
        }

        // Leave balances per type.
        $leaveTypes = [
            1 => 12,
            2 => 6,
            3 => 3
        ];

        foreach ($leaveTypes as $typeId => $days) {

            $stmt = $pdo->prepare("
                INSERT INTO leave_balances (
                    employee_id,
                    leave_type_id,
                    year,
                    allocated_days,
                    used_days,
                    remaining_days
                ) VALUES (?, ?, 2026, ?, 0, ?)

                ON DUPLICATE KEY UPDATE
                    allocated_days = VALUES(allocated_days),
                    remaining_days =
                        VALUES(allocated_days) - used_days
            ");

            $stmt->execute([
                $id,
                $typeId,
                $days,
                $days
            ]);
        }
    }


    // =========================================
    // PART 3: GENERATE ATTENDANCE
    // SEPTEMBER - OCTOBER 2026
    // =========================================

    $attendanceCount = 0;

    foreach ($employees as $e) {

        $id = $employeeIds[$e['username']];

        $startDate = new DateTime('2026-09-01');
        $endDate = new DateTime('2026-11-01');

        for (
            $date = clone $startDate;
            $date < $endDate;
            $date->modify('+1 day')
        ) {

            // Skip Saturday and Sunday.
            if ((int)$date->format('N') > 5) {
                continue;
            }

            $day = (int)$date->format('j');

            // Different time-in variations.
            $minutes = [0, 5, 10, 15];
            $lateMinutes = $minutes[($day + $id) % 4];

            // TIME IN
            $timeIn = clone $date;
            $timeIn->setTime(8, $lateMinutes, 0);

            // BREAK
            $breakTime = clone $date;
            $breakTime->setTime(12, 0, 0);

            // TIME OUT
            $timeOut = clone $date;
            $timeOut->setTime(17, $lateMinutes, 0);

            // Check existing attendance.
            $stmt = $pdo->prepare("
                SELECT id FROM attendance
                WHERE employee_id = ?
                AND DATE(time_in) = ?
                LIMIT 1
            ");

            $stmt->execute([
                $id,
                $date->format('Y-m-d')
            ]);

            if ($stmt->fetch()) {
                continue;
            }

            // Insert attendance.
            $stmt = $pdo->prepare("
                INSERT INTO attendance (
                    employee_id,
                    created_at,
                    email,
                    time_in,
                    time_out,
                    workhours,
                    Break_Time
                ) VALUES (?, ?, ?, ?, ?, 8.00, ?)
            ");

            $stmt->execute([
                $id,
                $timeIn->format('Y-m-d H:i:s'),
                $e['email'],
                $timeIn->format('Y-m-d H:i:s'),
                $timeOut->format('Y-m-d H:i:s'),
                $breakTime->format('Y-m-d H:i:s')
            ]);

            $attendanceCount++;
        }
    }

    echo "Part 3 completed: Attendance generated.\n";
    echo "New attendance records: $attendanceCount\n";

    // =========================================
    // VERIFY ATTENDANCE
    // =========================================

    foreach ($employees as $e) {

        $id = $employeeIds[$e['username']];

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM attendance
            WHERE employee_id = ?
            AND time_in >= '2026-09-01'
            AND time_in < '2026-11-01'
        ");

        $stmt->execute([$id]);

        echo $e['username'] . ': '
            . $stmt->fetchColumn()
            . " attendance records\n";
    }

    // =========================================
    // PART 4: EMPLOYEE SALARIES
    // =========================================

    $salaries = [];

    foreach ($employees as $e) {

        $id = $employeeIds[$e['username']];

        // Find existing active salary.
        $stmt = $pdo->prepare("
            SELECT id
            FROM employee_salaries
            WHERE employee_id = ?
            AND status = 'Active'
            ORDER BY effective_date DESC, id DESC
            LIMIT 1
        ");

        $stmt->execute([$id]);
        $existing = $stmt->fetch();

        if ($existing) {

            $stmt = $pdo->prepare("
                UPDATE employee_salaries SET
                    pay_type = 'Monthly',
                    basic_salary = ?,
                    allowance = ?,
                    effective_date = '2026-09-01'
                WHERE id = ?
            ");

            $stmt->execute([
                $e['salary'],
                $e['allowance'],
                $existing['id']
            ]);

        } else {

            $stmt = $pdo->prepare("
                INSERT INTO employee_salaries (
                    employee_id,
                    pay_type,
                    basic_salary,
                    allowance,
                    effective_date,
                    status
                ) VALUES (
                    ?, 'Monthly', ?, ?,
                    '2026-09-01', 'Active'
                )
            ");

            $stmt->execute([
                $id,
                $e['salary'],
                $e['allowance']
            ]);
        }

        $salaries[$e['username']] = [
            'basic' => $e['salary'],
            'allowance' => $e['allowance']
        ];
    }

    echo "Part 4A completed: Employee salaries prepared.\n";

    // =========================================
    // PAYROLL PERIODS
    // =========================================

    // Get admin user ID.
    $stmt = $pdo->prepare("
        SELECT id FROM users
        WHERE username = 'admin'
        LIMIT 1
    ");

    $stmt->execute();
    $adminId = $stmt->fetchColumn();

    if (!$adminId) {
        throw new Exception("Admin account not found.");
    }

    $payrollPeriods = [
        [
            'name' => 'September 2026 Payroll',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'pay_date' => '2026-10-05'
        ],
        [
            'name' => 'October 2026 Payroll',
            'start' => '2026-10-01',
            'end' => '2026-10-31',
            'pay_date' => '2026-11-05'
        ]
    ];

    $periodIds = [];

    foreach ($payrollPeriods as $period) {

        $stmt = $pdo->prepare("
            INSERT INTO payroll_periods (
                period_name,
                start_date,
                end_date,
                pay_date,
                status,
                created_by
            ) VALUES (
                ?, ?, ?, ?, 'Processed', ?
            )

            ON DUPLICATE KEY UPDATE
                period_name = VALUES(period_name),
                pay_date = VALUES(pay_date),
                status = 'Processed',
                created_by = VALUES(created_by)
        ");

        $stmt->execute([
            $period['name'],
            $period['start'],
            $period['end'],
            $period['pay_date'],
            $adminId
        ]);

        // Get payroll period ID.
        $stmt = $pdo->prepare("
            SELECT id
            FROM payroll_periods
            WHERE start_date = ?
            AND end_date = ?
            LIMIT 1
        ");

        $stmt->execute([
            $period['start'],
            $period['end']
        ]);

        $periodIds[] = (int)$stmt->fetchColumn();
    }

    // =========================================
    // PART 5: GENERATE PAYSLIPS
    // =========================================

    foreach ($employees as $e) {

        $employeeId = $employeeIds[$e['username']];

        $basic = (float)$e['salary'];
        $allowance = (float)$e['allowance'];

        foreach ($periodIds as $periodIndex => $periodId) {

            // Two months of sample payroll.
            $workingDays = ($periodIndex === 0) ? 22 : 22;
            $daysWorked = $workingDays;

            $absentDays = 0;
            $lateMinutes = 0;
            $overtimeHours = 0;

            $basicPay = $basic;

            $grossPay = $basicPay + $allowance;

            // Sample statutory deductions.
            $sss = round($basicPay * 0.045, 2);
            $philhealth = round($basicPay * 0.025, 2);
            $pagibig = 100;

            $withholdingTax = 0;
            $absenceDeduction = 0;
            $lateDeduction = 0;
            $otherDeductions = 0;

            $totalDeductions =
                $sss +
                $philhealth +
                $pagibig +
                $withholdingTax +
                $absenceDeduction +
                $lateDeduction +
                $otherDeductions;

            $netPay = $grossPay - $totalDeductions;

            // Check for an existing payslip.
            $stmt = $pdo->prepare("
                SELECT id
                FROM payslips
                WHERE payroll_period_id = ?
                AND employee_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $periodId,
                $employeeId
            ]);

            $existing = $stmt->fetch();

            if ($existing) {

                $stmt = $pdo->prepare("
                    UPDATE payslips SET
                        pay_type = 'Monthly',
                        basic_salary = ?,
                        working_days = ?,
                        days_worked = ?,
                        absent_days = ?,
                        late_minutes = ?,
                        ot_hours = ?,
                        basic_pay = ?,
                        allowance = ?,
                        overtime_pay = 0,
                        other_earnings = 0,
                        gross_pay = ?,
                        sss = ?,
                        philhealth = ?,
                        pagibig = ?,
                        withholding_tax = ?,
                        absences_deduction = ?,
                        late_deduction = ?,
                        other_deductions = ?,
                        total_deductions = ?,
                        net_pay = ?,
                        remarks = 'Sample payroll for testing',
                        status = 'Finalized'
                    WHERE id = ?
                ");

                $stmt->execute([
                    $basic,
                    $workingDays,
                    $daysWorked,
                    $absentDays,
                    $lateMinutes,
                    $overtimeHours,
                    $basicPay,
                    $allowance,
                    $grossPay,
                    $sss,
                    $philhealth,
                    $pagibig,
                    $withholdingTax,
                    $absenceDeduction,
                    $lateDeduction,
                    $otherDeductions,
                    $totalDeductions,
                    $netPay,
                    $existing['id']
                ]);

            } else {

                $stmt = $pdo->prepare("
                    INSERT INTO payslips (
                        payroll_period_id,
                        employee_id,
                        pay_type,
                        basic_salary,
                        working_days,
                        days_worked,
                        absent_days,
                        late_minutes,
                        ot_hours,
                        basic_pay,
                        allowance,
                        overtime_pay,
                        other_earnings,
                        gross_pay,
                        sss,
                        philhealth,
                        pagibig,
                        withholding_tax,
                        absences_deduction,
                        late_deduction,
                        other_deductions,
                        total_deductions,
                        net_pay,
                        remarks,
                        status
                    ) VALUES (
                        ?, ?, 'Monthly', ?, ?, ?, ?, ?, ?,
                        ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, 'Sample payroll for testing', 'Finalized'
                    )
                ");

                $stmt->execute([
                    $periodId,
                    $employeeId,
                    $basic,
                    $workingDays,
                    $daysWorked,
                    $absentDays,
                    $lateMinutes,
                    $overtimeHours,
                    $basicPay,
                    $allowance,
                    $grossPay,
                    $sss,
                    $philhealth,
                    $pagibig,
                    $withholdingTax,
                    $absenceDeduction,
                    $lateDeduction,
                    $otherDeductions,
                    $totalDeductions,
                    $netPay
                ]);
            }
        }
    }

    echo "Part 5 completed: Six payslips generated.\n";

    // =========================================
    // FINALIZE TRANSACTION
    // =========================================

    $pdo->commit();

    echo "\n====================================\n";
    echo "HRIS SAMPLE DATA SEEDED SUCCESSFULLY!\n";
    echo "====================================\n";

    echo "\nLOGIN ACCOUNTS:\n";
    echo "HR      : hr / hr123\n";
    echo "ADMIN   : admin / admin123\n";
    echo "BRANDON : brandon / brandon123\n";
    echo "DONALD  : donald / donald123\n";
    echo "KAISER  : kaiser / kaiser123\n";

    echo "\nGENERATED DATA:\n";
    echo "3 employee records\n";
    echo "2 months attendance\n";
    echo "2 payroll periods\n";
    echo "6 payslips\n";

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo "\nSEEDING FAILED!\n";
    echo $e->getMessage() . "\n";

    exit(1);
}

// RBAC tables (role_permissions and employees.manager_id).
// Safe to run on every start: existing permission rows are kept, so changes
// made in phpMyAdmin survive a restart. See database/migrate_rbac.php.
require_once __DIR__ . '/migrate_rbac.php';
