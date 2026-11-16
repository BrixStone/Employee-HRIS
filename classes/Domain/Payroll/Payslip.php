<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use DomainException;

final class Payslip
{
    private PayslipStatus $status;

    private function __construct(
        private readonly int $employeeId,
        private readonly ?int $payrollPeriodId,

        private readonly string $payType,
        private readonly Money $basicSalary,

        private readonly float $workingDays,
        private readonly float $daysWorked,
        private readonly float $absentDays,
        private readonly float $lateMinutes,
        private readonly float $overtimeHours,
        private readonly float $overtimeMultiplier,

        private readonly Money $basicPay,
        private readonly Money $allowance,
        private readonly Money $overtimePay,
        private readonly Money $grossPay,

        private readonly Money $sssDeduction,
        private readonly Money $philHealthDeduction,
        private readonly Money $pagIbigDeduction,
        private readonly Money $withholdingTax,
        private readonly Money $absenceDeduction,
        private readonly Money $lateDeduction,
        private readonly Money $totalDeductions,
        private readonly Money $netPay,

        private readonly ?int $dbId = null,
    ) {
        $this->status = PayslipStatus::Generated;
    }

    public static function compute(
        int $employeeId,
        string $payType,
        Money $basicSalary,
        Money $allowance,
        int $workingDays,
        float $daysWorked,
        float $workedHours,
        float $absentDays,
        array $lateRecords,
        float $overtimeHours,
        float $overtimeMultiplier,
        LateDeductionPolicy $latePolicy,
        float $sssRate,
        float $philHealthRate,
        Money $pagIbigFixed,
        float $withholdingTaxRate,
        ?int $payrollPeriodId = null,
    ): self {
        if (!in_array($payType, ['Monthly', 'Daily', 'Hourly'], true)) {
            throw new DomainException('Invalid pay type.');
        }

        if ($workingDays < 0) {
            throw new DomainException('Working days cannot be negative.');
        }

        $workingDays = max(1, $workingDays);
        $daysWorked = max(0, $daysWorked);
        $absentDays = max(0, $absentDays);
        $workedHours = max(0, $workedHours);
        $overtimeHours = max(0, $overtimeHours);

        /*
         * Calculate the base rate.
         */
        if ($payType === 'Monthly') {
            $periodSalary = $basicSalary->half();
            $dailyRate = $periodSalary->multiplyBy(
                1 / $workingDays
            );

            /*
             * Monthly employees receive the scheduled half-month
             * salary, less unauthorized absences.
             */
            $basicPay = $periodSalary;
            $absenceDeduction = $dailyRate->multiplyBy($absentDays);
            $hourlyRate = $dailyRate->multiplyBy(1 / 8);
        } elseif ($payType === 'Daily') {
            $dailyRate = $basicSalary;
            $basicPay = $dailyRate->multiplyBy($daysWorked);
            $absenceDeduction = Money::fromPesos(0);
            $hourlyRate = $dailyRate->multiplyBy(1 / 8);
        } else {
            /*
             * For hourly employees, basic_salary is the hourly rate.
             */
            $hourlyRate = $basicSalary;
            $basicPay = $hourlyRate->multiplyBy($workedHours);
            $absenceDeduction = Money::fromPesos(0);
        }

        /*
         * Late deduction uses the domain policy.
         */
        $lateDeductionDays = $latePolicy->totalDeductionDays(
            $lateRecords
        );

        if ($payType === 'Hourly') {
            $lateDeduction = $hourlyRate->multiplyBy(
                $lateDeductionDays * 8
            );
        } else {
            $lateDeduction = $dailyRate->multiplyBy(
                $lateDeductionDays
            );
        }


        $overtimePay = $hourlyRate->multiplyBy(
            $overtimeHours * $overtimeMultiplier
        );

        $periodAllowance = $payType === 'Monthly'
            ? $allowance->half()
            : $allowance;

        $grossPay = $basicPay
            ->add($periodAllowance)
            ->add($overtimePay);


        /*
         * Government deductions are calculated from the gross/base
         * according to the configured rates.
         */
        $sss = $basicSalary
            ->multiplyBy($sssRate);

        $philHealth = $basicSalary
            ->multiplyBy($philHealthRate);

        $pagIbig = $pagIbigFixed;

        $withholdingTax = $grossPay
            ->multiplyBy($withholdingTaxRate);

        if ($payType === 'Monthly') {
            $sss = $sss->half();
            $philHealth = $philHealth->half();
            $pagIbig = $pagIbig->half();
            $withholdingTax = $withholdingTax->half();
        }

        $totalDeductions = $sss
            ->add($philHealth)
            ->add($pagIbig)
            ->add($withholdingTax)
            ->add($absenceDeduction)
            ->add($lateDeduction);

        $netPay = $grossPay->subtract($totalDeductions);


        return new self(
            employeeId: $employeeId,
            payrollPeriodId: $payrollPeriodId,
            payType: $payType,
            basicSalary: $basicSalary,
            workingDays: $workingDays,
            daysWorked: $daysWorked,
            absentDays: $absentDays,
            lateMinutes: self::totalLateMinutes($lateRecords),
            overtimeHours: $overtimeHours,
            overtimeMultiplier: $overtimeMultiplier,
            basicPay: $basicPay,
            allowance: $periodAllowance,
            overtimePay: $overtimePay,
            grossPay: $grossPay,
            sssDeduction: $sss,
            philHealthDeduction: $philHealth,
            pagIbigDeduction: $pagIbig,
            withholdingTax: $withholdingTax,
            absenceDeduction: $absenceDeduction,
            lateDeduction: $lateDeduction,
            totalDeductions: $totalDeductions,
            netPay: $netPay,
        );
    }

    private static function totalLateMinutes(array $lateRecords): int
    {
        $total = 0;

        foreach ($lateRecords as $record) {
            $total += max(
                0,
                (int)($record['late_minutes'] ?? 0)
            );
        }

        return $total;
    }

    public function employeeId(): int
    {
        return $this->employeeId;
    }

    public function payrollPeriodId(): ?int
    {
        return $this->payrollPeriodId;
    }

    public function dbId(): ?int
    {
        return $this->dbId;
    }

    public function payType(): string
    {
        return $this->payType;
    }

    public function basicSalary(): Money
    {
        return $this->basicSalary;
    }

    public function workingDays(): float
    {
        return $this->workingDays;
    }

    public function daysWorked(): float
    {
        return $this->daysWorked;
    }

    public function absentDays(): float
    {
        return $this->absentDays;
    }

    public function lateMinutes(): float
    {
        return $this->lateMinutes;
    }

    public function overtimeHours(): float
    {
        return $this->overtimeHours;
    }

    public function overtimeMultiplier(): float
    {
        return $this->overtimeMultiplier;
    }

    public function basicPay(): Money
    {
        return $this->basicPay;
    }

    public function allowance(): Money
    {
        return $this->allowance;
    }

    public function overtimePay(): Money
    {
        return $this->overtimePay;
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

    public function withholdingTax(): Money
    {
        return $this->withholdingTax;
    }

    public function absenceDeduction(): Money
    {
        return $this->absenceDeduction;
    }

    public function lateDeduction(): Money
    {
        return $this->lateDeduction;
    }

    public function totalDeductions(): Money
    {
        return $this->totalDeductions;
    }

    public function netPay(): Money
    {
        return $this->netPay;
    }

    public function status(): PayslipStatus
    {
        return $this->status;
    }

    public function finalize(): void
    {
        if ($this->status !== PayslipStatus::Generated) {
            throw new DomainException(
                'Only generated payslips can be finalized.'
            );
        }

        if ($this->grossPay->centavos() <= 0) {
            throw new DomainException(
                'Cannot finalize a payslip with zero gross pay.'
            );
        }


        $this->status = $this->status->advance();
    }

    public function markPaid(): void
    {
        if ($this->status !== PayslipStatus::Finalized) {
            throw new DomainException(
                'Only finalized payslips can be marked paid.'
            );
        }

        $this->status = $this->status->advance();
    }

    public static function reconstitute(
        int $dbId,
        int $employeeId,
        ?int $payrollPeriodId,
        string $payType,
        Money $basicSalary,
        float $workingDays,
        float $daysWorked,
        float $absentDays,
        float $lateMinutes,
        float $overtimeHours,
        float $overtimeMultiplier,
        Money $basicPay,
        Money $allowance,
        Money $overtimePay,
        Money $grossPay,
        Money $sssDeduction,
        Money $philHealthDeduction,
        Money $pagIbigDeduction,
        Money $withholdingTax,
        Money $absenceDeduction,
        Money $lateDeduction,
        Money $totalDeductions,
        Money $netPay,
        PayslipStatus $status,
    ): self {
        $instance = new self(
            employeeId: $employeeId,
            payrollPeriodId: $payrollPeriodId,
            payType: $payType,
            basicSalary: $basicSalary,
            workingDays: $workingDays,
            daysWorked: $daysWorked,
            absentDays: $absentDays,
            lateMinutes: $lateMinutes,
            overtimeHours: $overtimeHours,
            overtimeMultiplier: $overtimeMultiplier,
            basicPay: $basicPay,
            allowance: $allowance,
            overtimePay: $overtimePay,
            grossPay: $grossPay,
            sssDeduction: $sssDeduction,
            philHealthDeduction: $philHealthDeduction,
            pagIbigDeduction: $pagIbigDeduction,
            withholdingTax: $withholdingTax,
            absenceDeduction: $absenceDeduction,
            lateDeduction: $lateDeduction,
            totalDeductions: $totalDeductions,
            netPay: $netPay,
            dbId: $dbId,
        );

        $instance->status = $status;

        return $instance;
    }
}
