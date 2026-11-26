<?php
/**
 * Attendance / overtime migration (safe to run any number of times).
 * ===================================================================
 *
 *   1. Adds shift_date, status, regular_hours, overtime_* and
 *      missing_out_flagged_at to `attendance`.
 *   2. Creates `work_shifts` (standard hours, OT grace minutes) with a default
 *      8:00-17:00 / 8h / 15 min grace row, and adds employees.shift_id.
 *   3. Backfills existing rows: closed shifts become COMPLETED.
 *   4. Flags open shifts that are already stale as MISSING_OUT.
 *
 * The same schema step also runs on demand from AttendanceService, so this
 * script is only needed to apply it ahead of time:
 *   docker exec hris_app php /app/database/migrate_attendance.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php'; // $pdo
require_once __DIR__ . '/../classes/AttendanceService.php';

$say = static function (string $m): void { fwrite(STDOUT, $m . "\n"); };

try {
    AttendanceService::ensureSchema($pdo);
    $say('attendance + work_shifts schema: ready');

    $open = $pdo->query("
        SELECT DISTINCT employee_id, email
        FROM attendance
        WHERE time_out IS NULL AND time_in IS NOT NULL
          AND (status IS NULL OR status = 'OPEN')
    ")->fetchAll(PDO::FETCH_ASSOC);

    $flagged = 0;
    foreach ($open as $row) {
        if ($row['employee_id'] === null) {
            continue;
        }
        // Reading the active shift flags every stale one for this employee.
        $before = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE status = 'MISSING_OUT'")->fetchColumn();
        AttendanceService::findActiveShift($pdo, (int)$row['employee_id'], (string)$row['email']);
        $flagged += (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE status = 'MISSING_OUT'")->fetchColumn() - $before;
    }
    $say("stale open shifts flagged as MISSING_OUT: {$flagged}");
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
