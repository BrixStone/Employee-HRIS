<?php
/**
 * Linear Search — HRIS
 * Wraps Searching::linearSearch()
 * DSA: O(n)
 */

require_once __DIR__ . '/../classes/DSA/Searching.php';

function linear_search_employees(array $rows, string $q): array
{
    return Searching::linearSearch($rows, $q, [
        'employee_id',
        'first_name',
        'last_name',
        'middle_name',
        'name',
        'email',
        'department_name',
        'position_title',
    ]);
}