<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Payroll\Money;
use App\Domain\Payroll\Payslip;
use App\Domain\Payroll\PayslipRepositoryInterface;
use App\Domain\Payroll\PayslipStatus;
use PDO;
use RuntimeException;

final class PdoPayslipRepository
implements PayslipRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    public function save(Payslip $payslip): void
    {
        $existing = $this->findStatus(
            $payslip->payrollPeriodId(),
            $payslip->employeeId()
        );

        if ($existing !== null && $existing !== 'Draft') {
            throw new RuntimeException(
                'Only Draft payslips can be regenerated.'
            );
        }

        $status = $this->toDbStatus($payslip->status());

        $sql = "
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
                ot_rate_multiplier,
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
                :period_id,
                :employee_id,
                :pay_type,
                :basic_salary,
                :working_days,
                :days_worked,
                :absent_days,
                :late_minutes,
                :ot_hours,
                :ot_multiplier,
                :basic_pay,
                :allowance,
                :overtime_pay,
                0,
                :gross_pay,
                :sss,
                :philhealth,
                :pagibig,
                :withholding_tax,
                :absence_deduction,
                :late_deduction,
                0,
                :total_deductions,
                :net_pay,
                NULL,
                :status
            )
            ON DUPLICATE KEY UPDATE
                pay_type = VALUES(pay_type),
                basic_salary = VALUES(basic_salary),
                working_days = VALUES(working_days),
                days_worked = VALUES(days_worked),
                absent_days = VALUES(absent_days),
                late_minutes = VALUES(late_minutes),
                ot_hours = VALUES(ot_hours),
                ot_rate_multiplier = VALUES(ot_rate_multiplier),
                basic_pay = VALUES(basic_pay),
                allowance = VALUES(allowance),
                overtime_pay = VALUES(overtime_pay),
                gross_pay = VALUES(gross_pay),
                sss = VALUES(sss),
                philhealth = VALUES(philhealth),
                pagibig = VALUES(pagibig),
                withholding_tax = VALUES(withholding_tax),
                absences_deduction = VALUES(absences_deduction),
                late_deduction = VALUES(late_deduction),
                total_deductions = VALUES(total_deductions),
                net_pay = VALUES(net_pay),
                status = 'Draft'
        ";

        $statement = $this->pdo->prepare($sql);

        $statement->execute([
            ':period_id' => $payslip->payrollPeriodId(),
            ':employee_id' => $payslip->employeeId(),
            ':pay_type' => $payslip->payType(),
            ':basic_salary' => $payslip->basicSalary()->format(),
            ':working_days' => $payslip->workingDays(),
            ':days_worked' => $payslip->daysWorked(),
            ':absent_days' => $payslip->absentDays(),
            ':late_minutes' => $payslip->lateMinutes(),
            ':ot_hours' => $payslip->overtimeHours(),
            ':ot_multiplier' => $payslip->overtimeMultiplier(),
            ':basic_pay' => $payslip->basicPay()->format(),
            ':allowance' => $payslip->allowance()->format(),
            ':overtime_pay' => $payslip->overtimePay()->format(),
            ':gross_pay' => $payslip->grossPay()->format(),
            ':sss' => $payslip->sssDeduction()->format(),
            ':philhealth' => $payslip->philHealthDeduction()->format(),
            ':pagibig' => $payslip->pagIbigDeduction()->format(),
            ':withholding_tax' => $payslip->withholdingTax()->format(),
            ':absence_deduction' => $payslip->absenceDeduction()->format(),
            ':late_deduction' => $payslip->lateDeduction()->format(),
            ':total_deductions' => $payslip->totalDeductions()->format(),
            ':net_pay' => $payslip->netPay()->format(),
            ':status' => $status,
        ]);
    }

    private function findStatus(
        ?int $periodId,
        int $employeeId
    ): ?string {
        if ($periodId === null) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT status
             FROM payslips
             WHERE payroll_period_id = :period_id
               AND employee_id = :employee_id
             LIMIT 1'
        );

        $statement->execute([
            ':period_id' => $periodId,
            ':employee_id' => $employeeId,
        ]);

        $status = $statement->fetchColumn();

        return $status === false ? null : (string)$status;
    }

    public function findByEmployeeId(int $employeeId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM payslips
             WHERE employee_id = :employee_id
             ORDER BY created_at DESC'
        );

        $statement->execute([
            ':employee_id' => $employeeId,
        ]);

        return $this->hydrate(
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function findAll(): array
    {
        $statement = $this->pdo->query(
            'SELECT *
             FROM payslips
             ORDER BY created_at DESC'
        );

        return $this->hydrate(
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function hydrate(array $rows): array
    {
        return array_map(
            fn(array $row): Payslip => Payslip::reconstitute(
                dbId: (int)$row['id'],
                employeeId: (int)$row['employee_id'],
                payrollPeriodId: $row['payroll_period_id']
                    ? (int)$row['payroll_period_id']
                    : null,
                payType: (string)$row['pay_type'],
                basicSalary: Money::fromPesos(
                    (float)$row['basic_salary']
                ),
                workingDays: (float)$row['working_days'],
                daysWorked: (float)$row['days_worked'],
                absentDays: (float)$row['absent_days'],
                lateMinutes: (float)$row['late_minutes'],
                overtimeHours: (float)$row['ot_hours'],
                overtimeMultiplier: (float)$row['ot_rate_multiplier'],
                basicPay: Money::fromPesos(
                    (float)$row['basic_pay']
                ),
                allowance: Money::fromPesos(
                    (float)$row['allowance']
                ),
                overtimePay: Money::fromPesos(
                    (float)$row['overtime_pay']
                ),
                grossPay: Money::fromPesos(
                    (float)$row['gross_pay']
                ),
                sssDeduction: Money::fromPesos(
                    (float)$row['sss']
                ),
                philHealthDeduction: Money::fromPesos(
                    (float)$row['philhealth']
                ),
                pagIbigDeduction: Money::fromPesos(
                    (float)$row['pagibig']
                ),
                withholdingTax: Money::fromPesos(
                    (float)$row['withholding_tax']
                ),
                absenceDeduction: Money::fromPesos(
                    (float)$row['absences_deduction']
                ),
                lateDeduction: Money::fromPesos(
                    (float)$row['late_deduction']
                ),
                totalDeductions: Money::fromPesos(
                    (float)$row['total_deductions']
                ),
                netPay: Money::fromPesos(
                    (float)$row['net_pay']
                ),
                status: $this->toDomainStatus(
                    (string)$row['status']
                ),
            ),
            $rows
        );
    }

    private function toDbStatus(PayslipStatus $status): string
    {
        return match ($status) {
            PayslipStatus::Generated => 'Draft',
            PayslipStatus::Finalized => 'Finalized',
            PayslipStatus::Paid => 'Paid',
        };
    }

    private function toDomainStatus(
        string $status
    ): PayslipStatus {
        return match (strtolower($status)) {
            'generated' => PayslipStatus::Generated,
            'finalized' => PayslipStatus::Finalized,
            'paid'      => PayslipStatus::Paid,
            default     => PayslipStatus::Generated,
        };
    }
    public function finalizeAllForPeriod(int $periodId): int
    {
        $this->pdo->beginTransaction();

        try {
            $check = $this->pdo->prepare(
                "SELECT
                COUNT(*) AS total_count,
                SUM(
                    CASE
                        WHEN status <> 'Draft'
                        THEN 1
                        ELSE 0
                    END
                ) AS invalid_count,
                SUM(
                    CASE
                        WHEN gross_pay <= 0
                        THEN 1
                        ELSE 0
                    END
                ) AS invalid_amount_count
             FROM payslips
             WHERE payroll_period_id = :period_id"
            );

            $check->execute([
                ':period_id' => $periodId,
            ]);

            $result = $check->fetch(\PDO::FETCH_ASSOC);

            $totalCount = (int)($result['total_count'] ?? 0);
            $invalidCount = (int)($result['invalid_count'] ?? 0);
            $invalidAmountCount = (int)(
                $result['invalid_amount_count'] ?? 0
            );

            if ($totalCount === 0) {
                throw new \RuntimeException(
                    'There are no payslips to finalize.'
                );
            }

            if ($invalidCount > 0) {
                throw new \RuntimeException(
                    'All payslips must be Draft before finalization.'
                );
            }

            if ($invalidAmountCount > 0) {
                throw new \RuntimeException(
                    'All payslips must have a positive gross amount.'
                );
            }

            $update = $this->pdo->prepare(
                "UPDATE payslips
             SET status = 'Finalized'
             WHERE payroll_period_id = :period_id
               AND status = 'Draft'"
            );

            $update->execute([
                ':period_id' => $periodId,
            ]);

            $updatedCount = $update->rowCount();

            $this->pdo->commit();

            return $updatedCount;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
