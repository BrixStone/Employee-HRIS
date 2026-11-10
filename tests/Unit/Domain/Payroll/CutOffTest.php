<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Payroll;

use App\Domain\Payroll\CutOff;
use PHPUnit\Framework\TestCase;

final class CutOffTest extends TestCase
{
    // ── August 2026 1st cut-off: Aug 1–15 (Sat–Sat) ──────────────────
    // Working days: Mon–Fri only
    // Aug  3,4,5,6,7  → 5
    // Aug 10,11,12,13,14 → 5
    // Total = 10

    public function test_first_half_is_detected(): void
    {
        $cutoff = CutOff::fromDates('2026-08-01', '2026-08-15');
        $this->assertTrue($cutoff->isFirstHalf());
        $this->assertFalse($cutoff->isSecondHalf());
    }

    public function test_second_half_is_detected(): void
    {
        $cutoff = CutOff::fromDates('2026-08-16', '2026-08-31');
        $this->assertFalse($cutoff->isFirstHalf());
        $this->assertTrue($cutoff->isSecondHalf());
    }

    public function test_working_days_august_first_half(): void
    {
        $cutoff = CutOff::fromDates('2026-08-01', '2026-08-15');
        $this->assertSame(10, $cutoff->workingDays());
    }

    public function test_working_days_august_second_half(): void
    {
        // Aug 16(Sun)–31(Mon)
        // Aug 17,18,19,20,21 → 5
        // Aug 24,25,26,27,28 → 5
        // Aug 31             → 1
        // Total = 11
        $cutoff = CutOff::fromDates('2026-08-16', '2026-08-31');
        $this->assertSame(11, $cutoff->workingDays());
    }

    public function test_working_days_october_first_half(): void
    {
        // Oct 1–15 2026 (Thu–Thu)
        // Oct 1,2         → 2
        // Oct 5,6,7,8,9   → 5
        // Oct 12,13,14,15 → 4
        // Total = 11
        $cutoff = CutOff::fromDates('2026-10-01', '2026-10-15');
        $this->assertSame(11, $cutoff->workingDays());
    }

    public function test_start_and_end_are_accessible(): void
    {
        $cutoff = CutOff::fromDates('2026-10-16', '2026-10-31');
        $this->assertSame('2026-10-16', $cutoff->start());
        $this->assertSame('2026-10-31', $cutoff->end());
    }

    public function test_label_format(): void
    {
        $cutoff = CutOff::fromDates('2026-10-01', '2026-10-15');
        $this->assertSame('October 2026 1st Cut-off', $cutoff->label());

        $cutoff2 = CutOff::fromDates('2026-10-16', '2026-10-31');
        $this->assertSame('October 2026 2nd Cut-off', $cutoff2->label());
    }
}