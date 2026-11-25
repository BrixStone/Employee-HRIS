<?php

/**
 * Role-Based Access Control (RBAC)
 *
 * System roles:
 *   admin, hr, employee
 *
 * Employee ranks:
 *   employee, team_leader, manager
 *
 * Permission actions:
 *   view, create, edit, delete, approve, submit
 *
 * Data scopes:
 *   all, department, team, own, none
 *
 * Permission priority:
 *   1. Admin protected access
 *   2. Rank permission override (allow / deny)
 *   3. System role permission
 *   4. Deny by default
 */

final class Rbac
{
    public const ROLES = [
        'admin',
        'hr',
        'employee'
    ];

    public const RANKS = [
        'employee',
        'team_leader',
        'manager'
    ];

    public const ACTIONS = [
        'view',
        'create',
        'edit',
        'delete',
        'approve',
        'submit'
    ];

    public const SCOPES = [
        'all',
        'department',
        'team',
        'own',
        'none'
    ];

    public const MODULES = [
        'dashboard' => 'Dashboard',
        'employee_records' => 'Employee Records',
        'employee_onboarding' => 'Employee Onboarding',
        'recruitment' => 'Recruitment',
        'attendance' => 'Attendance',
        'leave_requests' => 'Leave Requests',
        'payroll' => 'Payroll',
        'payslips' => 'Payslips',
        'promotion_management' => 'Promotion Management',
        'user_management' => 'User Management',
        'audit_logs' => 'Audit Logs',
        'reports' => 'Reports',
        'system_settings' => 'System Settings',
        'backup_management' => 'Backup Management'
    ];

    /**
     * Protected administrator access.
     *
     * These permissions cannot be removed from an admin account
     * through the permission matrix.
     */
    private const ADMIN_ALWAYS = [
        'dashboard',
        'employee_records',
        'employee_onboarding',
        'recruitment',
        'attendance',
        'leave_requests',
        'payroll',
        'payslips',
        'promotion_management',
        'user_management',
        'audit_logs',
        'reports',
        'system_settings'
    ];

    /**
     * Role permission cache.
     *
     * Structure:
     * role => module => action => scope
     */
    private static ?array $matrix = null;

    /**
     * Rank permission cache.
     *
     * Structure:
     * rank => module => action => [effect, scope]
     */
    private static ?array $rankMatrix = null;

    /**
     * Normalize system role.
     */
    public static function normalizeRole(mixed $role): string
    {
        return strtolower(trim((string) $role));
    }


    /**
     * Normalize employee rank.
     */
    public static function normalizeRank(mixed $rank): string
    {
        $rank = strtolower(trim((string) $rank));

        return in_array($rank, self::RANKS, true)
            ? $rank
            : 'employee';
    }

    /**
     * Normalize permission action.
     */
    private static function normalizeAction(string $action): string
    {
        return strtolower(trim($action));
    }

    /**
     * Normalize module key.
     */
    private static function normalizeModule(string $module): string
    {
        return strtolower(trim($module));
    }

    /**
     * Load system role permissions from the database.
     *
     * Tables:
     *   roles
     *   modules
     *   permissions
     *   role_permissions
     */
    public static function matrix(PDO $pdo): array
    {
        if (self::$matrix !== null) {
            return self::$matrix;
        }

        $matrix = [];

        try {
            $sql = "
                SELECT
                    r.role_key,
                    m.module_key,
                    p.permission_key,
                    rp.data_scope

                FROM role_permissions rp

                INNER JOIN roles r
                    ON r.id = rp.role_id

                INNER JOIN modules m
                    ON m.id = rp.module_id

                INNER JOIN permissions p
                    ON p.id = rp.permission_id

                WHERE m.is_active = 1
            ";

            $stmt = $pdo->query($sql);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $role = self::normalizeRole($row['role_key']);
                $module = self::normalizeModule($row['module_key']);
                $action = self::normalizeAction($row['permission_key']);
                $scope = strtolower(trim($row['data_scope']));

                if (
                    !in_array($role, self::ROLES, true) ||
                    !in_array($action, self::ACTIONS, true) ||
                    !in_array($scope, self::SCOPES, true)
                ) {
                    continue;
                }

                $matrix[$role][$module][$action] = $scope;
            }
        } catch (Throwable $e) {
            error_log(
                'RBAC role permission loading failed: ' .
                    $e->getMessage()
            );
        }

        return self::$matrix = $matrix;
    }

    /**
     * Load rank-specific permissions.
     *
     * Tables:
     *   rank_permissions
     *   modules
     *   permissions
     */
    public static function rankPermissions(PDO $pdo): array
    {
        if (self::$rankMatrix !== null) {
            return self::$rankMatrix;
        }

        $matrix = [];

        try {
            $sql = "
                SELECT
                    rp.rank_key,
                    m.module_key,
                    p.permission_key,
                    rp.effect,
                    rp.data_scope

                FROM rank_permissions rp

                INNER JOIN modules m
                    ON m.id = rp.module_id

                INNER JOIN permissions p
                    ON p.id = rp.permission_id

                WHERE m.is_active = 1
            ";

            $stmt = $pdo->query($sql);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rank = self::normalizeRank($row['rank_key']);
                $module = self::normalizeModule($row['module_key']);
                $action = self::normalizeAction($row['permission_key']);
                $effect = strtolower(trim($row['effect']));
                $scope = strtolower(trim($row['data_scope']));

                if (
                    !in_array($action, self::ACTIONS, true) ||
                    !in_array($effect, ['allow', 'deny'], true) ||
                    !in_array($scope, self::SCOPES, true)
                ) {
                    continue;
                }

                $matrix[$rank][$module][$action] = [
                    'effect' => $effect,
                    'scope' => $scope
                ];
            }
        } catch (Throwable $e) {
            error_log(
                'RBAC rank permission loading failed: ' .
                    $e->getMessage()
            );
        }

        return self::$rankMatrix = $matrix;
    }

    /**
     * Clear permission caches.
     *
     * Call this after saving permission changes.
     */
    public static function reset(): void
    {
        self::$matrix = null;
        self::$rankMatrix = null;
    }

    /**
     * Get the effective scope for an account.
     *
     * Rank permissions override system role permissions.
     *
     * Admin always receives all scope.
     */
    public static function scope(
        PDO $pdo,
        mixed $role,
        string $module,
        ?string $rank = null,
        string $ability = 'view'
    ): string {
        $role = self::normalizeRole($role);
        $module = self::normalizeModule($module);
        $ability = self::normalizeAction($ability);

        if (
            $role === 'admin' &&
            in_array($module, self::ADMIN_ALWAYS, true)
        ) {
            return 'all';
        }

        if (
            !in_array($role, self::ROLES, true) ||
            !in_array($ability, self::ACTIONS, true)
        ) {
            return 'none';
        }

        /*
         * Check rank override first.
         */
        if ($rank !== null) {
            $rank = self::normalizeRank($rank);

            $rankPermissions = self::rankPermissions($pdo);

            $override =
                $rankPermissions[$rank][$module][$ability] ?? null;

            if ($override !== null) {
                if ($override['effect'] === 'deny') {
                    return 'none';
                }

                return $override['scope'];
            }
        }

        /*
         * Fall back to system role permission.
         */
        $matrix = self::matrix($pdo);

        return $matrix[$role][$module][$ability] ?? 'none';
    }

    /**
     * Check whether an account can perform an action.
     *
     * Examples:
     *
     * Rbac::can($pdo, 'hr', 'attendance', 'view');
     *
     * Rbac::can(
     *     $pdo,
     *     'employee',
     *     'leave_requests',
     *     'approve',
     *     'manager'
     * );
     */
    public static function can(
        PDO $pdo,
        mixed $role,
        string $module,
        string $ability,
        ?string $rank = null
    ): bool {
        $role = self::normalizeRole($role);
        $module = self::normalizeModule($module);
        $ability = self::normalizeAction($ability);

        if (
            $role === 'admin' &&
            in_array($module, self::ADMIN_ALWAYS, true)
        ) {
            return true;
        }

        if (
            !in_array($role, self::ROLES, true) ||
            !in_array($ability, self::ACTIONS, true)
        ) {
            return false;
        }

        $scope = self::scope(
            $pdo,
            $role,
            $module,
            $rank,
            $ability
        );

        return $scope !== 'none';
    }

    /**
     * Determine whether a module should appear in the sidebar.
     *
     * A module is visible if:
     *   - The module exists and is active.
     *   - The account can view it.
     *   - Its effective scope is not none.
     */
    public static function moduleEnabled(
        PDO $pdo,
        mixed $role,
        string $module,
        ?string $rank = null
    ): bool {
        $role = self::normalizeRole($role);
        $module = self::normalizeModule($module);

        if (!in_array($role, self::ROLES, true)) {
            return false;
        }

        if (!array_key_exists($module, self::MODULES)) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT is_active
                FROM modules
                WHERE module_key = :module
                LIMIT 1
            ");

            $stmt->execute([
                'module' => $module
            ]);

            $active = $stmt->fetchColumn();

            if ($active === false || (int) $active !== 1) {
                return false;
            }
        } catch (Throwable $e) {
            error_log(
                'RBAC module lookup failed: ' .
                    $e->getMessage()
            );

            return false;
        }

        return self::can(
            $pdo,
            $role,
            $module,
            'view',
            $rank
        );
    }

    /**
     * Compatibility method for older code.
     *
     * The old access levels are no longer stored in the database.
     * This method derives a basic level from the new permissions.
     */
    public static function level(
        PDO $pdo,
        mixed $role,
        string $module
    ): string {
        $role = self::normalizeRole($role);

        if (
            $role === 'admin' &&
            in_array($module, self::ADMIN_ALWAYS, true)
        ) {
            return 'full';
        }

        $actions = [];

        foreach (self::ACTIONS as $action) {
            if (self::can($pdo, $role, $module, $action)) {
                $actions[] = $action;
            }
        }

        if (!$actions) {
            return 'none';
        }

        if (in_array('delete', $actions, true)) {
            return 'full';
        }

        if (
            in_array('edit', $actions, true) ||
            in_array('approve', $actions, true) ||
            in_array('create', $actions, true)
        ) {
            return 'manage';
        }

        if (in_array('submit', $actions, true)) {
            return 'submit_own';
        }

        return 'view';
    }

    /**
     * Legacy rank permission helper.
     *
     * Prefer can() with the rank argument for database-driven
     * permissions.
     */
    public static function rankCan(
        string $rank,
        string $module,
        string $ability
    ): bool {
        $legacyPermissions = [
            'employee' => [
                'attendance' => ['view'],
                'leave_requests' => ['view', 'submit'],
                'employee_records' => ['view'],
                'payslips' => ['view'],
                'dashboard' => ['view']
            ],

            'team_leader' => [
                'attendance' => ['view', 'edit'],
                'leave_requests' => ['view', 'approve'],
                'employee_records' => ['view'],
                'promotion_management' => ['view'],
                'dashboard' => ['view'],
                'payslips' => ['view']
            ],

            'manager' => [
                'attendance' => ['view', 'edit'],
                'leave_requests' => ['view', 'approve'],
                'employee_records' => ['view', 'create', 'edit'],
                'promotion_management' => ['view', 'approve'],
                'reports' => ['view'],
                'dashboard' => ['view'],
                'payslips' => ['view']
            ]
        ];

        $rank = self::normalizeRank($rank);
        $module = self::normalizeModule($module);
        $ability = self::normalizeAction($ability);

        return in_array(
            $ability,
            $legacyPermissions[$rank][$module] ?? [],
            true
        );
    }

    /**
     * Get employees directly reporting to a team leader/manager.
     *
     * IMPORTANT:
     * This assumes employees.manager_id stores the supervisor's
     * employees.id. If your My Team feature uses a different
     * relationship, this query must be adjusted to match it.
     *
     * Returns:
     *   int[] employee IDs
     */
    public static function teamEmployeeIds(
        PDO $pdo,
        ?int $managerEmployeeId
    ): array {
        if (
            $managerEmployeeId === null ||
            $managerEmployeeId < 1
        ) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT id
                FROM employees
                WHERE manager_id = :manager_id
                  AND employment_status = 'Active'
                ORDER BY id
            ");

            $stmt->execute([
                'manager_id' => $managerEmployeeId
            ]);

            return array_map(
                'intval',
                $stmt->fetchAll(PDO::FETCH_COLUMN)
            );
        } catch (Throwable $e) {
            error_log(
                'RBAC team lookup failed: ' .
                    $e->getMessage()
            );

            /*
             * Fail closed.
             * Do not accidentally expose the whole department.
             */
            return [];
        }
    }

    /**
     * Check whether an employee belongs to a supervisor's team.
     */
    public static function isTeamMember(
        PDO $pdo,
        ?int $managerEmployeeId,
        int $employeeId
    ): bool {
        if ($employeeId < 1) {
            return false;
        }

        return in_array(
            $employeeId,
            self::teamEmployeeIds($pdo, $managerEmployeeId),
            true
        );
    }

    public static function isSuperAdmin(mixed $role): bool
    {
        return self::normalizeRole($role) === 'admin';
    }
    public static function canManageBackups(mixed $role): bool
    {
        return self::isSuperAdmin($role);
    }
}
