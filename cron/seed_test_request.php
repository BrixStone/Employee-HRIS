<?php
/**
 * Dev helper: create a backdated Pending leave request to test the
 * pending-leave cron job (cron/expire_pending_leaves.php).
 * ==========================================================================
 *
 * It inserts ONE Pending request whose created_at is set N days in the past,
 * so the cron job sees it as "aged" and will remind HR (or reject it) right
 * away, instead of you waiting real days.
 *
 *   --age=N        How many days old to make it (default 10).
 *                  10 => already past the 3/6/9 reminder marks.
 *                  15 => past the 14-day cut-off, so the cron job rejects it.
 *   --employee=ID  Which employee files it (default 1).
 *   --type=ID      Leave type id (default 1 = Annual Leave).
 *   --clear        Delete ALL test requests created by this script, then exit.
 *   --help         Show this message.
 *
 * Every request it creates is tagged in the reason with "[CRON TEST]" so the
 * --clear option (and you) can find and remove them safely. This is a testing
 * aid only; it never touches real requests.
 *
 * Run it (see cron/README.md):
 *   docker exec hris_app php /app/cron/seed_test_request.php
 *   docker exec hris_app php /app/cron/seed_test_request.php --age=15
 *   docker exec hris_app php /app/cron/seed_test_request.php --clear
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php'; // $pdo

date_default_timezone_set('Asia/Manila');

const TEST_TAG = '[CRON TEST]';

$options = getopt('', ['age::', 'employee::', 'type::', 'clear', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
    Create a backdated Pending leave request to test the cron job.

      --age=N        Make it N days old (default 10). Use 15+ to trigger a reject.
      --employee=ID  Employee who files it (default 1).
      --type=ID      Leave type id (default 1).
      --clear        Delete every request this script created, then exit.
      --help         Show this message.

    TXT);
    exit(0);
}

try {
    // --clear: remove every test request this script ever made.
    if (isset($options['clear'])) {
        // Audit-log rows first (FK-free, but keep the table tidy), then requests.
        $pdo->exec(
            "DELETE l FROM leave_request_logs l
             JOIN leave_requests r ON r.id = l.leave_request_id
             WHERE r.reason LIKE '" . TEST_TAG . "%'"
        );
        $n = $pdo->exec("DELETE FROM leave_requests WHERE reason LIKE '" . TEST_TAG . "%'");
        fwrite(STDOUT, "Removed {$n} test request(s).\n");
        exit(0);
    }

    $age        = isset($options['age']) ? (int)$options['age'] : 10;
    $employeeId = isset($options['employee']) ? (int)$options['employee'] : 1;
    $typeId     = isset($options['type']) ? (int)$options['type'] : 1;

    if ($age < 0) {
        fwrite(STDERR, "ERROR: --age cannot be negative.\n");
        exit(1);
    }

    // Validate the employee and leave type exist, with a friendly message.
    $emp = $pdo->prepare("SELECT TRIM(CONCAT(first_name,' ',last_name)) FROM employees WHERE id = :id");
    $emp->execute(['id' => $employeeId]);
    $employeeName = $emp->fetchColumn();
    if ($employeeName === false) {
        fwrite(STDERR, "ERROR: no employee with id {$employeeId}. Use --employee=ID (see your Employees list).\n");
        exit(1);
    }

    $lt = $pdo->prepare("SELECT leave_name FROM leave_types WHERE id = :id");
    $lt->execute(['id' => $typeId]);
    $typeName = $lt->fetchColumn();
    if ($typeName === false) {
        fwrite(STDERR, "ERROR: no leave type with id {$typeId}. Use --type=ID (1=Annual, 2=Sick, 3=Personal).\n");
        exit(1);
    }

    // Future leave dates so the request is clearly a test, not a real one.
    $start = date('Y-m-d', strtotime('+30 days'));
    $end   = date('Y-m-d', strtotime('+31 days'));
    $reason = TEST_TAG . " seeded {$age}-day-old request for cron testing";

    $ins = $pdo->prepare("
        INSERT INTO leave_requests
            (employee_id, leave_type_id, start_date, end_date, number_of_days, reason, status, created_at)
        VALUES
            (:emp, :type, :start, :end, 2, :reason, 'Pending', DATE_SUB(NOW(), INTERVAL :age DAY))
    ");
    $ins->execute([
        'emp'    => $employeeId,
        'type'   => $typeId,
        'start'  => $start,
        'end'    => $end,
        'reason' => $reason,
        'age'    => $age,
    ]);
    $id = (int)$pdo->lastInsertId();

    $filedAt = date('Y-m-d H:i:s', strtotime("-{$age} days"));

    fwrite(STDOUT, "Created test leave request:\n");
    fwrite(STDOUT, "  id          : {$id}\n");
    fwrite(STDOUT, "  employee    : {$employeeName} (id {$employeeId})\n");
    fwrite(STDOUT, "  leave type  : {$typeName} (id {$typeId})\n");
    fwrite(STDOUT, "  filed       : {$filedAt}  ({$age} day(s) ago)\n");
    fwrite(STDOUT, "  status      : Pending\n\n");

    if ($age >= 14) {
        fwrite(STDOUT, "This is past the 14-day cut-off, so the cron job will REJECT it.\n");
    } elseif ($age >= 3) {
        $marks = (int)floor($age / 3);
        fwrite(STDOUT, "This is {$age} days old, so the cron job will REMIND HR (reminder #{$marks}).\n");
    } else {
        fwrite(STDOUT, "This is under 3 days old, so the cron job will do nothing yet.\n");
    }
    fwrite(STDOUT, "\nNext: run the cron job to act on it:\n");
    fwrite(STDOUT, "  docker exec hris_app php /app/cron/expire_pending_leaves.php --dry-run\n");
    fwrite(STDOUT, "  docker exec hris_app php /app/cron/expire_pending_leaves.php\n");
    fwrite(STDOUT, "Clean up test requests when done:\n");
    fwrite(STDOUT, "  docker exec hris_app php /app/cron/seed_test_request.php --clear\n");

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
