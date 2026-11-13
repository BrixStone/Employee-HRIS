<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Payroll\Money;
use App\Domain\Payroll\Payslip;
use App\Domain\Payroll\PayslipRepositoryInterface;
use App\Domain\Payroll\PayslipStatus;
use PDO;

final class PdoPayslipRepository implements PayslipRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(Payslip $payslip): void
    {
        $dbStatus = $this->toDbStatus($payslip->status());

        if ($payslip->dbId() !== null) {
            $this->pdo->prepare("
                UPDATE payslips SET
                    gross_pay        = :gross,
                    basic_pay        = :basic_pay,
                    sss              = :sss,
                    philhealth       = :philhealth,
                    pagibig          = :pagibig,
                    total_deductions = :total_ded,
                    net_pay          = :net,
                    status           = :status
                WHERE id = :id
            ")->execute([
                ':gross'     => $payslip->grossPay()->format(),
                ':basic_pay' => $payslip->grossPay()->subtract($payslip->sssDeduction())->subtract($payslip->philHealthDeduction())->subtract($payslip->pagIbigDeduction())->format(),
                ':sss'       => $payslip->sssDeduction()->format(),
                ':philhealth'=> $payslip->philHealthDeduction()->format(),
                ':pagibig'   => $payslip->pagIbigDeduction()->format(),
                ':total_ded' => $payslip->sssDeduction()->add($payslip->philHealthDeduction())->add($payslip->pagIbigDeduction())->format(),
                ':net'       => $payslip->netPay()->format(),
                ':status'    => $dbStatus,
                ':id'        => $payslip->dbId(),
            ]);
            return;
        }

        $this->pdo->prepare("
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
                :period_id, :emp_id, 'Monthly',
                :basic_salary, 0, 0, 0,
                0, 0, 1.25,
                :basic_pay, 0, 0, 0,
                :gross, :sss, :philhealth, :pagibig,
                0, 0, 0,
                0, :total_ded, :net,
                '', :status
            )
        ")->execute([
            ':period_id'   => $payslip->payrollPeriodId(),
            ':emp_id'      => $payslip->employeeId(),
            ':basic_salary'=> $payslip->grossPay()->format(),
            ':basic_pay'   => $payslip->grossPay()->subtract($payslip->sssDeduction())->subtract($payslip->philHealthDeduction())->subtract($payslip->pagIbigDeduction())->format(),
            ':gross'       => $payslip->grossPay()->format(),
            ':sss'         => $payslip->sssDeduction()->format(),
            ':philhealth'  => $payslip->philHealthDeduction()->format(),
            ':pagibig'     => $payslip->pagIbigDeduction()->format(),
            ':total_ded'   => $payslip->sssDeduction()->add($payslip->philHealthDeduction())->add($payslip->pagIbigDeduction())->format(),
            ':net'         => $payslip->netPay()->format(),
            ':status'      => $dbStatus,
        ]);
    }

    public function findByEmployeeId(int $employeeId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, payroll_period_id, employee_id,
                   gross_pay, sss, philhealth, pagibig,
                   total_deductions, net_pay, status
            FROM payslips
            WHERE employee_id = :eid
            ORDER BY created_at DESC
        ");
        $stmt->execute([':eid' => $employeeId]);
        return $this->hydrate($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, payroll_period_id, employee_id,
                   gross_pay, sss, philhealth, pagibig,
                   total_deductions, net_pay, status
            FROM payslips
            ORDER BY created_at DESC
        ");
        return $this->hydrate($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function hydrate(array $rows): array
    {
        return array_map(fn(array $row) => Payslip::reconstitute(
            dbId:                (int) $row['id'],
            employeeId:          (int) $row['employee_id'],
            payrollPeriodId:     $row['payroll_period_id'] ? (int) $row['payroll_period_id'] : null,
            grossPay:            Money::fromPesos((float) $row['gross_pay']),
            sssDeduction:        Money::fromPesos((float) $row['sss']),
            philHealthDeduction: Money::fromPesos((float) $row['philhealth']),
            pagIbigDeduction:    Money::fromPesos((float) $row['pagibig']),
            netPay:              Money::fromPesos((float) $row['net_pay']),
            status:              $this->toDomainStatus($row['status']),
        ), $rows);
    }

    private function toDbStatus(PayslipStatus $status): string
    {
        return match($status) {
            PayslipStatus::Generated => 'Draft',
            PayslipStatus::Finalized => 'Finalized',
            PayslipStatus::Paid      => 'Paid',
        };
    }

    private function toDomainStatus(string $dbStatus): PayslipStatus
    {
        return match($dbStatus) {
            'Finalized' => PayslipStatus::Finalized,
            'Paid'      => PayslipStatus::Paid,
            default     => PayslipStatus::Generated,
        };
    }
}