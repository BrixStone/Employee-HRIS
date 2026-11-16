<?php

declare(strict_types=1);

namespace App\Application\Payroll;

final class GeneratePayslipCommand
{
    public function __construct(
        public readonly int $employeeId,
        public readonly string $payType,
        public readonly float $basicSalary,
        public readonly float $allowance,

        public readonly int $workingDays,
        public readonly float $daysWorked,
        public readonly float $workedHours,
        public readonly float $absentDays,

        public readonly array $lateRecords,
        public readonly float $overtimeHours,
        public readonly float $overtimeMultiplier,

        public readonly float $sssRate,
        public readonly float $philHealthRate,
        public readonly float $pagIbigFixed,
        public readonly float $withholdingTaxRate,

        public readonly ?int $payrollPeriodId = null,
    ) {
    }
}
