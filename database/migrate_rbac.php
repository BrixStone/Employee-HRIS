<?php
/**
 * RBAC database migration (safe to run any number of times).
 * ==========================================================
 *
 *   1. Creates the `role_permissions` table (the permission matrix).
 *   2. Fills in the default matrix. Rows that already exist are kept, so edits
 *      you made in phpMyAdmin are not overwritten.
 *   3. Adds `employees.manager_id` ("reports to"), which defines a manager's team.
 *
 * Run it against your Docker database:
 *   docker exec hris_app php /app/database/migrate_rbac.php
 *   docker exec hris_app php /app/database/migrate_rbac.php --reset   # restore the default matrix
 *
 * It also runs automatically at the end of database/seed_sample_data.php
 * (the hris-seeder container), so a fresh database gets it too.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php'; // $pdo
require_once __DIR__ . '/../classes/Rbac.php';

$reset = in_array('--reset', $argv ?? [], true);
$say = static function (string $m): void { fwrite(STDOUT, $m . "\n"); };

try {
    // ---- 1. permission matrix table -------------------------------------
    $levels = "'" . implode("','", array_keys(Rbac::LEVELS)) . "'";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS role_permissions (
            role       VARCHAR(30) NOT NULL,
            module     VARCHAR(50) NOT NULL,
            access     VARCHAR(30) NOT NULL,
            updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (role, module),
            CONSTRAINT chk_role_permissions_access CHECK (access IN ({$levels}))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $say('role_permissions table: ready');

    // ---- 2. default matrix ----------------------------------------------
    $verb = $reset ? 'REPLACE' : 'INSERT IGNORE';
    $put = $pdo->prepare("{$verb} INTO role_permissions (role, module, access) VALUES (:r, :m, :a)");
    $written = 0;
    foreach (Rbac::DEFAULTS as $role => $modules) {
        foreach ($modules as $module => $access) {
            $put->execute(['r' => $role, 'm' => $module, 'a' => $access]);
            $written += $put->rowCount() > 0 ? 1 : 0;
        }
    }
    $say($reset
        ? "default matrix restored ({$written} rows written)"
        : "default matrix: {$written} new row(s) added, existing rows left as they are");

    // ---- 3. employees.manager_id ----------------------------------------
    $exists = static function (string $sql, array $params) use ($pdo): bool {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn() > 0;
    };

    if (!$exists(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'manager_id'",
        []
    )) {
        $pdo->exec('ALTER TABLE employees ADD COLUMN manager_id BIGINT NULL AFTER position_id');
        $say('employees.manager_id: column added');
    } else {
        $say('employees.manager_id: already there');
    }

    if (!$exists(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND INDEX_NAME = 'idx_employees_manager'",
        []
    )) {
        $pdo->exec('ALTER TABLE employees ADD INDEX idx_employees_manager (manager_id)');
        $say('employees.manager_id: index added');
    }

    if (!$exists(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND CONSTRAINT_NAME = 'fk_employee_manager'",
        []
    )) {
        // If a manager's employee row is deleted, their reports simply have no manager.
        $pdo->exec(
            'ALTER TABLE employees ADD CONSTRAINT fk_employee_manager
             FOREIGN KEY (manager_id) REFERENCES employees (id) ON UPDATE CASCADE ON DELETE SET NULL'
        );
        $say('employees.manager_id: foreign key added');
    }

    $say('RBAC migration complete.');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'RBAC migration FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
