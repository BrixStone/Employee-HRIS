<?php
/**
 * Copy all HRIS data from Supabase (PostgreSQL) into MySQL.
 *
 * Usage (from the project root, MySQL running, schema already imported):
 *     php database/migrate_supabase_to_mysql.php            # refuses if MySQL already has data
 *     php database/migrate_supabase_to_mysql.php --force    # deletes MySQL rows first
 *
 * Source: config/supabase.php (your local, git-ignored Supabase credentials)
 * Target: config/database.php (MySQL)
 *
 * - Keeps the original ids, so foreign keys and the session/user links stay valid.
 * - Converts Supabase UTC timestamps to Philippine time (the MySQL DATETIME
 *   columns store Asia/Manila time).
 * - Copies only tables/columns that exist in both databases.
 * - Runs inside one MySQL transaction: on any error nothing is written.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$force = in_array('--force', $argv, true);

// Copy order respects foreign keys (parents first).
const TABLES = [
    'departments', 'positions', 'employees', 'users', 'leave_types',
    'leave', 'attendance', 'leave_requests', 'leave_balances', 'leave_request_logs',
];

// These timestamptz columns hold Manila wall-clock values written by PHP's
// date() (not real UTC), so they are copied as-is instead of converted.
const KEEP_WALL_CLOCK = ['attendance.break_time'];

define('HRIS_DB_THROW_ON_FAIL', true);

function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}" . PHP_EOL);
    exit(1);
}

/** Connect to Supabase using the credentials in config/supabase.php. */
function connectSource(): PDO
{
    $file = __DIR__ . '/../config/supabase.php';
    if (!is_file($file)) {
        fail('config/supabase.php not found. It holds the Supabase credentials and is git-ignored.');
    }
    try {
        require $file;
        return $pdo;
    } catch (Throwable $e) {
        // The pooler handshake can be slow; retry once with a longer timeout.
        if (!isset($host, $port, $database, $user, $password)) {
            fail('Could not connect to Supabase: ' . $e->getMessage());
        }
        try {
            return new PDO(
                "pgsql:host={$host};port={$port};dbname={$database};connect_timeout=30",
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Throwable $e2) {
            fail('Could not connect to Supabase: ' . $e2->getMessage());
        }
    }
}

function connectTarget(): PDO
{
    try {
        require __DIR__ . '/../config/database.php';
        return $pdo;
    } catch (Throwable $e) {
        fail('Could not connect to MySQL: ' . $e->getMessage()
            . "\nStart MySQL in XAMPP and import database/hris_mysql.sql first.");
    }
}

/** column name (lowercase) => ['name' => real name, 'type' => mysql data type] */
function targetColumns(PDO $my, string $table): array
{
    $st = $my->prepare("
        SELECT column_name AS name, data_type AS type
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = :t
        ORDER BY ordinal_position
    ");
    $st->execute(['t' => $table]);
    $cols = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[strtolower($c['name'])] = ['name' => $c['name'], 'type' => strtolower($c['type'])];
    }
    return $cols;
}

/** column name (lowercase) => real name, for the Postgres table */
function sourceColumns(PDO $pg, string $table): array
{
    $st = $pg->prepare("
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = :t
    ");
    $st->execute(['t' => $table]);
    $cols = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $cols[strtolower($c)] = $c;
    }
    return $cols;
}

function convertValue(mixed $value, string $mysqlType, string $tableColumn): mixed
{
    if ($value === null) {
        return null;
    }
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if (in_array($mysqlType, ['datetime', 'timestamp'], true)) {
        $str = (string)$value;
        // Postgres timestamptz looks like "2026-10-20 08:40:56.375834+00"
        $hasOffset = (bool)preg_match('/([+-]\d{2}(:?\d{2})?|Z)$/', $str);
        if (in_array($tableColumn, KEEP_WALL_CLOCK, true) || !$hasOffset) {
            $str = preg_replace('/([+-]\d{2}(:?\d{2})?|Z)$/', '', $str);
            return (new DateTimeImmutable($str, new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        }
        return (new DateTimeImmutable($str))
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format('Y-m-d H:i:s');
    }
    return $value;
}

// ---------------------------------------------------------------------------

out('Connecting to MySQL...');
$my = connectTarget();
out('Connecting to Supabase...');
$pg = connectSource();

$pgTables = $pg->query("
    SELECT table_name FROM information_schema.tables
    WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
")->fetchAll(PDO::FETCH_COLUMN);

// Safety: never silently merge into a database that already has data.
$nonEmpty = [];
foreach (TABLES as $t) {
    $n = (int)$my->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    if ($n > 0) {
        $nonEmpty[] = "{$t} ({$n})";
    }
}
if ($nonEmpty && !$force) {
    fail("MySQL already has data in: " . implode(', ', $nonEmpty)
        . "\nRe-run with --force to delete it and copy fresh from Supabase.");
}

$my->exec('SET FOREIGN_KEY_CHECKS = 0');
$my->beginTransaction();
try {
    if ($nonEmpty) {
        foreach (array_reverse(TABLES) as $t) {
            $my->exec("DELETE FROM `{$t}`");
        }
        out('Cleared existing MySQL rows.');
    }

    foreach (TABLES as $table) {
        if (!in_array($table, $pgTables, true)) {
            out(sprintf('  %-20s skipped (not in Supabase)', $table));
            continue;
        }

        $tCols = targetColumns($my, $table);
        $sCols = sourceColumns($pg, $table);
        $common = array_values(array_intersect(array_keys($tCols), array_keys($sCols)));
        $dropped = array_diff(array_keys($sCols), $common);

        $select = implode(', ', array_map(fn($c) => '"' . str_replace('"', '""', $sCols[$c]) . '"', $common));
        $rows = $pg->query("SELECT {$select} FROM public.\"{$table}\" ORDER BY 1")->fetchAll(PDO::FETCH_NUM);

        if ($rows) {
            $colList = implode(', ', array_map(fn($c) => '`' . $tCols[$c]['name'] . '`', $common));
            $holders = implode(', ', array_fill(0, count($common), '?'));
            $ins = $my->prepare("INSERT INTO `{$table}` ({$colList}) VALUES ({$holders})");
            foreach ($rows as $row) {
                $vals = [];
                foreach ($common as $i => $c) {
                    $vals[] = convertValue($row[$i], $tCols[$c]['type'], "{$table}.{$c}");
                }
                $ins->execute($vals);
            }
        }

        out(sprintf('  %-20s %d row(s)%s', $table, count($rows),
            $dropped ? ' (columns not in MySQL schema: ' . implode(', ', $dropped) . ')' : ''));
    }

    $my->commit();
} catch (Throwable $e) {
    if ($my->inTransaction()) {
        $my->rollBack();
    }
    $my->exec('SET FOREIGN_KEY_CHECKS = 1');
    fail('Migration failed, nothing was written: ' . $e->getMessage());
}
$my->exec('SET FOREIGN_KEY_CHECKS = 1');

// Report rows whose foreign keys point at missing parents (Supabase may not
// have enforced every constraint that the MySQL schema has).
$orphanChecks = [
    'leave_requests.employee_id -> employees'   => 'SELECT COUNT(*) FROM leave_requests c LEFT JOIN employees p ON p.id = c.employee_id WHERE p.id IS NULL',
    'leave_requests.leave_type_id -> leave_types' => 'SELECT COUNT(*) FROM leave_requests c LEFT JOIN leave_types p ON p.id = c.leave_type_id WHERE p.id IS NULL',
    'users.employee_id -> employees'            => 'SELECT COUNT(*) FROM users c LEFT JOIN employees p ON p.id = c.employee_id WHERE c.employee_id IS NOT NULL AND p.id IS NULL',
    'leave_balances.employee_id -> employees'   => 'SELECT COUNT(*) FROM leave_balances c LEFT JOIN employees p ON p.id = c.employee_id WHERE p.id IS NULL',
];
$problems = 0;
foreach ($orphanChecks as $label => $sql) {
    $n = (int)$my->query($sql)->fetchColumn();
    if ($n > 0) {
        $problems++;
        out("  WARNING: {$n} row(s) in {$label} reference a missing parent row.");
    }
}

out($problems ? 'Done, with warnings above.' : 'Done. All data copied and foreign keys check out.');
