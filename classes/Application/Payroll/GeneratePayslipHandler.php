<?php

declare(strict_types=1);

namespace App\Application\Payroll;

use App\Domain\Payroll\CutOff;
use App\Domain\Payroll\LateDeductionPolicy;
use App\Domain\Payroll\Money;
use App\Domain\Payroll\Payslip;
use App\Domain\Payroll\PayslipRepositoryInterface;

/**
 * Application use case: generate one semi-monthly payslip.
 *
 * Knows the domain. Knows the repository interface.
 * Does NOT know MySQL, PDO, $_SESSION, or HTML.
 */
final class GeneratePayslipHandler
{
    private LateDeductionPolicy $policy;

    public function __construct(
        private readonly PayslipRepositoryInterface $repository,
    ) {
        $this->policy = new LateDeductionPolicy();
    }

    public function handle(GeneratePayslipCommand $command): Payslip
    {
        $payslip = Payslip::compute(
            employeeId: $command->employeeId,
            basicSalary: Money::fromPesos($command->basicSalary),
            allowance: Money::fromPesos($command->allowance),
            cutOff: CutOff::fromDates($command->periodStart, $command->periodEnd),
            lateRecords: $command->lateRecords,
            policy: $this->policy,
            sssRate: $command->sssRate,
            philRate: $command->philRate,
            pagIbig: Money::fromPesos($command->pagIbigFixed),
            payrollPeriodId: $command->payrollPeriodId,
        );

        $this->repository->save($payslip);

        return $payslip;
    }
}
