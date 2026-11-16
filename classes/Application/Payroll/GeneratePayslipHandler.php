<?php

declare(strict_types=1);

namespace App\Application\Payroll;

use App\Domain\Payroll\CutOff;
use App\Domain\Payroll\LateDeductionPolicy;
use App\Domain\Payroll\Money;
use App\Domain\Payroll\Payslip;
use App\Domain\Payroll\PayslipRepositoryInterface;

final class GeneratePayslipHandler
{
    public function __construct(
        private readonly PayslipRepositoryInterface $repository,
        private readonly LateDeductionPolicy $latePolicy = new LateDeductionPolicy(),
    ) {
    }

    public function handle(
        GeneratePayslipCommand $command
    ): Payslip {
        $payslip = Payslip::compute(
            employeeId: $command->employeeId,
            payType: $command->payType,
            basicSalary: Money::fromPesos($command->basicSalary),
            allowance: Money::fromPesos($command->allowance),
            workingDays: $command->workingDays,
            daysWorked: $command->daysWorked,
            workedHours: $command->workedHours,
            absentDays: $command->absentDays,
            lateRecords: $command->lateRecords,
            overtimeHours: $command->overtimeHours,
            overtimeMultiplier: $command->overtimeMultiplier,
            latePolicy: $this->latePolicy,
            sssRate: $command->sssRate,
            philHealthRate: $command->philHealthRate,
            pagIbigFixed: Money::fromPesos(
                $command->pagIbigFixed
            ),
            withholdingTaxRate: $command->withholdingTaxRate,
            payrollPeriodId: $command->payrollPeriodId,
        );

        $this->repository->save($payslip);

        return $payslip;
    }
}
