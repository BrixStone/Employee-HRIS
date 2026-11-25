<?php

declare(strict_types=1);

/**
 * RBAC database migration.
 *
 * Safe to run multiple times.
 *
 * Responsibilities:
 * 1. Ensure employees.manager_id exists.
 * 2. Ensure the manager index exists.
 * 3. Ensure the manager foreign key exists.
 *
 * RBAC permission tables and default permissions are already
 * created by the main database initialization SQL.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$say = static function (string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
};

try {
    /**
     * Check whether a database object exists.
     */
    $exists = static function (
        string $sql,
        array $params = []
    ) use ($pdo): bool {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    };

    /**
     * 1. Ensure employees.manager_id exists.
     */
    $managerColumnExists = $exists(
        "
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'employees'
          AND COLUMN_NAME = 'manager_id'
        "
    );

    if (!$managerColumnExists) {
        $pdo->exec("
            ALTER TABLE employees
            ADD COLUMN manager_id BIGINT NULL AFTER position_id
        ");

        $say('employees.manager_id: column added');
    } else {
        $say('employees.manager_id: already exists');
    }

    /**
     * 2. Ensure manager index exists.
     */
    $managerIndexExists = $exists(
        "
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'employees'
          AND INDEX_NAME = 'idx_employees_manager'
        "
    );

    if (!$managerIndexExists) {
        $pdo->exec("
            ALTER TABLE employees
            ADD INDEX idx_employees_manager (manager_id)
        ");

        $say('employees.manager_id: index added');
    } else {
        $say('employees.manager_id: index already exists');
    }

    /**
     * 3. Ensure manager foreign key exists.
     */
    $managerForeignKeyExists = $exists(
        "
        SELECT COUNT(*)
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'employees'
          AND CONSTRAINT_NAME = 'fk_employee_manager'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        "
    );

    if (!$managerForeignKeyExists) {
        $pdo->exec("
            ALTER TABLE employees
            ADD CONSTRAINT fk_employee_manager
            FOREIGN KEY (manager_id)
            REFERENCES employees (id)
            ON UPDATE CASCADE
            ON DELETE SET NULL
        ");

        $say('employees.manager_id: foreign key added');
    } else {
        $say('employees.manager_id: foreign key already exists');
    }

    $say('RBAC migration complete.');

    exit(0);

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'RBAC migration FAILED: ' . $e->getMessage() . PHP_EOL
    );

    exit(1);
}
