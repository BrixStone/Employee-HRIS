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

if (!function_exists('rank_label')) {
    /** Display name of an employee rank (employee | team_leader | manager). */
    function rank_label($rank): string
    {
        switch (strtolower(trim((string)($rank ?? '')))) {
            case 'manager':
                return 'Manager';
            case 'team_leader':
                return 'Team Leader';
            default:
                return 'Employee';
        }
    }
}

if (!function_exists('rank_badge')) {
    /** Small coloured badge that shows an employee's rank. Safe to echo. */
    function rank_badge($rank): string
    {
        $key = strtolower(trim((string)($rank ?? '')));
        $colors = [
            'manager'     => 'background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.4)',
            'team_leader' => 'background:rgba(71,99,255,.15);color:#8da0ff;border:1px solid rgba(71,99,255,.4)',
        ];
        $style = $colors[$key] ?? 'background:rgba(148,163,184,.15);color:#94a3b8;border:1px solid rgba(148,163,184,.35)';

        return '<span class="rank-badge" style="display:inline-block;padding:2px 10px;border-radius:999px;'
            . 'font-size:12px;font-weight:600;white-space:nowrap;' . $style . '">'
            . h(rank_label($rank)) . '</span>';
    }
}
