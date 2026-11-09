<?php
/**
 * Rbac: role-based access control for the whole app (pages, API, realtime).
 * ==========================================================================
 *
 * The permission matrix lives in the database table `role_permissions`
 * (one row per role + module), so it can be viewed and edited in phpMyAdmin
 * (http://localhost:8080 -> hris -> role_permissions). Every check reads the
 * table, so an edit takes effect on the next request with no restart.
 *
 *   role      admin | hr | manager | employee
 *   module    dashboard, employee_records, attendance, leave_requests,
 *             payslips, user_management, system_settings
 *   access    one of the LEVELS below
 *
 * Each access level grants some abilities over some scope:
 *
 *   level         scope  abilities
 *   full          all    view, edit, approve, submit
 *   manage        all    view, edit, approve
 *   view          all    view
 *   team          team   view                (manager: their direct reports)
 *   approve_team  team   view, approve
 *   own           own    view, edit          (employee: their own record)
 *   submit_own    own    view, submit
 *   none          -      nothing
 *
 * Safety rules:
 *   - Anything not granted is denied (unknown role, unknown module, typo in
 *     the table, missing row => 'none').
 *   - If the table is missing or empty, the built-in DEFAULTS are used, so a
 *     broken database can never lock everybody out.
 *   - 'admin' always keeps full access to user_management and
 *     system_settings, whatever the table says, so an admin can always repair
 *     a bad edit.
 */
final class Rbac
{
    public const ROLES = ['admin', 'hr', 'manager', 'employee'];

    /** module key => label shown to admins */
    public const MODULES = [
        'dashboard'        => 'Dashboard',
        'employee_records' => 'Employee Records',
        'attendance'       => 'Attendance',
        'leave_requests'   => 'Leave Requests',
        'payslips'         => 'Payslips',
        'user_management'  => 'User Management',
        'system_settings'  => 'System Settings',
    ];

    /** access level => [scope, abilities] */
    public const LEVELS = [
        'full'         => ['scope' => 'all',  'abilities' => ['view', 'edit', 'approve', 'submit']],
        'manage'       => ['scope' => 'all',  'abilities' => ['view', 'edit', 'approve']],
        'view'         => ['scope' => 'all',  'abilities' => ['view']],
        'team'         => ['scope' => 'team', 'abilities' => ['view']],
        'approve_team' => ['scope' => 'team', 'abilities' => ['view', 'approve']],
        'own'          => ['scope' => 'own',  'abilities' => ['view', 'edit']],
        'submit_own'   => ['scope' => 'own',  'abilities' => ['view', 'submit']],
        'none'         => ['scope' => 'none', 'abilities' => []],
    ];

    /** The permission table from the HRIS roles design. */
    public const DEFAULTS = [
        'admin' => [
            'dashboard' => 'full', 'employee_records' => 'full', 'attendance' => 'full',
            'leave_requests' => 'full', 'payslips' => 'full',
            'user_management' => 'full', 'system_settings' => 'full',
        ],
        'hr' => [
            'dashboard' => 'view', 'employee_records' => 'manage', 'attendance' => 'manage',
            'leave_requests' => 'manage', 'payslips' => 'manage',
            'user_management' => 'none', 'system_settings' => 'none',
        ],
        'manager' => [
            'dashboard' => 'view', 'employee_records' => 'team', 'attendance' => 'team',
            'leave_requests' => 'approve_team', 'payslips' => 'none',
            'user_management' => 'none', 'system_settings' => 'none',
        ],
        'employee' => [
            'dashboard' => 'view', 'employee_records' => 'own', 'attendance' => 'own',
            'leave_requests' => 'submit_own', 'payslips' => 'own',
            'user_management' => 'none', 'system_settings' => 'none',
        ],
    ];

    /** Modules an admin can never lose (lock-out protection). */
    private const ADMIN_ALWAYS = ['user_management', 'system_settings'];

    /** @var array<string, array<string, string>>|null per-request cache */
    private static ?array $matrix = null;

    public static function normalizeRole(mixed $role): string
    {
        return strtolower(trim((string)$role));
    }

    /**
     * The whole matrix: role => module => access.
     * Read from the database once per request; DEFAULTS if the table is
     * missing or empty.
     */
    public static function matrix(PDO $pdo): array
    {
        if (self::$matrix !== null) {
            return self::$matrix;
        }

        $rows = [];
        try {
            $rows = $pdo->query('SELECT role, module, access FROM role_permissions')->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable $e) {
            error_log('Rbac: role_permissions unavailable, using defaults: ' . $e->getMessage());
        }

        if (!$rows) {
            return self::$matrix = self::DEFAULTS;
        }

        $matrix = [];
        foreach ($rows as [$role, $module, $access]) {
            $role   = self::normalizeRole($role);
            $module = strtolower(trim((string)$module));
            $access = strtolower(trim((string)$access));
            // An unknown access value is treated as 'none' (fail closed).
            $matrix[$role][$module] = isset(self::LEVELS[$access]) ? $access : 'none';
        }
        return self::$matrix = $matrix;
    }

    /** Forget the cached matrix (tests, or right after editing the table). */
    public static function reset(): void
    {
        self::$matrix = null;
    }

    /** The access level of a role on a module ('none' when not granted). */
    public static function level(PDO $pdo, mixed $role, string $module): string
    {
        $role = self::normalizeRole($role);

        if ($role === 'admin' && in_array($module, self::ADMIN_ALWAYS, true)) {
            return 'full';
        }
        return self::matrix($pdo)[$role][$module] ?? 'none';
    }

    /** 'all' | 'team' | 'own' | 'none' */
    public static function scope(PDO $pdo, mixed $role, string $module): string
    {
        return self::LEVELS[self::level($pdo, $role, $module)]['scope'];
    }

    /** May this role do $ability (view | edit | approve | submit) on $module? */
    public static function can(PDO $pdo, mixed $role, string $module, string $ability): bool
    {
        return in_array($ability, self::LEVELS[self::level($pdo, $role, $module)]['abilities'], true);
    }

    /**
     * employees.id of everyone who reports directly to this manager
     * (employees.manager_id = the manager's employee id).
     *
     * @return int[]
     */
    public static function teamEmployeeIds(PDO $pdo, ?int $managerEmployeeId): array
    {
        if ($managerEmployeeId === null || $managerEmployeeId < 1) {
            return [];
        }
        try {
            $st = $pdo->prepare('SELECT id FROM employees WHERE manager_id = :m ORDER BY id');
            $st->execute(['m' => $managerEmployeeId]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            // manager_id column not created yet (migration not run): no team.
            error_log('Rbac: team lookup failed: ' . $e->getMessage());
            return [];
        }
    }

    public static function isTeamMember(PDO $pdo, ?int $managerEmployeeId, int $employeeId): bool
    {
        return in_array($employeeId, self::teamEmployeeIds($pdo, $managerEmployeeId), true);
    }
}
