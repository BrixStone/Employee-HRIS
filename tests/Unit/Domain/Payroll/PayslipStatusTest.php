<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Payroll;

use App\Domain\Payroll\PayslipStatus;
use PHPUnit\Framework\TestCase;
use ValueError;

final class PayslipStatusTest extends TestCase
{
    // ── Construction from string (matches DB values) ──────────────────

    public function test_can_be_created_from_string_generated(): void
    {
        $status = PayslipStatus::from('generated');
        $this->assertSame('generated', $status->value);
    }

    public function test_can_be_created_from_string_finalized(): void
    {
        $status = PayslipStatus::from('finalized');
        $this->assertSame('finalized', $status->value);
    }

    public function test_can_be_created_from_string_paid(): void
    {
        $status = PayslipStatus::from('paid');
        $this->assertSame('paid', $status->value);
    }

    public function test_invalid_string_throws_value_error(): void
    {
        $this->expectException(ValueError::class);
        PayslipStatus::from('approved'); // not a valid payslip status
    }

    // ── Legal transitions ─────────────────────────────────────────────

    public function test_generated_can_advance_to_finalized(): void
    {
        $status = PayslipStatus::from('generated');
        $next   = $status->advance();
        $this->assertSame('finalized', $next->value);
    }

    public function test_finalized_can_advance_to_paid(): void
    {
        $status = PayslipStatus::from('finalized');
        $next   = $status->advance();
        $this->assertSame('paid', $next->value);
    }

    // ── Illegal transitions ───────────────────────────────────────────

    public function test_paid_cannot_advance(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already paid');
        PayslipStatus::from('paid')->advance();
    }

    // ── State checks ──────────────────────────────────────────────────

    public function test_is_paid_returns_true_only_for_paid(): void
    {
        $this->assertTrue(PayslipStatus::from('paid')->isPaid());
        $this->assertFalse(PayslipStatus::from('finalized')->isPaid());
        $this->assertFalse(PayslipStatus::from('generated')->isPaid());
    }

    public function test_is_editable_returns_true_only_for_generated(): void
    {
        $this->assertTrue(PayslipStatus::from('generated')->isEditable());
        $this->assertFalse(PayslipStatus::from('finalized')->isEditable());
        $this->assertFalse(PayslipStatus::from('paid')->isEditable());
    }

    public function test_label_returns_human_readable_string(): void
    {
        $this->assertSame('Generated', PayslipStatus::from('generated')->label());
        $this->assertSame('Finalized', PayslipStatus::from('finalized')->label());
        $this->assertSame('Paid',      PayslipStatus::from('paid')->label());
    }
}