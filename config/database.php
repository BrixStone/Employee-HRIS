<?php
/**
 * MySQL / MariaDB connection (XAMPP + phpMyAdmin) + Employee Access Guard.
 * Keeps existing HRIS data intact and blocks Inactive / Suspended employees.
 *
 * Usage:
 *     require_once __DIR__ . '/../config/database.php';
 */

date_default_timezone_set('Asia/Manila');

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'hris';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : '';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT            => 5,
            PDO::ATTR_EMULATE_PREPARES   => true,
            (defined('Pdo\Mysql::ATTR_FOUND_ROWS')
                ? constant('Pdo\Mysql::ATTR_FOUND_ROWS')
                : constant('PDO::MYSQL_ATTR_FOUND_ROWS')) => true,
        ]
    );

    $pdo->exec("SET time_zone = '+08:00'");
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
} catch (PDOException $e) {
    if (defined('HRIS_DB_THROW_ON_FAIL')) {
        throw $e;
    }
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    die(
        "Database connection failed.\n\n" .
        "Could not connect to MySQL at {$dbHost}:{$dbPort} (database '{$dbName}').\n" .
        "Start MySQL in the XAMPP Control Panel and make sure the database exists."
    );
} finally {
    unset($dbPass);
}

if (!function_exists('hris_can_user_login')) {
    /**
     * Checks whether a user account and its linked employee record are Active.
     * Blocks Inactive, Non Active, or Suspended accounts without altering database data.
     *
     * @return array{allowed: bool, reason: string}
     */
    function hris_can_user_login(PDO $pdo, array $userRow): array
    {
        $userStatus = strtolower(trim((string)($userRow['status'] ?? '')));
        if ($userStatus !== '' && $userStatus !== 'active') {
            return [
                'allowed' => false,
                'reason'  => 'Your account status is ' . trim((string)$userRow['status']) . '. Inactive or suspended accounts cannot log in.',
            ];
        }

        $empPk = $userRow['employee_id'] ?? null;
        if ($empPk !== null && $empPk !== '') {
            $stmt = $pdo->prepare('SELECT employment_status FROM employees WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$empPk]);
            $empStatusRaw = (string)($stmt->fetchColumn() ?? '');
            $empStatus    = strtolower(trim($empStatusRaw));

            if ($empStatus !== '' && $empStatus !== 'active') {
                return [
                    'allowed' => false,
                    'reason'  => "Access denied: employee status is {$empStatusRaw}. Inactive or suspended accounts cannot log in.",
                ];
            }
        } elseif (strtolower(trim((string)($userRow['role'] ?? ''))) === 'employee') {
            return [
                'allowed' => false,
                'reason'  => 'Access denied: linked employee record not found.',
            ];
        }

        return ['allowed' => true, 'reason' => ''];
    }
}

if (!function_exists('hris_guard_active_employee')) {
    /**
     * Enforces active status for the currently logged-in session user.
     * Forces logout and redirects to /authentication/login.php if Inactive or Suspended.
     */
    function hris_guard_active_employee(PDO $pdo, string $loginUrl = '/authentication/login.php'): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $sessionUserId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
        if ($sessionUserId === null || $sessionUserId === '') {
            return;
        }

        try {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$sessionUserId]);
            $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

            $check = !$currentUser
                ? ['allowed' => false, 'reason' => 'Your account no longer exists. Please contact HR.']
                : hris_can_user_login($pdo, $currentUser);

            if (!$check['allowed']) {
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(
                        session_name(),
                        '',
                        time() - 42000,
                        $params['path'],
                        $params['domain'],
                        $params['secure'],
                        $params['httponly']
                    );
                }
                session_destroy();

                if (session_status() !== PHP_SESSION_ACTIVE) {
                    session_start();
                }
                $_SESSION['login_error'] = $check['reason'];
                header('Location: ' . $loginUrl);
                exit;
            }
        } catch (Throwable $e) {
            error_log('HRIS active employee guard check failed: ' . $e->getMessage());
        }
    }
}

// ── Self-healing schema patches ───────────────────────────────────────────────
// Add any missing columns / seed data that older databases may not have.
// Each block is safe to run on every request (checks before altering).
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        static $hris_schema_patched = false;
        if (!$hris_schema_patched) {
            $hris_schema_patched = true;
            $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();

            // ── users.last_activity ───────────────────────────────────────
            $hasLastActivity = (bool)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = " . $pdo->quote((string)$dbName) . "
                   AND TABLE_NAME   = 'users'
                   AND COLUMN_NAME  = 'last_activity'"
            )->fetchColumn();
            if (!$hasLastActivity) {
                $pdo->exec("ALTER TABLE users ADD COLUMN last_activity DATETIME DEFAULT NULL");
                $pdo->exec("ALTER TABLE users ADD INDEX idx_users_last_activity (last_activity)");
            }

            // ── RBAC seed: roles, modules, permissions ────────────────────
            // The role_permissions INSERTs in 01-hris.sql use SELECT … FROM
            // roles/modules/permissions — if those tables are empty the whole
            // permission matrix is empty and everyone gets a 403 except admin.
            $rolesEmpty = (int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn() === 0;
            if ($rolesEmpty) {
                $pdo->exec("INSERT IGNORE INTO roles (role_key, role_name, is_protected) VALUES
                    ('admin',    'Administrator', 1),
                    ('hr',       'HR Staff',      1),
                    ('employee', 'Employee',      0)");
            }

            $modulesEmpty = (int)$pdo->query('SELECT COUNT(*) FROM modules')->fetchColumn() === 0;
            if ($modulesEmpty) {
                $pdo->exec("INSERT IGNORE INTO modules (module_key, module_name, is_active, sort_order) VALUES
                    ('dashboard',            'Dashboard',            1,  1),
                    ('employee_records',     'Employee Records',     1,  2),
                    ('employee_onboarding',  'Employee Onboarding',  1,  3),
                    ('recruitment',          'Recruitment',          1,  4),
                    ('attendance',           'Attendance',           1,  5),
                    ('leave_requests',       'Leave Requests',       1,  6),
                    ('payroll',              'Payroll',              1,  7),
                    ('payslips',             'Payslips',             1,  8),
                    ('promotion_management', 'Promotion Management', 1,  9),
                    ('user_management',      'User Management',      1, 10),
                    ('audit_logs',           'Audit Logs',           1, 11),
                    ('reports',              'Reports',              1, 12),
                    ('system_settings',      'System Settings',      1, 13),
                    ('backup_management',    'Backup Management',    1, 14)");
            }

            $permsEmpty = (int)$pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn() === 0;
            if ($permsEmpty) {
                $pdo->exec("INSERT IGNORE INTO permissions (permission_key, permission_name) VALUES
                    ('view',    'View'),
                    ('create',  'Create'),
                    ('edit',    'Edit'),
                    ('delete',  'Delete'),
                    ('approve', 'Approve'),
                    ('submit',  'Submit')");
            }

            // ── role_permissions: seed if empty after roles/modules/perms exist ──
            if ((int)$pdo->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn() === 0) {
                // Admin: full access to everything
                $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, module_id, permission_id, data_scope)
                    SELECT r.id, m.id, p.id, 'all'
                    FROM roles r CROSS JOIN modules m CROSS JOIN permissions p
                    WHERE r.role_key = 'admin'");

                // HR: organisation-wide HR access
                $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, module_id, permission_id, data_scope)
                    SELECT r.id, m.id, p.id, 'all'
                    FROM roles r JOIN modules m JOIN permissions p
                    WHERE r.role_key = 'hr'
                      AND ((m.module_key = 'dashboard'           AND p.permission_key = 'view')
                        OR (m.module_key = 'employee_records'    AND p.permission_key IN ('view','create','edit','delete'))
                        OR (m.module_key = 'employee_onboarding' AND p.permission_key IN ('view','create','edit','delete','approve'))
                        OR (m.module_key = 'recruitment'         AND p.permission_key IN ('view','create','edit','delete','approve'))
                        OR (m.module_key = 'attendance'          AND p.permission_key IN ('view','create','edit'))
                        OR (m.module_key = 'leave_requests'      AND p.permission_key IN ('view','create','edit','approve'))
                        OR (m.module_key = 'payroll'             AND p.permission_key IN ('view','create','edit','approve'))
                        OR (m.module_key = 'payslips'            AND p.permission_key IN ('view','create','edit'))
                        OR (m.module_key = 'promotion_management' AND p.permission_key IN ('view','create','edit','approve'))
                        OR (m.module_key = 'user_management'     AND p.permission_key IN ('view','create','edit'))
                        OR (m.module_key = 'audit_logs'          AND p.permission_key = 'view')
                        OR (m.module_key = 'reports'             AND p.permission_key = 'view')
                        OR (m.module_key = 'system_settings'     AND p.permission_key = 'view'))");

                // Employee: own-data access
                $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, module_id, permission_id, data_scope)
                    SELECT r.id, m.id, p.id, 'own'
                    FROM roles r JOIN modules m JOIN permissions p
                    WHERE r.role_key = 'employee'
                      AND ((m.module_key = 'dashboard'            AND p.permission_key = 'view')
                        OR (m.module_key = 'employee_records'     AND p.permission_key = 'view')
                        OR (m.module_key = 'attendance'           AND p.permission_key IN ('view','submit','edit'))
                        OR (m.module_key = 'leave_requests'       AND p.permission_key IN ('view','submit'))
                        OR (m.module_key = 'payslips'             AND p.permission_key = 'view')
                        OR (m.module_key = 'promotion_management' AND p.permission_key IN ('view','submit')))");
            }
        }
    } catch (Throwable $e) {
        error_log('HRIS schema patch failed: ' . $e->getMessage());
    }
}
