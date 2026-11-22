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