<?php
/**
 * Quick Sort — HRIS
 * Wraps Sorting::quickSort()
 * DSA: O(n log n) average, O(n²) worst case
 * Uses median-of-three pivot to avoid worst case on sorted input.
 *
 * Used in:
 *   - hr/employees.php  alternate sort for large employee lists
 *   - hr/payroll.php    sort payslips by employee name
 */

require_once __DIR__ . '/../classes/DSA/Sorting.php';

/**
 * Quick sort an array of associative arrays by a string key.
 *
 * @param  array  $data
 * @param  string $key
 * @param  string $dir  'asc' | 'desc'
 * @return array
 */
function quick_sort_employees(array $data, string $key, string $dir = 'asc'): array
{
    return Sorting::quickSort($data, $key, $dir);
}