<?php
declare(strict_types=1);

namespace App\Application\Payroll;

/**
 * Immutable data carrier from the presentation layer to the handler.
 * No logic — just validated input.
 */
final class GeneratePayslipCommand
{
    public function __construct(
        public readonly int    $employeeId,
        public readonly float  $basicSalary,
        public readonly float  $allowance,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly array  $lateRecords,
        public readonly float  $sssRate,
        public readonly float  $philRate,
        public readonly float  $pagIbigFixed,
        public readonly ?int   $payrollPeriodId = null,
    ) {}
}