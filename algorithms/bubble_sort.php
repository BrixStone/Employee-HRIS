<?php
/**
 * Bubble Sort — HRIS
 * Wraps Sorting::bubbleSort()
 * DSA: O(n²) worst case, O(n) best case
 */

require_once __DIR__ . '/../classes/DSA/Sorting.php';

function sort_key_value(array $employee, string $key): mixed
{
    return $employee[$key] ?? null;
}

function bubble_sort_employees(array &$arr, string $key, string $dir = 'asc'): void
{
    $arr = Sorting::bubbleSort($arr, $key, $dir);
}