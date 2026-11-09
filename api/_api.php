<?php
/**
 * Shared helpers for the JSON endpoints in api/ (include with require_once).
 *
 *   api_methods()          allowed HTTP methods, plus a same-origin check on writes
 *   api_require_role()     signed-in session with one of the given roles (401 / 403)
 *   api_require_account()  the account is still Active in the database
 *   api_require_employee() ... and is linked to an employee record
 *   api_json_body()        JSON request body as an array (415 / 413 / 400)
 *   api_error()            JSON error response
 *   api_clean_*()          body validators that collect per-field errors
 *   api_query_*()          query-string validators (400 on bad input)
 *
 * Authentication is the normal PHP session cookie (PHPSESSID) set by
 * authentication/login.php, so Swagger UI on the same origin works as soon
 * as the user has signed in.
 */

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/helpers.php'; // respond()
require_once __DIR__ . '/../classes/Rbac.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const API_MAX_BODY_BYTES = 65536;

/** Send {"success": false, "message": ...} and stop. */
function api_error(int $status, string $message, array $extra = []): never
{
    respond($status, ['success' => false, 'message' => $message] + $extra);
}

/**
 * Stop with 405 unless the request method is in $methods. Requests that
 * change data must come from this site, so a page on another site cannot
 * use the visitor's session cookie to call the API.
 */
function api_methods(array $methods): string
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if (!in_array($method, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        api_error(405, 'Method not allowed');
    }
    if ($method !== 'GET') {
        api_require_same_origin();
    }
    return $method;
}

function api_require_same_origin(): void
{
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');

    if ($origin === '') {
        // curl and scripts send no Origin (and carry no browser cookies).
        if (strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') {
            api_error(403, 'Cross-site requests are not allowed');
        }
        return;
    }

    $parts = parse_url($origin);
    $originHost = strtolower(
        ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '')
    );
    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));

    if ($originHost === '' || $originHost !== $requestHost) {
        api_error(403, 'Cross-site requests are not allowed');
    }
}

/** Session data, read without holding the session lock. */
function api_session(): array
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $session = $_SESSION;
        session_write_close();
        return $session;
    }
    session_start(['read_and_close' => true]);
    return $_SESSION ?? [];
}

/**
 * Require a signed-in session whose role is in $roles (null = any role).
 * Returns the session with 'role' in lower case.
 */
function api_require_role(?array $roles = null): array
{
    $session = api_session();
    $role = strtolower(trim((string)($session['role'] ?? '')));

    if (empty($session['user_id']) || $role === '') {
        api_error(401, 'Authentication required');
    }
    if ($roles !== null && !in_array($role, $roles, true)) {
        api_error(403, 'Insufficient permissions');
    }

    $session['role'] = $role;
    return $session;
}

/**
 * Re-check the account in the database. A user who was deactivated (or
 * whose role changed) after signing in must not keep using an old session.
 */
function api_require_account(PDO $pdo, array $session): array
{
    $st = $pdo->prepare("
        SELECT u.id AS user_id, u.username, u.role, u.status, u.employee_id,
               e.id AS linked_employee_id, e.first_name, e.last_name, e.email
        FROM users AS u
        LEFT JOIN employees AS e ON e.id = u.employee_id
        WHERE u.id = :id
        LIMIT 1
    ");
    $st->execute(['id' => (int)$session['user_id']]);
    $account = $st->fetch(PDO::FETCH_ASSOC);

    if (!$account || strcasecmp((string)$account['status'], 'Active') !== 0) {
        api_error(401, 'This account is inactive or no longer exists. Please sign in again.');
    }
    if (strtolower(trim((string)$account['role'])) !== $session['role']) {
        api_error(401, 'Your account role has changed. Please sign in again.');
    }

    return [
        'user_id'     => (int)$account['user_id'],
        'username'    => $account['username'],
        'role'        => $session['role'],
        'status'      => $account['status'],
        'employee_id' => $account['linked_employee_id'] !== null ? (int)$account['linked_employee_id'] : null,
        'name'        => trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? '')),
        'email'       => $account['email'],
    ];
}

/** Active account that is linked to an employee record. */
function api_require_employee(PDO $pdo, array $session): array
{
    $account = api_require_account($pdo, $session);

    if ($account['employee_id'] === null) {
        api_error(403, 'No employee record is linked to this account');
    }
    return $account;
}

/**
 * Check the permission matrix (table role_permissions) for this account.
 * Stops with 403 when $ability is not granted on $module, or when the role's
 * scope is not one of $scopes. Returns the scope ('all' | 'team' | 'own') so the
 * caller can narrow the data it returns (a manager only sees their team).
 *
 * @param string[]|null $scopes
 */
function api_require_permission(PDO $pdo, array $account, string $module, string $ability, ?array $scopes = null): string
{
    $scope = Rbac::scope($pdo, $account['role'], $module);

    if (!Rbac::can($pdo, $account['role'], $module, $ability)
        || ($scopes !== null && !in_array($scope, $scopes, true))) {
        api_error(403, 'Insufficient permissions');
    }
    return $scope;
}

/** Decode a JSON object request body. */
function api_json_body(): array
{
    $type = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (strtolower(trim(explode(';', $type)[0])) !== 'application/json') {
        api_error(415, 'Content-Type must be application/json');
    }

    $raw = (string)file_get_contents('php://input', false, null, 0, API_MAX_BODY_BYTES + 1);
    if (strlen($raw) > API_MAX_BODY_BYTES) {
        api_error(413, 'Request body is too large');
    }

    try {
        $data = json_decode(trim($raw) === '' ? '{}' : $raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        api_error(400, 'Request body is not valid JSON');
    }

    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
        api_error(400, 'Request body must be a JSON object');
    }
    return $data;
}

/** True for a MySQL duplicate-key error (unique index violation). */
function api_is_duplicate_key(PDOException $e): bool
{
    return (int)($e->errorInfo[1] ?? 0) === 1062;
}

// ----------------------------------------------------------------------
// Body field validators. Each one records a message in $errors[$field]
// and returns null when the value is invalid.
// ----------------------------------------------------------------------

function api_reject_unknown(array $input, array $allowed, array &$errors): void
{
    foreach (array_diff(array_keys($input), $allowed) as $field) {
        $errors[(string)$field] = 'Unknown or read-only field';
    }
}

/** Trimmed string; an empty string becomes null. */
function api_clean_string(array $input, string $field, int $max, array &$errors, bool $required = false): ?string
{
    $value = $input[$field] ?? null;

    if ($value !== null && !is_string($value)) {
        $errors[$field] = 'Must be a string';
        return null;
    }

    $value = trim((string)$value);
    if ($value === '') {
        if ($required) {
            $errors[$field] = 'This field is required';
        }
        return null;
    }
    if (mb_strlen($value) > $max) {
        $errors[$field] = "Must be at most {$max} characters";
        return null;
    }
    return $value;
}

/** Date in YYYY-MM-DD format. */
function api_clean_date(array $input, string $field, array &$errors, bool $required = false): ?string
{
    $value = api_clean_string($input, $field, 32, $errors, $required);
    if ($value === null) {
        return null;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        $errors[$field] = 'Must be a date in YYYY-MM-DD format';
        return null;
    }
    return $value;
}

/** One of $options (case-insensitive); returns the canonical spelling. */
function api_clean_enum(array $input, string $field, array $options, array &$errors, bool $required = false): ?string
{
    $value = api_clean_string($input, $field, 50, $errors, $required);
    if ($value === null) {
        return null;
    }

    foreach ($options as $option) {
        if (strcasecmp($option, $value) === 0) {
            return $option;
        }
    }
    $errors[$field] = 'Must be one of: ' . implode(', ', $options);
    return null;
}

/** Positive integer id, sent as a JSON number or a string of digits. */
function api_clean_id(array $input, string $field, array &$errors, bool $required = false): ?int
{
    $value = $input[$field] ?? null;

    if ($value === null || $value === '') {
        if ($required) {
            $errors[$field] = 'This field is required';
        }
        return null;
    }

    $id = 0;
    if (is_int($value)) {
        $id = $value;
    } elseif (is_string($value) && ctype_digit($value) && strlen($value) <= 18) {
        $id = (int)$value;
    }

    if ($id < 1) {
        $errors[$field] = 'Must be a positive integer';
        return null;
    }
    return $id;
}

// ----------------------------------------------------------------------
// Query-string validators (stop with 400 on bad input).
// ----------------------------------------------------------------------

/** Optional ?name= limited to $options (case-insensitive). */
function api_query_enum(string $name, array $options): ?string
{
    $raw = $_GET[$name] ?? '';
    $message = "{$name} must be one of: " . implode(', ', $options);

    if (!is_string($raw)) {
        api_error(400, $message);
    }

    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    foreach ($options as $option) {
        if (strcasecmp($option, $raw) === 0) {
            return $option;
        }
    }
    api_error(400, $message);
}

/** Optional ?name= positive integer. */
function api_query_id(string $name): ?int
{
    $raw = $_GET[$name] ?? '';
    if ($raw === '') {
        return null;
    }
    if (!is_string($raw) || !ctype_digit($raw) || strlen($raw) > 18 || (int)$raw < 1) {
        api_error(400, "{$name} must be a positive integer");
    }
    return (int)$raw;
}
