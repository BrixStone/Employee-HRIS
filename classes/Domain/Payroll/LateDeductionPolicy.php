<?php
declare(strict_types=1);

namespace App\Domain\Payroll;

/**
 * Domain service: maps late minutes → deduction days using Binary Search.
 *
 * Brackets (thresholds):
 *   index 0 →   0 ≤ minutes < 30  → 0.0 days
 *   index 1 →  30 ≤ minutes < 60  → 0.5 days
 *   index 2 →  60 ≤ minutes       → 1.0 days
 *
 * Binary search locates which bracket the late minutes fall into.
 * This is the same logic as binarySearchLateDeductionDays() in
 * classes/Payroll.php, promoted into the domain layer so it is
 * independently testable and reusable.
 */
final class LateDeductionPolicy
{
    /** Lower bound of each bracket (must be sorted ascending). */
    private const THRESHOLDS = [0, 30, 60];

    /** Deduction days per bracket index. */
    private const DEDUCTIONS = [0.0, 0.5, 1.0];

    /**
     * Returns the bracket index for a given number of late minutes.
     * Uses Binary Search: O(log n).
     */
    public function bracketIndex(int $lateMinutes): int
    {
        $thresholds = self::THRESHOLDS;
        $lo         = 0;
        $hi         = count($thresholds) - 1;
        $result     = 0;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);

            if ($thresholds[$mid] <= $lateMinutes) {
                $result = $mid;   // this bracket qualifies; try higher
                $lo     = $mid + 1;
            } else {
                $hi = $mid - 1;   // too high; go lower
            }
        }

        return $result;
    }

    /**
     * Returns the number of days to deduct for one attendance record.
     */
    public function deductionDays(int $lateMinutes): float
    {
        return self::DEDUCTIONS[$this->bracketIndex($lateMinutes)];
    }

    /**
     * Accumulates deduction days across multiple attendance records.
     * Each record must have a 'late_minutes' key.
     *
     * @param array<int, array{late_minutes: int}> $records
     */
    public function totalDeductionDays(array $records): float
    {
        $total = 0.0;
        foreach ($records as $record) {
            $total += $this->deductionDays((int) $record['late_minutes']);
        }
        return $total;
    }
}