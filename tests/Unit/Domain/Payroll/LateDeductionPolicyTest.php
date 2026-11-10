<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Payroll;

use App\Domain\Payroll\LateDeductionPolicy;
use PHPUnit\Framework\TestCase;

final class LateDeductionPolicyTest extends TestCase
{
    private LateDeductionPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new LateDeductionPolicy();
    }

    // ── Bracket 0: 0–29 minutes late → 0.0 days deducted ────────────

    public function test_zero_minutes_late_deducts_nothing(): void
    {
        $this->assertSame(0.0, $this->policy->deductionDays(0));
    }

    public function test_twenty_nine_minutes_late_deducts_nothing(): void
    {
        $this->assertSame(0.0, $this->policy->deductionDays(29));
    }

    // ── Bracket 1: 30–59 minutes late → 0.5 days deducted ───────────

    public function test_thirty_minutes_late_deducts_half_day(): void
    {
        $this->assertSame(0.5, $this->policy->deductionDays(30));
    }

    public function test_fifty_nine_minutes_late_deducts_half_day(): void
    {
        $this->assertSame(0.5, $this->policy->deductionDays(59));
    }

    // ── Bracket 2: 60+ minutes late → 1.0 day deducted ──────────────

    public function test_sixty_minutes_late_deducts_full_day(): void
    {
        $this->assertSame(1.0, $this->policy->deductionDays(60));
    }

    public function test_ninety_minutes_late_deducts_full_day(): void
    {
        $this->assertSame(1.0, $this->policy->deductionDays(90));
    }

    public function test_very_large_late_minutes_deducts_full_day(): void
    {
        $this->assertSame(1.0, $this->policy->deductionDays(999));
    }

    // ── Accumulation over multiple late days ─────────────────────────

    public function test_total_deduction_days_accumulates_correctly(): void
    {
        // Brandon: 15 min (0.0) + 45 min (0.5) + 75 min (1.0) = 1.5 days
        $records = [
            ['late_minutes' => 15],
            ['late_minutes' => 45],
            ['late_minutes' => 75],
        ];
        $this->assertSame(1.5, $this->policy->totalDeductionDays($records));
    }

    public function test_total_deduction_days_all_on_time(): void
    {
        $records = [
            ['late_minutes' => 0],
            ['late_minutes' => 10],
            ['late_minutes' => 0],
        ];
        $this->assertSame(0.0, $this->policy->totalDeductionDays($records));
    }

    // ── Binary search is actually used (bracket index returned) ──────

    public function test_bracket_index_for_zero_minutes(): void
    {
        // Binary search on thresholds [0, 30, 60] → index 0
        $this->assertSame(0, $this->policy->bracketIndex(0));
    }

    public function test_bracket_index_for_forty_minutes(): void
    {
        // Binary search on thresholds [0, 30, 60] → index 1
        $this->assertSame(1, $this->policy->bracketIndex(40));
    }

    public function test_bracket_index_for_sixty_minutes(): void
    {
        // Binary search on thresholds [0, 30, 60] → index 2
        $this->assertSame(2, $this->policy->bracketIndex(60));
    }
}