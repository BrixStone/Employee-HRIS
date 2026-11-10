<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Payroll;

use App\Domain\Payroll\CutOff;
use App\Domain\Payroll\LateDeductionPolicy;
use App\Domain\Payroll\Money;
use App\Domain\Payroll\Payslip;
use App\Domain\Payroll\PayslipRepositoryInterface;
use App\Infrastructure\Persistence\InMemoryPayslipRepository;
use PHPUnit\Framework\TestCase;

final class InMemoryPayslipRepositoryTest extends TestCase
{
    private PayslipRepositoryInterface $repo;
    private CutOff $cutOff;

    protected function setUp(): void
    {
        $this->repo   = new InMemoryPayslipRepository();
        $this->cutOff = CutOff::fromDates('2026-10-01', '2026-10-15');
    }

    // ── save() and findByEmployeeId() ─────────────────────────────────

    public function test_saved_payslip_can_be_retrieved_by_employee_id(): void
    {
        $payslip = $this->makePayslip(employeeId: 1);
        $this->repo->save($payslip);

        $results = $this->repo->findByEmployeeId(1);
        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]->employeeId());
    }

    public function test_find_returns_empty_array_for_unknown_employee(): void
    {
        $results = $this->repo->findByEmployeeId(999);
        $this->assertSame([], $results);
    }

    public function test_multiple_payslips_saved_for_same_employee(): void
    {
        $this->repo->save($this->makePayslip(employeeId: 1));
        $this->repo->save($this->makePayslip(employeeId: 1));

        $results = $this->repo->findByEmployeeId(1);
        $this->assertCount(2, $results);
    }

    public function test_payslips_for_different_employees_do_not_mix(): void
    {
        $this->repo->save($this->makePayslip(employeeId: 1));
        $this->repo->save($this->makePayslip(employeeId: 2));

        $this->assertCount(1, $this->repo->findByEmployeeId(1));
        $this->assertCount(1, $this->repo->findByEmployeeId(2));
    }

    // ── findAll() ─────────────────────────────────────────────────────

    public function test_find_all_returns_every_saved_payslip(): void
    {
        $this->repo->save($this->makePayslip(employeeId: 1));
        $this->repo->save($this->makePayslip(employeeId: 2));
        $this->repo->save($this->makePayslip(employeeId: 3));

        $this->assertCount(3, $this->repo->findAll());
    }

    public function test_find_all_returns_empty_array_when_nothing_saved(): void
    {
        $this->assertSame([], $this->repo->findAll());
    }

    // ── Repository satisfies the interface ───────────────────────────

    public function test_repository_implements_interface(): void
    {
        $this->assertInstanceOf(
            PayslipRepositoryInterface::class,
            $this->repo
        );
    }

    // ── Helper ────────────────────────────────────────────────────────

    private function makePayslip(int $employeeId): Payslip
    {
        return Payslip::compute(
            employeeId:  $employeeId,
            basicSalary: Money::fromPesos(35000.00),
            allowance:   Money::fromPesos(3000.00),
            cutOff:      $this->cutOff,
            lateRecords: [],
            policy:      new LateDeductionPolicy(),
            sssRate:     0.045,
            philRate:    0.025,
            pagIbig:     Money::fromPesos(100.00),
        );
    }
}