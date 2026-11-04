<?php
/**
 * Shared helper functions.
 *
 * These used to be copy-pasted into several pages (h(), e(), fullName(),
 * respond()). Keep ONE definition here and `require_once` this file instead.
 * Every function is wrapped in function_exists() so including it twice, or
 * alongside an older page-level definition, can never cause a redeclare error.
 */

if (!function_exists('h')) {
    /** HTML-escape a value for safe output. */
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('e')) {
    /** Alias of h() (some pages use e() as their escape helper). */
    function e($value): string
    {
        return h($value);
    }
}

if (!function_exists('fullName')) {
    /** "First Middle Last" from an employee/user row. */
    function fullName($row): string
    {
        $middle = !empty($row['middle_name']) ? $row['middle_name'] . ' ' : '';
        return trim(($row['first_name'] ?? '') . ' ' . $middle . ($row['last_name'] ?? ''));
    }
}

if (!function_exists('respond')) {
    /** Send a JSON response and stop (used by the api/ endpoints). */
    function respond(int $status, array $data): never
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
