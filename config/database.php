<?php
/**
 * MySQL / MariaDB connection (XAMPP + phpMyAdmin).
 *
 * Drop-in replacement for the old config/supabase.php: it provides the same
 * $pdo object with the same behaviour (exceptions on error, fails fast when
 * the server is down, HRIS_DB_THROW_ON_FAIL support), so pages only need
 *     require_once __DIR__ . '/../config/database.php';
 *
 * Defaults match a stock XAMPP install. Override with environment variables
 * when needed (the Docker metrics exporter does this):
 *     DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *
 * Schema: database/hris_mysql.sql
 */

// All DATETIME columns hold Philippine time. PHP and the MySQL session must
// agree on the zone, otherwise strtotime() on DB values is off by hours.
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
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Fail in a few seconds instead of hanging until PHP's 30s limit
            PDO::ATTR_TIMEOUT => 5,
            // Emulated prepares let a query reuse the same named placeholder
            // (e.g. :year twice), which the code relied on under Postgres.
            PDO::ATTR_EMULATE_PREPARES => true,
            // rowCount() on UPDATE returns matched rows, like Postgres, not
            // only rows whose values actually changed.
            (defined('Pdo\Mysql::ATTR_FOUND_ROWS')
                ? constant('Pdo\Mysql::ATTR_FOUND_ROWS')
                : constant('PDO::MYSQL_ATTR_FOUND_ROWS')) => true,
        ]
    );

    // Philippine time for NOW()/CURRENT_TIMESTAMP (no DST, so a fixed offset
    // works without loading MySQL's time zone tables).
    // Strict mode makes bad data an error, as it was under Postgres, instead
    // of being silently truncated or zeroed.
    $pdo->exec("SET time_zone = '+08:00'");
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
} catch (PDOException $e) {
    // monitoring/metrics.php defines this so it can report hris_db_up 0
    // in Prometheus format instead of the page dying with plain text.
    if (defined('HRIS_DB_THROW_ON_FAIL')) {
        throw $e;
    }
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    die(
        "Database connection failed.\n\n" .
        "Could not connect to MySQL at {$dbHost}:{$dbPort} (database '{$dbName}').\n" .
        "Start MySQL in the XAMPP Control Panel and make sure the database exists\n" .
        "(import database/hris_mysql.sql in phpMyAdmin)."
    );
} finally {
    unset($dbPass);
}
