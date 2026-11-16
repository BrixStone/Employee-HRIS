<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Payroll;

use App\Application\Payroll\GeneratePayslipCommand;
use App\Application\Payroll\GeneratePayslipHandler;
use App\Domain\Payroll\Money;
use App\Domain\Payroll\PayslipRepositoryInterface;
use App\Infrastructure\Persistence\InMemoryPayslipRepository;
use PHPUnit\Framework\TestCase;

final class GeneratePayslipHandlerTest extends TestCase
{
    private PayslipRepositoryInterface $repo;

    private GeneratePayslipHandler $handler;

    protected function setUp(): void
    {
        $this->repo = new InMemoryPayslipRepository();

        $this->handler = new GeneratePayslipHandler(
            $this->repo
        );
    }

    public function test_handler_generates_and_saves_payslip(): void
    {
        $command = new GeneratePayslipCommand(
            employeeId: 1,
            payType: 'Monthly',
            basicSalary: 35000.00,
            allowance: 3000.00,

            workingDays: 11,
            daysWorked: 11,
            workedHours: 88,
            absentDays: 0,

            lateRecords: [],
            overtimeHours: 0,
            overtimeMultiplier: 1.25,

            sssRate: 0.045,
            philHealthRate: 0.025,
            pagIbigFixed: 100.00,
            withholdingTaxRate: 0.00,

            payrollPeriodId: null,
        );

        $payslip = $this->handler->handle($command);

        $found = $this->repo->findByEmployeeId(1);

        $this->assertCount(1, $found);
        $this->assertSame(1, $payslip->employeeId());
        $this->assertSame(
            'generated',
            $payslip->status()->value
        );
    }

    public function test_gross_pay_is_correct_for_no_late(): void
    {
        $command = $this->makeCommand(
            salary: 35000.00,
            allowance: 3000.00,
            lateRecords: []
        );

        $payslip = $this->handler->handle($command);

        $this->assertSame(
            '19000.00',
            $payslip->grossPay()->format()
        );
    }

    public function test_late_deduction_bracket_0_no_deduction(): void
    {
        $withoutLate = $this->handler->handle(
            $this->makeCommand(
                salary: 35000.00,
                allowance: 0.00,
                lateRecords: []
            )
        );

        $withLate = $this->handler->handle(
            $this->makeCommand(
                salary: 35000.00,
                allowance: 0.00,
                lateRecords: [
                    ['late_minutes' => 15],
                ]
            )
        );

        $this->assertSame(
            $withoutLate->grossPay()->format(),
            $withLate->grossPay()->format()
        );

        $this->assertSame(
            '0.00',
            $withLate->lateDeduction()->format()
        );
    }

    public function test_late_deduction_bracket_1_half_day(): void
    {
        $withoutLate = $this->handler->handle(
            $this->makeCommand(
                salary: 35000.00,
                allowance: 0.00,
                lateRecords: []
            )
        );

        $withLate = $this->handler->handle(
            $this->makeCommand(
                salary: 35000.00,
                allowance: 0.00,
                lateRecords: [
                    ['late_minutes' => 45],
                ]
            )
        );

        /*
         * Late deductions are deductions, not reductions to gross pay.
         */
        $this->assertSame(
            $withoutLate->grossPay()->format(),
            $withLate->grossPay()->format()
        );

        $this->assertGreaterThan(
            0,
            $withLate->lateDeduction()->centavos()
        );

        $this->assertLessThan(
            $withLate->grossPay()->centavos(),
            $withLate->netPay()->centavos()
        );
    }

    public function test_late_deduction_bracket_2_full_day(): void
    {
        $halfDayLate = $this->handler->handle(
            $this->makeCommand(
                salary: 35000.00,
                allowance: 0.00,
                lateRecords: [
                    ['late_minutes' => 45],
                ]
            )
        );

        $fullDayLate = $this->handler->handle(
            $this->makeCommand(
                salary: 35000.00,
                allowance: 0.00,
                lateRecords: [
                    ['late_minutes' => 75],
                ]
            )
        );

        /*
         * Gross pay remains the same because it contains earnings only.
         */
        $this->assertSame(
            $halfDayLate->grossPay()->format(),
            $fullDayLate->grossPay()->format()
        );

        /*
         * A full-day late bracket must produce a larger deduction.
         */
        $this->assertGreaterThan(
            $halfDayLate->lateDeduction()->centavos(),
            $fullDayLate->lateDeduction()->centavos()
        );

        $this->assertLessThan(
            $fullDayLate->grossPay()->centavos(),
            $fullDayLate->netPay()->centavos()
        );
    }

    public function test_multiple_employees_saved_independently(): void
    {
        $this->handler->handle(
            $this->makeCommand(
                salary: 35000.00,
                employeeId: 1
            )
        );

        $this->handler->handle(
            $this->makeCommand(
                salary: 40000.00,
                employeeId: 2
            )
        );

        $this->handler->handle(
            $this->makeCommand(
                salary: 28000.00,
                employeeId: 3
            )
        );

        $this->assertCount(
            3,
            $this->repo->findAll()
        );

        $this->assertCount(
            1,
            $this->repo->findByEmployeeId(1)
        );

        $this->assertCount(
            1,
            $this->repo->findByEmployeeId(2)
        );

        $this->assertCount(
            1,
            $this->repo->findByEmployeeId(3)
        );
    }

    public function test_payslip_can_be_finalized_after_generation(): void
    {
        $payslip = $this->handler->handle(
            $this->makeCommand(
                salary: 35000.00
            )
        );

        $payslip->finalize();

        $this->assertSame(
            'finalized',
            $payslip->status()->value
        );
    }

    public function test_payslip_can_be_marked_paid_after_finalization(): void
    {
        $payslip = $this->handler->handle(
            $this->makeCommand(
                salary: 35000.00
            )
        );

        $payslip->finalize();
        $payslip->markPaid();

        $this->assertSame(
            'paid',
            $payslip->status()->value
        );
    }

    private function makeCommand(
        float $salary,
        float $allowance = 0.00,
        array $lateRecords = [],
        int $employeeId = 1
    ): GeneratePayslipCommand {
        return new GeneratePayslipCommand(
            employeeId: $employeeId,
            payType: 'Monthly',
            basicSalary: $salary,
            allowance: $allowance,

            workingDays: 11,
            daysWorked: 11,
            workedHours: 88,
            absentDays: 0,

            lateRecords: $lateRecords,
            overtimeHours: 0,
            overtimeMultiplier: 1.25,

            sssRate: 0.045,
            philHealthRate: 0.025,
            pagIbigFixed: 100.00,
            withholdingTaxRate: 0.00,

            payrollPeriodId: null,
        );
    }
}
