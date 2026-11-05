<?php
// classes/Payroll.php

class Payroll
{
    private PDO $pdo;

    private const LATE_CUTOFF     = '09:00:00';
    private const STANDARD_HOURS  = 8;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ─── Deduction Rates ────────────────────────────────────────────────────

    public function getDeductionRates(): array
    {
        return $this->pdo
            ->query("SELECT * FROM deduction_rates WHERE is_active = 1 ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Employee Salary ────────────────────────────────────────────────────

    public function getActiveSalary(int $employeeId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM employee_salaries
            WHERE employee_id = :eid AND status = 'Active'
            ORDER BY effective_date DESC
            LIMIT 1
        ");
        $stmt->execute([':eid' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function setSalary(
        int    $employeeId,
        string $payType,
        float  $basicSalary,
        float  $allowance,
        string $effectiveDate
    ): bool {
        // Deactivate existing active salary first
        $this->pdo->prepare("
            UPDATE employee_salaries
            SET status = 'Inactive'
            WHERE employee_id = :eid AND status = 'Active'
        ")->execute([':eid' => $employeeId]);

        // Insert new salary
        $stmt = $this->pdo->prepare("
            INSERT INTO employee_salaries
                (employee_id, pay_type, basic_salary, allowance, effective_date, status)
            VALUES
                (:eid, :pt, :bs, :al, :ed, 'Active')
        ");
        return $stmt->execute([
            ':eid' => $employeeId,
            ':pt'  => $payType,
            ':bs'  => $basicSalary,
            ':al'  => $allowance,
            ':ed'  => $effectiveDate,
        ]);
    }

    public function getAllSalaries(): array
    {
        return $this->pdo->query("
            SELECT
                es.*,
                e.first_name,
                e.last_name,
                e.employee_id  AS emp_code,
                d.name         AS department_name,
                p.title        AS position_title
            FROM employee_salaries es
            JOIN employees e ON e.id = es.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN positions   p ON p.id = e.position_id
            WHERE es.status = 'Active'
            ORDER BY e.last_name, e.first_name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Payroll Periods ────────────────────────────────────────────────────

    public function createPeriod(
        string $name,
        string $startDate,
        string $endDate,
        string $payDate
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO payroll_periods (period_name, start_date, end_date, pay_date, status)
            VALUES (:n, :s, :e, :p, 'Draft')
        ");
        $stmt->execute([
            ':n' => $name,
            ':s' => $startDate,
            ':e' => $endDate,
            ':p' => $payDate,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getAllPeriods(): array
    {
        return $this->pdo->query("
            SELECT
                pp.*,
                COUNT(ps.id) AS payslip_count
            FROM payroll_periods pp
            LEFT JOIN payslips ps ON ps.payroll_period_id = pp.id
            GROUP BY pp.id
            ORDER BY pp.start_date DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPeriodById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM payroll_periods WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updatePeriodStatus(int $id, string $status): bool
    {
        $allowed = ['Draft', 'Processed', 'Paid', 'Cancelled'];
        if (!in_array($status, $allowed, true)) return false;
        $stmt = $this->pdo->prepare("
            UPDATE payroll_periods SET status = :s WHERE id = :id
        ");
        return $stmt->execute([':s' => $status, ':id' => $id]);
    }

    // ─── Attendance Summary ──────────────────────────────────────────────────

    public function getAttendanceSummary(
        int    $employeeId,
        string $startDate,
        string $endDate
    ): array {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(DISTINCT DATE(time_in))                                    AS days_worked,
                COALESCE(SUM(GREATEST(workhours - :std1, 0)), 0)                 AS ot_hours,
                COALESCE(SUM(
                    CASE
                        WHEN TIME(time_in) > :cutoff
                        THEN TIMESTAMPDIFF(
                            MINUTE,
                            CONCAT(DATE(time_in), ' ', :cutoff2),
                            time_in
                        )
                        ELSE 0
                    END
                ), 0)                                                            AS late_minutes
            FROM attendance
            WHERE employee_id = :eid
              AND DATE(time_in) BETWEEN :start AND :end
              AND time_in IS NOT NULL
        ");
        $stmt->execute([
            ':eid'    => $employeeId,
            ':start'  => $startDate,
            ':end'    => $endDate,
            ':std1'   => self::STANDARD_HOURS,
            ':cutoff' => self::LATE_CUTOFF,
            ':cutoff2' => self::LATE_CUTOFF,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $workingDays = $this->countWorkingDays($startDate, $endDate);
        $daysWorked  = (float)($row['days_worked']  ?? 0);
        $absentDays  = max(0, $workingDays - $daysWorked);

        return [
            'working_days' => $workingDays,
            'days_worked'  => $daysWorked,
            'absent_days'  => $absentDays,
            'ot_hours'     => (float)($row['ot_hours']     ?? 0),
            'late_minutes' => (int)  ($row['late_minutes'] ?? 0),
        ];
    }

    // ─── DSA: Binary Search — late deduction bracket ─────────────────────────

    /**
     * Binary search through late-minute brackets to find deduction in days.
     *   0–30  min  → 0 days deducted
     *   31–60 min  → 0.5 day deducted
     *   61+   min  → 1 day deducted
     */
    public function binarySearchLateDeductionDays(int $lateMinutes): float
    {
        $brackets = [
            ['min' => 0,  'max' => 30,           'deduct_days' => 0.0],
            ['min' => 31, 'max' => 60,            'deduct_days' => 0.5],
            ['min' => 61, 'max' => PHP_INT_MAX,   'deduct_days' => 1.0],
        ];

        $lo = 0;
        $hi = count($brackets) - 1;

        while ($lo <= $hi) {
            $mid = (int)(($lo + $hi) / 2);
            if ($lateMinutes < $brackets[$mid]['min']) {
                $hi = $mid - 1;
            } elseif ($lateMinutes > $brackets[$mid]['max']) {
                $lo = $mid + 1;
            } else {
                return $brackets[$mid]['deduct_days'];
            }
        }
        return 0.0;
    }

    // ─── Payslip Generation ──────────────────────────────────────────────────

    public function generatePayslip(int $periodId, int $employeeId): array {
    $period = $this->getPeriodById($periodId);
    $salary = $this->getActiveSalary($employeeId);
    $rates  = $this->getDeductionRates();
    $attend = $this->getAttendanceSummary(
        $employeeId,
        $period['start_date'],
        $period['end_date']
    );

    if (!$salary) {
        return ['error' => 'No active salary found for this employee.'];
    }

    $basicSalary  = (float)$salary['basic_salary'];
    $allowance    = (float)$salary['allowance'];
    $payType      = $salary['pay_type'];
    $workingDays  = $attend['working_days'];
    $daysWorked   = $attend['days_worked'];
    $absentDays   = $attend['absent_days'];
    $lateMinutes  = $attend['late_minutes'];
    $otHours      = $attend['ot_hours'];
    $otMultiplier = 1.25;

    // ── Daily rate — semi-monthly aware ──────────────────────────────────
    if ($payType === 'Monthly') {
        // Split salary in half for this cut-off
        // then divide by actual working days in the period
        $periodWorkingDays = max(1, $this->countWorkingDays(
            $period['start_date'],
            $period['end_date']
        ));
        $halfMonthSalary = $basicSalary / 2;
        $dailyRate       = $halfMonthSalary / $periodWorkingDays;
    } else {
        // Daily or Hourly — use as-is
        $dailyRate = $basicSalary;
    }

    $hourlyRate = $dailyRate / self::STANDARD_HOURS;

    // ── Earnings ─────────────────────────────────────────────────────────
    $basicPay    = round($dailyRate * $daysWorked, 2);
    $overtimePay = round($hourlyRate * $otMultiplier * $otHours, 2);

    // ── Absence and Late deductions ──────────────────────────────────────
    $absencesDeduction = round($dailyRate * $absentDays, 2);
    $lateDays          = $this->binarySearchLateDeductionDays($lateMinutes);
    $lateDeduction     = round($dailyRate * $lateDays, 2);

    // ── Allowance also split in half for semi-monthly ────────────────────
    $periodAllowance = $payType === 'Monthly'
        ? round($allowance / 2, 2)
        : $allowance;

    // ── Gross ────────────────────────────────────────────────────────────
    $grossPay = round($basicPay + $periodAllowance + $overtimePay, 2);

    // ── Government deductions from DB ─────────────────────────────────────
    // For Monthly employees: deductions are also split per cut-off
    $sss = $philhealth = $pagibig = $withholdingTax = 0.0;
    foreach ($rates as $r) {
        $base = strtolower($r['applies_to']) === 'gross' ? $grossPay : $basicSalary;
        $amt  = $r['type'] === 'Percentage'
            ? round($base * ((float)$r['rate'] / 100), 2)
            : round((float)$r['rate'], 2);

        // Split in half for semi-monthly
        if ($payType === 'Monthly') {
            $amt = round($amt / 2, 2);
        }

        switch (strtolower($r['name'])) {
            case 'sss':             $sss            = $amt; break;
            case 'philhealth':      $philhealth     = $amt; break;
            case 'pag-ibig':        $pagibig        = $amt; break;
            case 'withholding tax': $withholdingTax = $amt; break;
        }
    }

    $totalDeductions = round(
        $sss + $philhealth + $pagibig + $withholdingTax
        + $absencesDeduction + $lateDeduction,
        2
    );
    $netPay = round($grossPay - $totalDeductions, 2);

    return [
        'payroll_period_id'  => $periodId,
        'employee_id'        => $employeeId,
        'pay_type'           => $payType,
        'basic_salary'       => $basicSalary,
        'working_days'       => $workingDays,
        'days_worked'        => $daysWorked,
        'absent_days'        => $absentDays,
        'late_minutes'       => $lateMinutes,
        'ot_hours'           => $otHours,
        'ot_rate_multiplier' => $otMultiplier,
        'basic_pay'          => $basicPay,
        'allowance'          => $periodAllowance,
        'overtime_pay'       => $overtimePay,
        'other_earnings'     => 0.00,
        'gross_pay'          => $grossPay,
        'sss'                => $sss,
        'philhealth'         => $philhealth,
        'pagibig'            => $pagibig,
        'withholding_tax'    => $withholdingTax,
        'absences_deduction' => $absencesDeduction,
        'late_deduction'     => $lateDeduction,
        'other_deductions'   => 0.00,
        'total_deductions'   => $totalDeductions,
        'net_pay'            => $netPay,
        'status'             => 'Draft',
    ];
}

    public function savePayslip(array $data): bool
    {
        $sql = "
            INSERT INTO payslips (
                payroll_period_id, employee_id, pay_type, basic_salary,
                working_days, days_worked, absent_days, late_minutes,
                ot_hours, ot_rate_multiplier,
                basic_pay, allowance, overtime_pay, other_earnings, gross_pay,
                sss, philhealth, pagibig, withholding_tax,
                absences_deduction, late_deduction, other_deductions,
                total_deductions, net_pay, status
            ) VALUES (
                :payroll_period_id, :employee_id, :pay_type, :basic_salary,
                :working_days, :days_worked, :absent_days, :late_minutes,
                :ot_hours, :ot_rate_multiplier,
                :basic_pay, :allowance, :overtime_pay, :other_earnings, :gross_pay,
                :sss, :philhealth, :pagibig, :withholding_tax,
                :absences_deduction, :late_deduction, :other_deductions,
                :total_deductions, :net_pay, :status
            )
            ON DUPLICATE KEY UPDATE
                pay_type            = VALUES(pay_type),
                basic_salary        = VALUES(basic_salary),
                working_days        = VALUES(working_days),
                days_worked         = VALUES(days_worked),
                absent_days         = VALUES(absent_days),
                late_minutes        = VALUES(late_minutes),
                ot_hours            = VALUES(ot_hours),
                ot_rate_multiplier  = VALUES(ot_rate_multiplier),
                basic_pay           = VALUES(basic_pay),
                allowance           = VALUES(allowance),
                overtime_pay        = VALUES(overtime_pay),
                other_earnings      = VALUES(other_earnings),
                gross_pay           = VALUES(gross_pay),
                sss                 = VALUES(sss),
                philhealth          = VALUES(philhealth),
                pagibig             = VALUES(pagibig),
                withholding_tax     = VALUES(withholding_tax),
                absences_deduction  = VALUES(absences_deduction),
                late_deduction      = VALUES(late_deduction),
                other_deductions    = VALUES(other_deductions),
                total_deductions    = VALUES(total_deductions),
                net_pay             = VALUES(net_pay),
                status              = VALUES(status)
        ";
        return $this->pdo->prepare($sql)->execute($data);
    }

    public function getPayslipsByPeriod(int $periodId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                ps.*,
                e.first_name,
                e.last_name,
                e.employee_id  AS emp_code,
                d.name         AS department_name,
                p.title        AS position_title
            FROM payslips ps
            JOIN employees e ON e.id = ps.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN positions   p ON p.id = e.position_id
            WHERE ps.payroll_period_id = :pid
            ORDER BY e.last_name, e.first_name
        ");
        $stmt->execute([':pid' => $periodId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPayslipById(int $payslipId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                ps.*,
                e.first_name,
                e.last_name,
                e.employee_id  AS emp_code,
                e.email,
                e.date_hired,
                d.name         AS department_name,
                p.title        AS position_title,
                pp.period_name,
                pp.start_date,
                pp.end_date,
                pp.pay_date
            FROM payslips ps
            JOIN employees      e  ON e.id  = ps.employee_id
            JOIN payroll_periods pp ON pp.id = ps.payroll_period_id
            LEFT JOIN departments d ON d.id  = e.department_id
            LEFT JOIN positions   p ON p.id  = e.position_id
            WHERE ps.id = :id
        ");
        $stmt->execute([':id' => $payslipId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getPayslipsByEmployee(int $employeeId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                ps.*,
                pp.period_name,
                pp.start_date,
                pp.end_date,
                pp.pay_date
            FROM payslips ps
            JOIN payroll_periods pp ON pp.id = ps.payroll_period_id
            WHERE ps.employee_id = :eid
            ORDER BY pp.start_date DESC
        ");
        $stmt->execute([':eid' => $employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updatePayslipStatus(int $id, string $status): bool
    {
        $allowed = ['Draft', 'Finalized', 'Paid'];
        if (!in_array($status, $allowed, true)) return false;
        $stmt = $this->pdo->prepare("
            UPDATE payslips SET status = :s WHERE id = :id
        ");
        return $stmt->execute([':s' => $status, ':id' => $id]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function countWorkingDays(string $startDate, string $endDate): int
    {
        $start = new DateTime($startDate);
        $end   = new DateTime($endDate);
        $count = 0;
        while ($start <= $end) {
            $dow = (int)$start->format('N'); // 1=Mon 7=Sun
            if ($dow < 6) $count++;
            $start->modify('+1 day');
        }
        return $count;
    }
}
