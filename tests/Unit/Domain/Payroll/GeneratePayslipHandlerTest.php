<?php
declare(strict_types=1);

namespace Tests\Unit\Application\Payroll;

use App\Application\Payroll\GeneratePayslipCommand;
use App\Application\Payroll\GeneratePayslipHandler;
use App\Domain\Payroll\PayslipRepositoryInterface;
use App\Infrastructure\Persistence\InMemoryPayslipRepository;
use PHPUnit\Framework\TestCase;

final class GeneratePayslipHandlerTest extends TestCase
{
    private PayslipRepositoryInterface $repo;
    private GeneratePayslipHandler     $handler;

    protected function setUp(): void
    {
        $this->repo    = new InMemoryPayslipRepository();
        $this->handler = new GeneratePayslipHandler($this->repo);
    }

    // ── Happy path ────────────────────────────────────────────────────

    public function test_handler_generates_and_saves_payslip(): void
    {
        $command = new GeneratePayslipCommand(
            employeeId:    1,
            basicSalary:   35000.00,
            allowance:     3000.00,
            periodStart:   '2026-10-01',
            periodEnd:     '2026-10-15',
            lateRecords:   [],
            sssRate:       0.045,
            philRate:      0.025,
            pagIbigFixed:  100.00,
        );

        $payslip = $this->handler->handle($command);

        // saved in repo
        $found = $this->repo->findByEmployeeId(1);
        $this->assertCount(1, $found);

        // correct employee
        $this->assertSame(1, $payslip->employeeId());

        // starts as generated
        $this->assertSame('generated', $payslip->status()->value);
    }

    public function test_gross_pay_is_correct_for_no_late(): void
    {
        // (35000 + 3000) / 2 = 19000.00
        $command = $this->makeCommand(
            salary: 35000.00,
            allowance: 3000.00,
            lateRecords: []
        );
        $payslip = $this->handler->handle($command);
        $this->assertSame('19000.00', $payslip->grossPay()->format());
    }

    public function test_late_deduction_bracket_0_no_deduction(): void
    {
        // 15 min late → bracket 0 → 0.0 days → gross unchanged
        $without = $this->handler->handle(
            $this->makeCommand(salary: 35000.00, allowance: 0.00, lateRecords: [])
        );
        $with = $this->handler->handle(
            $this->makeCommand(
                salary: 35000.00,
                allowance: 0.00,
                lateRecords: [['late_minutes' => 15]]
            )
        );
        $this->assertSame($without->grossPay()->format(), $with->grossPay()->format());
    }

    public function test_late_deduction_bracket_1_half_day(): void
    {
        // 45 min late → bracket 1 → 0.5 days deducted
        $without = $this->handler->handle(
            $this->makeCommand(salary: 35000.00, allowance: 0.00, lateRecords: [])
        );
        $with = $this->handler->handle(
            $this->makeCommand(
                salary: 35000.00,
                allowance: 0.00,
                lateRecords: [['late_minutes' => 45]]
            )
        );
        // gross must be lower
        $this->assertLessThan(
            $without->grossPay()->centavos(),
            $with->grossPay()->centavos()
        );
    }

    public function test_late_deduction_bracket_2_full_day(): void
    {
        // 75 min late → bracket 2 → 1.0 day deducted
        $half = $this->handler->handle(
            $this->makeCommand(
                salary: 35000.00,
                allowance: 0.00,
                lateRecords: [['late_minutes' => 45]]
            )
        );
        $full = $this->handler->handle(
            $this->makeCommand(
                salary: 35000.00,
                allowance: 0.00,
                lateRecords: [['late_minutes' => 75]]
            )
        );
        // full-day deduction produces lower gross than half-day
        $this->assertLessThan(
            $half->grossPay()->centavos(),
            $full->grossPay()->centavos()
        );
    }

    public function test_multiple_employees_saved_independently(): void
    {
        $this->handler->handle($this->makeCommand(salary: 35000.00, employeeId: 1));
        $this->handler->handle($this->makeCommand(salary: 40000.00, employeeId: 2));
        $this->handler->handle($this->makeCommand(salary: 28000.00, employeeId: 3));

        $this->assertCount(3, $this->repo->findAll());
        $this->assertCount(1, $this->repo->findByEmployeeId(1));
        $this->assertCount(1, $this->repo->findByEmployeeId(2));
        $this->assertCount(1, $this->repo->findByEmployeeId(3));
    }

    public function test_payslip_can_be_finalized_after_generation(): void
    {
        $payslip = $this->handler->handle(
            $this->makeCommand(salary: 35000.00)
        );
        $payslip->finalize();
        $this->assertSame('finalized', $payslip->status()->value);
    }

    public function test_payslip_can_be_marked_paid_after_finalization(): void
    {
        $payslip = $this->handler->handle(
            $this->makeCommand(salary: 35000.00)
        );
        $payslip->finalize();
        $payslip->markPaid();
        $this->assertSame('paid', $payslip->status()->value);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function makeCommand(
        float $salary,
        float $allowance   = 0.00,
        array $lateRecords = [],
        int   $employeeId  = 1,
    ): GeneratePayslipCommand {
        return new GeneratePayslipCommand(
            employeeId:   $employeeId,
            basicSalary:  $salary,
            allowance:    $allowance,
            periodStart:  '2026-10-01',
            periodEnd:    '2026-10-15',
            lateRecords:  $lateRecords,
            sssRate:      0.045,
            philRate:     0.025,
            pagIbigFixed: 100.00,
        );
    }
}