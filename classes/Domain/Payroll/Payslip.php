<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use DomainException;

/**
 * Aggregate root for a single semi-monthly payslip.
 *
 * All monetary values are Money objects (integer centavos).
 * Status transitions are enforced by PayslipStatus::advance().
 */
final class Payslip
{
    private PayslipStatus $status;

    private function __construct(
        private readonly int    $employeeId,
        private readonly Money  $grossPay,
        private readonly Money  $sssDeduction,
        private readonly Money  $philHealthDeduction,
        private readonly Money  $pagIbigDeduction,
        private readonly Money  $netPay,
        private readonly ?int   $payrollPeriodId = null,
        private readonly ?int   $dbId            = null,
    ) {
        $this->status = PayslipStatus::Generated;
    }

    public static function compute(
        int                 $employeeId,
        Money               $basicSalary,
        Money               $allowance,
        CutOff              $cutOff,
        array               $lateRecords,
        LateDeductionPolicy $policy,
        float               $sssRate,
        float               $philRate,
        Money               $pagIbig,
        ?int                $payrollPeriodId = null,
    ): self {
        $workingDays = $cutOff->workingDays();

        // Semi-monthly split
        $halfSalary    = $basicSalary->half();
        $halfAllowance = $allowance->half();

        // Daily rate based on half-month salary and working days in period
        $dailyRate = $workingDays > 0
            ? $halfSalary->multiplyBy(1 / $workingDays)
            : Money::fromPesos(0.00);

        // Late deduction using Binary Search policy
        $deductDays = $policy->totalDeductionDays($lateRecords);
        $lateDeduct = $dailyRate->multiplyBy($deductDays);

        // Gross = half salary − late deduction + half allowance
        $gross = $halfSalary->subtract($lateDeduct)->add($halfAllowance);

        // Deductions are semi-monthly (halved)
        $sss       = $basicSalary->multiplyBy($sssRate)->half();
        $philHealth = $basicSalary->multiplyBy($philRate)->half();
        $pagIbigHalf = $pagIbig->half();

        // Net pay
        $net = $gross
            ->subtract($sss)
            ->subtract($philHealth)
            ->subtract($pagIbigHalf);

                return new self(
            employeeId:          $employeeId,
            grossPay:            $gross,
            sssDeduction:        $sss,
            philHealthDeduction: $philHealth,
            pagIbigDeduction:    $pagIbigHalf,
            netPay:              $net,
            payrollPeriodId:     $payrollPeriodId,
        );
    }

    // ── Accessors ─────────────────────────────────────────────────────

    public function employeeId(): int
    {
        return $this->employeeId;
    }
    public function grossPay(): Money
    {
        return $this->grossPay;
    }
    public function sssDeduction(): Money
    {
        return $this->sssDeduction;
    }
    public function philHealthDeduction(): Money
    {
        return $this->philHealthDeduction;
    }
    public function pagIbigDeduction(): Money
    {
        return $this->pagIbigDeduction;
    }
    public function netPay(): Money
    {
        return $this->netPay;
    }
    public function status(): PayslipStatus
    {
        return $this->status;
    }

    // ── Status transitions ────────────────────────────────────────────

    public function finalize(): void
    {
        if ($this->grossPay->centavos() === 0) {
            throw new DomainException(
                'Cannot finalize a payslip with zero gross pay.'
            );
        }
        $this->status = $this->status->advance();
    }

    public function markPaid(): void
    {
        $this->status = $this->status->advance();
    }

    public function payrollPeriodId(): ?int
    {
        return $this->payrollPeriodId;
    }
    public function dbId(): ?int
    {
        return $this->dbId;
    }

    /**
     * Rebuild a Payslip from stored DB values without recomputing.
     * Used exclusively by PdoPayslipRepository.
     */
    public static function reconstitute(
        int           $dbId,
        int           $employeeId,
        ?int          $payrollPeriodId,
        Money         $grossPay,
        Money         $sssDeduction,
        Money         $philHealthDeduction,
        Money         $pagIbigDeduction,
        Money         $netPay,
        PayslipStatus $status,
    ): self {
        $instance = new self(
            employeeId: $employeeId,
            grossPay: $grossPay,
            sssDeduction: $sssDeduction,
            philHealthDeduction: $philHealthDeduction,
            pagIbigDeduction: $pagIbigDeduction,
            netPay: $netPay,
            payrollPeriodId: $payrollPeriodId,
            dbId: $dbId,
        );
        $instance->status = $status;
        return $instance;
    }
}
