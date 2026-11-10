<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Payroll;

use App\Domain\Payroll\CutOff;
use App\Domain\Payroll\LateDeductionPolicy;
use App\Domain\Payroll\Money;
use App\Domain\Payroll\Payslip;
use App\Domain\Payroll\PayslipStatus;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PayslipTest extends TestCase
{
    private CutOff $cutOff;
    private LateDeductionPolicy $policy;

    protected function setUp(): void
    {
        // October 2026 1st cut-off: 11 working days
        $this->cutOff = CutOff::fromDates('2026-10-01', '2026-10-15');
        $this->policy = new LateDeductionPolicy();
    }

    // ── Construction ──────────────────────────────────────────────────

    public function test_payslip_starts_as_generated(): void
    {
        $payslip = $this->makePayslip(salary: 35000.00, lateRecords: []);
        $this->assertSame('generated', $payslip->status()->value);
    }

    public function test_payslip_stores_employee_id(): void
    {
        $payslip = $this->makePayslip(salary: 35000.00, lateRecords: []);
        $this->assertSame(1, $payslip->employeeId());
    }

    // ── Gross pay ─────────────────────────────────────────────────────

    public function test_gross_pay_is_half_monthly_salary_when_no_late(): void
    {
        // 35000 / 2 = 17500.00
        $payslip = $this->makePayslip(salary: 35000.00, lateRecords: []);
        $this->assertSame('17500.00', $payslip->grossPay()->format());
    }

    public function test_gross_pay_includes_half_allowance(): void
    {
        // (35000 + 3000) / 2 = 19000.00
        $payslip = $this->makePayslip(
            salary: 35000.00,
            lateRecords: [],
            allowance: 3000.00
        );
        $this->assertSame('19000.00', $payslip->grossPay()->format());
    }

    public function test_late_deduction_reduces_gross_pay(): void
    {
        // Oct 1–15: 11 working days
        // daily rate = (35000/2) / 11 = 1590.909...
        // 60 min late → 1.0 day deducted
        // gross = 17500 − 1590.91 = 15909.09
        $payslip = $this->makePayslip(
            salary: 35000.00,
            lateRecords: [['late_minutes' => 60]]
        );
        $daily      = Money::fromPesos(35000.00)->half()->multiplyBy(1 / 11);
        $expected   = Money::fromPesos(35000.00)
                           ->half()
                           ->subtract($daily->multiplyBy(1.0));
        $this->assertSame($expected->format(), $payslip->grossPay()->format());
    }

    // ── Deductions ────────────────────────────────────────────────────

    public function test_sss_deduction_is_half_of_monthly_rate(): void
    {
        // SSS 4.5% of basic salary; semi-monthly = /2
        // 35000 × 0.045 / 2 = 787.50
        $payslip = $this->makePayslip(salary: 35000.00, lateRecords: []);
        $this->assertSame('787.50', $payslip->sssDeduction()->format());
    }

    public function test_philhealth_deduction_is_half_of_monthly_rate(): void
    {
        // PhilHealth 2.5% of basic; semi-monthly = /2
        // 35000 × 0.025 / 2 = 437.50
        $payslip = $this->makePayslip(salary: 35000.00, lateRecords: []);
        $this->assertSame('437.50', $payslip->philHealthDeduction()->format());
    }

    public function test_pagibig_deduction_is_half_of_fixed_100(): void
    {
        // Pag-IBIG fixed 100; semi-monthly = 50
        $payslip = $this->makePayslip(salary: 35000.00, lateRecords: []);
        $this->assertSame('50.00', $payslip->pagIbigDeduction()->format());
    }

    public function test_net_pay_is_gross_minus_all_deductions(): void
    {
        $payslip     = $this->makePayslip(salary: 35000.00, lateRecords: []);
        $expected    = $payslip->grossPay()
                               ->subtract($payslip->sssDeduction())
                               ->subtract($payslip->philHealthDeduction())
                               ->subtract($payslip->pagIbigDeduction());
        $this->assertSame($expected->format(), $payslip->netPay()->format());
    }

    // ── Status transitions ────────────────────────────────────────────

    public function test_payslip_can_be_finalized(): void
    {
        $payslip = $this->makePayslip(salary: 35000.00, lateRecords: []);
        $payslip->finalize();
        $this->assertSame('finalized', $payslip->status()->value);
    }

    public function test_finalized_payslip_can_be_marked_paid(): void
    {
        $payslip = $this->makePayslip(salary: 35000.00, lateRecords: []);
        $payslip->finalize();
        $payslip->markPaid();
        $this->assertSame('paid', $payslip->status()->value);
    }

    public function test_paid_payslip_cannot_be_finalized_again(): void
    {
        $this->expectException(DomainException::class);
        $payslip = $this->makePayslip(salary: 35000.00, lateRecords: []);
        $payslip->finalize();
        $payslip->markPaid();
        $payslip->finalize(); // must throw
    }

    public function test_zero_gross_payslip_cannot_be_finalized(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('zero gross');
        $payslip = $this->makePayslip(salary: 0.00, lateRecords: []);
        $payslip->finalize(); // must throw
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function makePayslip(
        float $salary,
        array $lateRecords,
        float $allowance = 0.00
    ): Payslip {
        return Payslip::compute(
            employeeId:  1,
            basicSalary: Money::fromPesos($salary),
            allowance:   Money::fromPesos($allowance),
            cutOff:      $this->cutOff,
            lateRecords: $lateRecords,
            policy:      $this->policy,
            sssRate:     0.045,
            philRate:    0.025,
            pagIbig:     Money::fromPesos(100.00),
        );
    }
}