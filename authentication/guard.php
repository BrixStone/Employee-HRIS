<?php
// authentication/guard.php
// IMPORTANT: Save this file as UTF-8 (without BOM).
// Do not put spaces, blank lines, or HTML before this opening PHP tag.
//
// Page guard for the whole app. Include it at the very top of a page, then:
//
//   require_access('employee_records', 'view', 'all');
//
// require_access(module, ability, scope, roles) checks, in order:
//   1. the visitor is logged in            (otherwise: redirect to the login page)
//   2. the account is STILL Active in the database and its role has not
//      changed since login                 (otherwise: logged out, to the login page)
//   3. the role may use this page's portal ($roles, optional)
//   4. the permission matrix (table role_permissions, see classes/Rbac.php)
//      grants $ability on $module          (otherwise: 403 page)
//   5. the role's scope on the module equals $scope, when given
//      ('all' = HR/admin pages, 'team' = manager pages, 'own' = employee pages)
//
// It returns the verified account: user_id, username, role (lower case),
// employee_id (or null).

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php'; // $pdo
require_once __DIR__ . '/../classes/Rbac.php';

/** Where each role lands after login (and where the 403 page sends them back). */
function role_home(?string $role): string
{
    switch (Rbac::normalizeRole($role)) {
        case 'admin':
            return '/admin/users.php';
        case 'hr':
        case 'payroll':
            return '/hr/dashboard.php';
        case 'manager':
            return '/manager/dashboard.php';
        case 'employee':
            return '/employee/dashboard.php';
        default:
            return '/authentication/login.php';
    }
}

function guard_pdo(): PDO
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        http_response_code(503);
        exit('Database unavailable.');
    }
    return $pdo;
}

/** Send the visitor to the login page and stop. */
function guard_to_login(): never
{
    header('Location: /authentication/login.php');
    exit;
}

/** End the session (deactivated user, changed role) and go to the login page. */
function guard_logout(): never
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    guard_to_login();
}

/** Plain 403 page with a way back. */
function guard_forbidden(?string $role, string $message = 'You do not have permission to open this page.'): never
{
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $home = htmlspecialchars(role_home($role), ENT_QUOTES, 'UTF-8');
    $msg  = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>403 Forbidden | HRIS</title></head>'
       . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'background:#0a0514;color:#fff;font-family:Inter,Arial,sans-serif">'
       . '<main style="max-width:420px;padding:32px;text-align:center">'
       . '<p style="margin:0;font-size:56px;font-weight:800;color:#f43f5e">403</p>'
       . '<h1 style="margin:8px 0 12px;font-size:22px">Access denied</h1>'
       . '<p style="margin:0 0 24px;color:#c9c7d1;line-height:1.5">' . $msg . '</p>'
       . '<a href="' . $home . '" style="display:inline-block;padding:10px 18px;border-radius:10px;'
       . 'background:#4763ff;color:#fff;text-decoration:none;font-weight:600">Go back</a>'
       . '</main></body></html>';
    exit;
}

/**
 * Fresh database read of the logged-in account. Null when the session is not
 * valid any more: the account is gone or no longer Active, or its role was
 * changed after the user logged in.
 */
function guard_account(PDO $pdo): ?array
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid < 1) {
        return null;
    }

    $st = $pdo->prepare('SELECT id, username, role, status, employee_id FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row || strcasecmp((string)$row['status'], 'Active') !== 0) {
        return null;
    }
    $role = Rbac::normalizeRole($row['role']);
    if ($role === '' || $role !== Rbac::normalizeRole($_SESSION['role'] ?? '')) {
        return null;
    }

    return [
        'user_id'     => (int)$row['id'],
        'username'    => (string)$row['username'],
        'role'        => $role,
        'employee_id' => $row['employee_id'] !== null ? (int)$row['employee_id'] : null,
    ];
}

/**
 * Require a valid login and (optionally) a permission. See the file header.
 *
 * @param string|null $module  Rbac::MODULES key, or null for "any signed-in user"
 * @param string      $ability view | edit | approve | submit
 * @param string|null $scope   all | team | own, or null for any scope
 * @param string[]|null $roles Only these roles may use the page (portal check)
 */
function require_access(?string $module = null, string $ability = 'view', ?string $scope = null, ?array $roles = null): array
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        guard_to_login();
    }

    $pdo = guard_pdo();
    $account = guard_account($pdo);
    if ($account === null) {
        guard_logout();
    }
    $role = $account['role'];

    if ($roles !== null && !in_array($role, array_map([Rbac::class, 'normalizeRole'], $roles), true)) {
        guard_forbidden($role);
    }

    if ($module !== null) {
        if (!Rbac::can($pdo, $role, $module, $ability)) {
            guard_forbidden($role);
        }
        if ($scope !== null && Rbac::scope($pdo, $role, $module) !== $scope) {
            guard_forbidden($role);
        }
    }

    return $account;
}

/** Older name, still used by some pages: only these roles may open the page. */
function require_role(array $allowed): void
{
    require_access(null, 'view', null, $allowed);
}

function current_role()
{
    return $_SESSION['role'] ?? null;
}

function current_user_id()
{
    return $_SESSION['user_id'] ?? null;
}

function current_employee_id()
{
    return $_SESSION['employee_id'] ?? null;
}

// ---- CSRF protection for state-changing forms (admin and manager pages) ----

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Stop with 403 unless the POSTed form carries this session's token. */
function csrf_check(?string $role = null): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        guard_forbidden($role, 'This form has expired or is not valid. Go back, reload the page and try again.');
    }
}
