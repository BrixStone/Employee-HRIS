<?php

/**
 * authentication/guard.php
 *
 * Page guard for the whole application.
 *
 * require_access() checks:
 * 1. Visitor is logged in.
 * 2. Account is still Active in the database.
 * 3. Account role has not changed since login.
 * 4. Linked employee account is still Active.
 * 5. Account role is allowed to access the page.
 * 6. Permission matrix grants the requested action.
 * 7. Requested data scope matches the effective scope.
 *
 * Save as UTF-8 without BOM.
 * Do not put spaces or HTML before this PHP opening tag.
 */

// Start session only if it is not already active.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Load database connection and RBAC class.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Rbac.php';

/**
 * Where each role lands after login.
 */
function role_home(?string $role): string
{
    switch (Rbac::normalizeRole($role)) {
        case 'admin':
            return '/admin/dashboard.php';

        case 'hr':
            return '/hr/dashboard.php';

        case 'employee':
            return '/employee/dashboard.php';

        default:
            return '/authentication/login.php';
    }
}

/**
 * Get the database connection.
 */
function guard_pdo(): PDO
{
    global $pdo;

    if (!($pdo instanceof PDO)) {
        http_response_code(503);
        exit('Database unavailable.');
    }

    return $pdo;
}

/**
 * Redirect visitor to login.
 */
function guard_to_login(): never
{
    header('Location: /authentication/login.php');
    exit;
}

/**
 * Destroy the current session and redirect to login.
 */
function guard_logout(): never
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
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
    }

    guard_to_login();
}

/**
 * Display a 403 Forbidden page.
 */
function guard_forbidden(
    ?string $role,
    string $message = 'You do not have permission to open this page.'
): never {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');

    $home = htmlspecialchars(
        role_home($role),
        ENT_QUOTES,
        'UTF-8'
    );

    $msg = htmlspecialchars(
        $message,
        ENT_QUOTES,
        'UTF-8'
    );

    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>403 Forbidden | HRIS</title>
    </head>
    <body style="
        margin:0;
        min-height:100vh;
        display:flex;
        align-items:center;
        justify-content:center;
        background:#0a0514;
        color:#fff;
        font-family:Inter,Arial,sans-serif;
    ">
        <main style="
            max-width:420px;
            padding:32px;
            text-align:center;
        ">
            <p style="
                margin:0;
                font-size:56px;
                font-weight:800;
                color:#f43f5e;
            ">403</p>

            <h1 style="
                margin:8px 0 12px;
                font-size:22px;
            ">Access denied</h1>

            <p style="
                margin:0 0 24px;
                color:#c9c7d1;
                line-height:1.5;
            ">' . $msg . '</p>

            <a href="' . $home . '" style="
                display:inline-block;
                padding:10px 18px;
                border-radius:10px;
                background:#4763ff;
                color:#fff;
                text-decoration:none;
                font-weight:600;
            ">Go back</a>
        </main>
    </body>
    </html>';

    exit;
}

/**
 * Fresh database read of the logged-in account.
 *
 * Returns null when:
 * - User does not exist.
 * - Account is not Active.
 * - Account role has changed since login.
 * - Linked employee record is not Active.
 */
function guard_account(PDO $pdo): ?array
{
    $uid = (int) ($_SESSION['user_id'] ?? 0);

    if ($uid < 1) {
        return null;
    }

    $st = $pdo->prepare("
        SELECT
            u.id,
            u.username,
            u.role,
            u.status,
            u.employee_id,
            e.`rank` AS employee_rank,
            e.department_id
        FROM users u
        LEFT JOIN employees e
            ON e.id = u.employee_id
        WHERE u.id = :id
        LIMIT 1
    ");

    $st->execute([
        'id' => $uid
    ]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    // Verify account status.
    if (
        strcasecmp(
            trim((string) $row['status']),
            'Active'
        ) !== 0
    ) {
        return null;
    }

    // Verify that the role has not changed since login.
    $role = Rbac::normalizeRole($row['role']);

    if (
        $role === '' ||
        $role !== Rbac::normalizeRole($_SESSION['role'] ?? '')
    ) {
        return null;
    }

    // Verify linked employee status and employee record.
    if (function_exists('hris_can_user_login')) {
        $accountCheck = hris_can_user_login($pdo, $row);

        if (empty($accountCheck['allowed'])) {
            error_log(
                'HRIS account guard denied user ID ' .
                $uid . ': ' .
                ($accountCheck['reason'] ?? 'Account not allowed.')
            );

            return null;
        }
    }

    return [
        'user_id' => (int) $row['id'],

        'username' => (string) $row['username'],

        'role' => $role,

        'employee_id' => $row['employee_id'] !== null
            ? (int) $row['employee_id']
            : null,

        'rank' => Rbac::normalizeRank(
            $row['employee_rank'] ?? 'employee'
        ),

        'department_id' => $row['department_id'] !== null
            ? (int) $row['department_id']
            : null,
    ];
}

/**
 * Require a valid login and optionally check permissions.
 *
 * @param string|null $module
 * @param string $ability
 * @param string|null $scope
 * @param string[]|null $roles
 */
function require_access(
    ?string $module = null,
    string $ability = 'view',
    ?string $scope = null,
    ?array $roles = null
): array {

    // Check whether the visitor is logged in.
    if (
        empty($_SESSION['user_id']) ||
        empty($_SESSION['role'])
    ) {
        guard_to_login();
    }

    $pdo = guard_pdo();

    // Verify account directly against MySQL.
    $account = guard_account($pdo);

    if ($account === null) {
        guard_logout();
    }

    $role = $account['role'];

    // Update last activity for the online users widget.
    // config/database.php already handles adding this column.
    try {
        $pdo->prepare("
            UPDATE users
            SET last_activity = NOW()
            WHERE id = ?
        ")->execute([
            $account['user_id']
        ]);

    } catch (Throwable $e) {
        error_log(
            'guard last_activity update failed: ' .
            $e->getMessage()
        );
    }

    // Check allowed portal roles.
    if ($roles !== null) {
        $allowedRoles = array_map(
            [Rbac::class, 'normalizeRole'],
            $roles
        );

        if (!in_array($role, $allowedRoles, true)) {
            guard_forbidden($role);
        }
    }

    // Check module permissions.
    if ($module !== null) {

        // Rank overrides apply to employee accounts.
        $rank = $role === 'employee'
            ? $account['rank']
            : null;

        // Check whether the account can perform the requested action.
        if (
            !Rbac::can(
                $pdo,
                $role,
                $module,
                $ability,
                $rank
            )
        ) {
            guard_forbidden($role);
        }

        // Check data scope if the page requires a specific scope.
        if ($scope !== null) {
            $effectiveScope = Rbac::scope(
                $pdo,
                $role,
                $module,
                $rank,
                $ability
            );

            if ($effectiveScope !== $scope) {
                guard_forbidden(
                    $role,
                    'You do not have the required data access scope for this page.'
                );
            }
        }
    }

    return $account;
}

/**
 * Older name, still used by some pages.
 *
 * Only the specified roles may open the page.
 */
function require_role(array $allowed): void
{
    require_access(
        null,
        'view',
        null,
        $allowed
    );
}

/**
 * Require a specific employee rank.
 */
function require_rank(
    string $rank,
    string $module = 'dashboard',
    string $ability = 'view'
): array {

    $account = require_access(
        null,
        'view',
        null,
        ['employee']
    );

    $actualRank = Rbac::normalizeRank(
        $account['rank'] ?? 'employee'
    );

    if (
        $actualRank !== Rbac::normalizeRank($rank) ||
        !Rbac::rankCan($actualRank, $module, $ability)
    ) {
        guard_forbidden(
            $account['role'],
            'This feature is not available for your rank.'
        );
    }

    return $account;
}

/**
 * Get normalized rank from a verified account.
 */
function account_rank(array $account): string
{
    return Rbac::normalizeRank(
        $account['rank'] ?? 'employee'
    );
}

/**
 * Current session role.
 */
function current_role()
{
    return $_SESSION['role'] ?? null;
}

/**
 * Current session user ID.
 */
function current_user_id()
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * Current session employee ID.
 */
function current_employee_id()
{
    return $_SESSION['employee_id'] ?? null;
}

/**
 * CSRF protection for state-changing forms.
 */

/**
 * Generate or retrieve the session CSRF token.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['csrf'];
}

/**
 * Generate hidden CSRF input field.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' .
        htmlspecialchars(
            csrf_token(),
            ENT_QUOTES,
            'UTF-8'
        ) .
        '">';
}

/**
 * Stop with 403 unless the POSTed form carries this session's token.
 */
function csrf_check(?string $role = null): void
{
    $sent = (string) ($_POST['csrf'] ?? '');

    if (
        $sent === '' ||
        !hash_equals(csrf_token(), $sent)
    ) {
        guard_forbidden(
            $role,
            'This form has expired or is not valid. Go back, reload the page and try again.'
        );
    }
}
