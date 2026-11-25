<?php
// classes/Payroll.php
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/AttendanceService.php';


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
        $result = $stmt->execute([
            ':eid' => $employeeId,
            ':pt'  => $payType,
            ':bs'  => $basicSalary,
            ':al'  => $allowance,
            ':ed'  => $effectiveDate,
        ]);

        if ($result) {
            AuditLogger::log('salary.set', 'employee', $employeeId, [
                'pay_type'       => $payType,
                'basic_salary'   => $basicSalary,
                'allowance'      => $allowance,
                'effective_date' => $effectiveDate,
            ]);
        }

        return $result;
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

    public function updatePeriodStatus(
    int $periodId,
    string $newStatus
): bool {
    $allowedStatuses = [
        'Processed',
        'Paid',
        'Cancelled',
    ];

    if (!in_array($newStatus, $allowedStatuses, true)) {
        return false;
    }

    try {
        $this->pdo->beginTransaction();

        $periodStatement = $this->pdo->prepare(
            'SELECT status
             FROM payroll_periods
             WHERE id = :period_id
             LIMIT 1
             FOR UPDATE'
        );

        $periodStatement->execute([
            ':period_id' => $periodId,
        ]);

        $currentStatus = $periodStatement->fetchColumn();

        if ($currentStatus === false) {
            throw new RuntimeException(
                'Payroll period was not found.'
            );
        }

        /*
         * Only these transitions are allowed:
         *
         * Draft     → Processed
         * Processed → Paid
         */
        if ($currentStatus === 'Draft') {
            if ($newStatus !== 'Processed') {
                throw new RuntimeException(
                    'A Draft period can only be marked Processed.'
                );
            }
        } elseif ($currentStatus === 'Processed') {
            if ($newStatus !== 'Paid') {
                throw new RuntimeException(
                    'A Processed period can only be marked Paid.'
                );
            }
        } elseif ($currentStatus === 'Paid') {
            throw new RuntimeException(
                'A Paid payroll period cannot be changed.'
            );
        } elseif ($currentStatus === 'Cancelled') {
            throw new RuntimeException(
                'A Cancelled payroll period cannot be changed.'
            );
        } else {
            throw new RuntimeException(
                'Invalid payroll period status.'
            );
        }

        /*
         * Cancellation is allowed only before processing.
         */
        if ($newStatus === 'Cancelled') {
            if ($currentStatus !== 'Draft') {
                throw new RuntimeException(
                    'Only Draft periods can be cancelled.'
                );
            }

            $cancelStatement = $this->pdo->prepare(
                "UPDATE payroll_periods
                 SET status = 'Cancelled'
                 WHERE id = :period_id
                   AND status = 'Draft'"
            );

            $cancelStatement->execute([
                ':period_id' => $periodId,
            ]);

            $this->pdo->commit();

            return true;
        }

        /*
         * A period cannot be processed or paid without payslips.
         */
        $summaryStatement = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total_payslips,
                SUM(
                    CASE
                        WHEN status = 'Draft'
                        THEN 1
                        ELSE 0
                    END
                ) AS draft_payslips,
                SUM(
                    CASE
                        WHEN status = 'Finalized'
                        THEN 1
                        ELSE 0
                    END
                ) AS finalized_payslips,
                SUM(
                    CASE
                        WHEN status = 'Paid'
                        THEN 1
                        ELSE 0
                    END
                ) AS paid_payslips
             FROM payslips
             WHERE payroll_period_id = :period_id"
        );

        $summaryStatement->execute([
            ':period_id' => $periodId,
        ]);

        $summary = $summaryStatement->fetch(
            PDO::FETCH_ASSOC
        );

        $totalPayslips = (int)(
            $summary['total_payslips'] ?? 0
        );

        $draftPayslips = (int)(
            $summary['draft_payslips'] ?? 0
        );

        $finalizedPayslips = (int)(
            $summary['finalized_payslips'] ?? 0
        );

        $paidPayslips = (int)(
            $summary['paid_payslips'] ?? 0
        );

        if ($totalPayslips === 0) {
            throw new \RuntimeException(
                'You must generate payslips before marking '
                . 'this payroll period as Processed.'
            );
        }

        /*
         * Draft → Processed:
         * Every payslip must be finalized first.
         */
        if ($currentStatus === 'Draft') {
            if ($draftPayslips > 0) {
                throw new \RuntimeException(
                    'You must finalize all payslips before '
                    . 'marking this period as Processed.'
                );
            }

            if ($finalizedPayslips + $paidPayslips !== $totalPayslips) {
                throw new \RuntimeException(
                    'Every payslip must be finalized before '
                    . 'marking this period as Processed.'
                );
            }
        }

        /*
         * Processed → Paid:
         * Every payslip must still be Finalized.
         * They will all be marked Paid automatically below.
         */
        if ($currentStatus === 'Processed') {
            if ($draftPayslips > 0) {
                throw new \RuntimeException(
                    'You must finalize all payslips before '
                    . 'marking this period as Paid.'
                );
            }

            // Payslips already marked Paid one by one are fine.
            if ($finalizedPayslips + $paidPayslips !== $totalPayslips) {
                throw new \RuntimeException(
                    'Every payslip must be finalized before '
                    . 'marking this period as Paid.'
                );
            }
        }

        /*
         * Mark the payroll period.
         */
        $periodUpdate = $this->pdo->prepare(
            'UPDATE payroll_periods
             SET status = :new_status
             WHERE id = :period_id
               AND status = :current_status'
        );

        $periodUpdate->execute([
            ':new_status' => $newStatus,
            ':period_id' => $periodId,
            ':current_status' => $currentStatus,
        ]);

        /*
         * When the period becomes Paid, automatically mark
         * every Finalized payslip as Paid.
         */
        if ($newStatus === 'Paid') {
            $payslipUpdate = $this->pdo->prepare(
                "UPDATE payslips
                 SET status = 'Paid'
                 WHERE payroll_period_id = :period_id
                   AND status = 'Finalized'"
            );

            $payslipUpdate->execute([
                ':period_id' => $periodId,
            ]);
        }

        $this->pdo->commit();

        return true;
    } catch (\Throwable $exception) {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        throw $exception;
    }
}


    // ─── Attendance Summary ──────────────────────────────────────────────────

    public function getAttendanceSummary(
        int    $employeeId,
        string $startDate,
        string $endDate
    ): array {
        AttendanceService::ensureSchema($this->pdo);

        // Overtime: only APPROVED overtime is paid. Rows recorded before overtime
        // tracking (overtime_status IS NULL) keep the old rule, hours over the standard.
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(DISTINCT DATE(time_in))                                    AS days_worked,
                COALESCE(SUM(
                    CASE
                        WHEN overtime_status = 'APPROVED' THEN COALESCE(overtime_hours, 0)
                        WHEN overtime_status IS NULL      THEN GREATEST(COALESCE(workhours, 0) - :std1, 0)
                        ELSE 0
                    END
                ), 0)                                                            AS ot_hours,
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

    public function generatePayslip(int $periodId, int $employeeId): array
    {
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
                case 'sss':
                    $sss            = $amt;
                    break;
                case 'philhealth':
                    $philhealth     = $amt;
                    break;
                case 'pag-ibig':
                    $pagibig        = $amt;
                    break;
                case 'withholding tax':
                    $withholdingTax = $amt;
                    break;
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
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($data);

        if ($result) {
            $payslipId = (int)$this->pdo->lastInsertId();
            AuditLogger::log('payslip.generate', 'payslip', $payslipId, [
                'employee_id'       => $data['employee_id'],
                'payroll_period_id' => $data['payroll_period_id'],
                'net_pay'           => $data['net_pay'],
                'status'            => $data['status'],
            ]);
        }

        return $result;
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
        $result = $stmt->execute([':s' => $status, ':id' => $id]);

        if ($result) {
            AuditLogger::log('payslip.status_change', 'payslip', $id, [
                'new_status' => $status,
            ]);
        }

        return $result;
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
