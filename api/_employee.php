<?php
/**
 * Validation for employee data written through the API (include only).
 * Used by POST api/employees.php, PATCH api/employee.php and
 * PATCH api/me/profile.php.
 */

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_api.php';

// Same choices as the HR add/edit employee forms and the employees table.
const EMPLOYEE_GENDERS  = ['Male', 'Female', 'Other'];
const EMPLOYEE_TYPES    = ['Full-time', 'Part-time', 'Contract', 'Intern'];
const EMPLOYEE_STATUSES = ['Active', 'Inactive', 'On Leave', 'Suspended'];

/** Columns Employee::update() writes. */
const EMPLOYEE_FIELDS = [
    'employee_id', 'first_name', 'middle_name', 'last_name', 'suffix',
    'date_of_birth', 'gender', 'phone', 'email', 'address',
    'department_id', 'position_id', 'date_hired', 'employment_type', 'employment_status',
];

/** Fields an employee may change on their own profile. */
const EMPLOYEE_SELF_FIELDS = ['phone', 'address'];

function employee_clean_phone(array $input, array &$errors): ?string
{
    $phone = api_clean_string($input, 'phone', 30, $errors);

    if ($phone !== null
        && (!preg_match('/^\+?[0-9 ().\-]+$/', $phone) || preg_match_all('/[0-9]/', $phone) < 7)) {
        $errors['phone'] = 'Use at least 7 digits; spaces, ( ) . - and a leading + are allowed';
        return null;
    }
    return $phone;
}

function employee_clean_address(array $input, array &$errors): ?string
{
    return api_clean_string($input, 'address', 1000, $errors);
}

/**
 * Validate an employee payload from HR/admin.
 *
 * Create ($existing === null): first_name, last_name and date_hired are
 * required, employee_id is generated, and omitted fields get the same
 * defaults as hr/add_employee.php.
 * Update: only the fields present in $input are checked and returned.
 *
 * @return array{0: array<string, mixed>, 1: array<string, string>} [values, errors]
 */
function employee_input(PDO $pdo, array $input, ?array $existing = null): array
{
    $isCreate = $existing === null;
    $errors = [];
    $sent = static fn (string $field): bool => array_key_exists($field, $input);

    $allowed = $isCreate ? array_diff(EMPLOYEE_FIELDS, ['employee_id']) : EMPLOYEE_FIELDS;
    api_reject_unknown($input, $allowed, $errors);

    $values = [
        'employee_id'       => $isCreate ? null : api_clean_string($input, 'employee_id', 20, $errors, $sent('employee_id')),
        'first_name'        => api_clean_string($input, 'first_name', 100, $errors, $isCreate || $sent('first_name')),
        'middle_name'       => api_clean_string($input, 'middle_name', 100, $errors),
        'last_name'         => api_clean_string($input, 'last_name', 100, $errors, $isCreate || $sent('last_name')),
        'suffix'            => api_clean_string($input, 'suffix', 20, $errors),
        'date_of_birth'     => api_clean_date($input, 'date_of_birth', $errors),
        'gender'            => api_clean_enum($input, 'gender', EMPLOYEE_GENDERS, $errors),
        'phone'             => employee_clean_phone($input, $errors),
        'email'             => api_clean_string($input, 'email', 150, $errors),
        'address'           => employee_clean_address($input, $errors),
        'department_id'     => api_clean_id($input, 'department_id', $errors),
        'position_id'       => api_clean_id($input, 'position_id', $errors),
        'date_hired'        => api_clean_date($input, 'date_hired', $errors, $isCreate || $sent('date_hired')),
        'employment_type'   => api_clean_enum($input, 'employment_type', EMPLOYEE_TYPES, $errors, $sent('employment_type')) ?? 'Full-time',
        'employment_status' => api_clean_enum($input, 'employment_status', EMPLOYEE_STATUSES, $errors, $sent('employment_status')) ?? 'Active',
    ];

    if ($values['date_of_birth'] !== null && $values['date_of_birth'] > date('Y-m-d')) {
        $errors['date_of_birth'] = 'Cannot be in the future';
    }
    if ($values['email'] !== null && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Must be a valid email address';
    }

    if ($isCreate) {
        unset($values['employee_id']); // Employee::create() generates EMP-###
    } else {
        $values = array_intersect_key($values, $input);
    }

    // Database checks, only for values that passed the format checks.
    $selfId = $isCreate ? 0 : (int)$existing['id'];

    foreach (['department_id' => 'departments', 'position_id' => 'positions'] as $field => $table) {
        if (!empty($values[$field]) && !isset($errors[$field])) {
            $st = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id LIMIT 1");
            $st->execute(['id' => $values[$field]]);
            if ($st->fetchColumn() === false) {
                $errors[$field] = 'No ' . rtrim($table, 's') . ' with this id';
            }
        }
    }

    // Email doubles as a login name (authentication/login.php), so keep it unique.
    $emailChanged = !empty($values['email'])
        && ($isCreate || strcasecmp((string)$values['email'], (string)($existing['email'] ?? '')) !== 0);
    if ($emailChanged && !isset($errors['email'])) {
        $st = $pdo->prepare("SELECT 1 FROM employees WHERE LOWER(email) = LOWER(:email) AND id <> :id LIMIT 1");
        $st->execute(['email' => $values['email'], 'id' => $selfId]);
        if ($st->fetchColumn() !== false) {
            $errors['email'] = 'Already used by another employee';
        }
    }

    if (!empty($values['employee_id']) && !isset($errors['employee_id'])
        && $values['employee_id'] !== ($existing['employee_id'] ?? null)) {
        $st = $pdo->prepare("SELECT 1 FROM employees WHERE employee_id = :code AND id <> :id LIMIT 1");
        $st->execute(['code' => $values['employee_id'], 'id' => $selfId]);
        if ($st->fetchColumn() !== false) {
            $errors['employee_id'] = 'Already used by another employee';
        }
    }

    return [$values, $errors];
}
