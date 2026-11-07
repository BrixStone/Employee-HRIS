<?php
/**
 * Merge Sort — HRIS
 * Wraps Sorting::mergeSort()
 * DSA: O(n log n) always — stable sort
 *
 * Used in:
 *   - hr/reports.php   sort departments by headcount
 *   - hr/payroll.php   sort payslips by net pay
 */

require_once __DIR__ . '/../classes/DSA/Sorting.php';

/**
 * Sort an array of associative arrays by a string key.
 *
 * @param  array  $data
 * @param  string $key
 * @param  string $dir  'asc' | 'desc'
 * @return array
 */
function merge_sort_employees(array $data, string $key, string $dir = 'asc'): array
{
    return Sorting::mergeSort($data, $key, $dir);
}

/**
 * Numeric merge sort — sorts by float value (net_pay, gross_pay etc.)
 * Reuses mergeSort since PHP's <=> spaceship handles floats correctly
 * when stored as strings via strtolower cast.
 * For pure numeric keys pass the numeric column name directly.
 *
 * @param  array  $data
 * @param  string $key
 * @param  string $dir  'asc' | 'desc'
 * @return array
 */
function merge_sort_numeric(array $data, string $key, string $dir = 'asc'): array
{
    return Sorting::mergeSort($data, $key, $dir);
}