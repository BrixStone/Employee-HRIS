<?php
/**
 * Scheduled job: remind HR about Pending leave, then auto-reject if ignored.
 * ==========================================================================
 *
 * For every leave request still "Pending", based on how many days it has been
 * waiting since it was filed:
 *
 *   - Every --remind-every days (default 3), up to the cut-off, send HR a
 *     reminder. Reminders are logged and pushed in realtime ("leave.reminder")
 *     so HR sees a live toast. A request gets reminders at roughly day 3, 6,
 *     9 and 12 (with the defaults) and never more than once per 3-day mark,
 *     even if the job runs several times.
 *
 *   - At or past --days days (default 14), the request is auto-rejected via
 *     LeaveRequest::reject(), which writes the audit log and fires the usual
 *     realtime "leave.rejected" event to the employee and HR.
 *
 * The job is idempotent and safe to run often (hourly or daily): it only ever
 * touches rows that are still Pending, it never repeats a reminder it already
 * sent, and a request an HR user just approved is skipped automatically.
 *
 * Run it from the command line (see cron/README.md):
 *   php cron/expire_pending_leaves.php                      # remind @3/6/9/12, reject @14
 *   php cron/expire_pending_leaves.php --days=30            # reject cut-off 30 days
 *   php cron/expire_pending_leaves.php --remind-every=7     # remind weekly instead
 *   php cron/expire_pending_leaves.php --dry-run            # show, change nothing
 *
 * Exit codes: 0 = success (even if nothing to do), 1 = a failure occurred.
 */

declare(strict_types=1);

// This script is CLI-only. Refuse to run over the web.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';          // $pdo
require_once __DIR__ . '/../classes/LeaveRequest.php';     // LeaveRequest + LeaveAuditLog

date_default_timezone_set('Asia/Manila');

// ---------------------------------------------------------------- options
$options = getopt('', ['days::', 'remind-every::', 'dry-run', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
    Remind HR about Pending leave requests, then auto-reject stale ones.

      --days=N            Reject requests older than N days (default 14).
      --remind-every=N    Remind HR every N days until the cut-off (default 3).
                          Use 0 to disable reminders (reject only).
      --dry-run           List what would happen; make no changes.
      --help              Show this message.

    TXT);
    exit(0);
}

$rejectDays  = isset($options['days']) ? (int)$options['days'] : 14;
$remindEvery = isset($options['remind-every']) ? (int)$options['remind-every'] : 3;

if ($rejectDays < 1) {
    fwrite(STDERR, "ERROR: --days must be a positive whole number.\n");
    exit(1);
}
if ($remindEvery < 0) {
    fwrite(STDERR, "ERROR: --remind-every cannot be negative (use 0 to disable reminders).\n");
    exit(1);
}
$dryRun = isset($options['dry-run']);

$log = static function (string $message): void {
    // Timestamped, single-line output so it reads well in a cron log file.
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
};

$log(sprintf(
    'pending-leave job start (remind every %s, reject at %d day(s)%s)',
    $remindEvery > 0 ? "{$remindEvery} day(s)" : 'never',
    $rejectDays,
    $dryRun ? ', DRY RUN' : ''
));

try {
    // Every Pending request, with its exact age in whole days since filing.
    $find = $pdo->prepare("
        SELECT lr.id, lr.employee_id, lr.created_at,
               TIMESTAMPDIFF(DAY, lr.created_at, NOW()) AS age_days,
               TRIM(CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,''))) AS employee_name
        FROM leave_requests AS lr
        LEFT JOIN employees AS e ON e.id = lr.employee_id
        WHERE lr.status = 'Pending'
        ORDER BY lr.created_at ASC
    ");
    $find->execute();
    $pending = $find->fetchAll(PDO::FETCH_ASSOC);

    if (!$pending) {
        $log('nothing to do: no Pending requests.');
        $log('pending-leave job done (0 reminded, 0 rejected).');
        exit(0);
    }

    $log('checking ' . count($pending) . ' Pending request(s).');

    $model = new LeaveRequest($pdo);
    $systemActor = [
        'id'    => null,                 // no human user; this is automation
        'name'  => 'System (auto-expiry)',
        'email' => null,
        'role'  => 'system',
    ];

    $reminded = 0;
    $rejected = 0;
    $failed   = 0;

    foreach ($pending as $row) {
        $id    = (int)$row['id'];
        $age   = (int)$row['age_days'];
        $who   = $row['employee_name'] !== '' ? $row['employee_name'] : ('employee #' . (int)$row['employee_id']);
        $label = "request #{$id} ({$who}, {$age} day(s) old)";

        // -------- 1. Reject once the request reaches the cut-off ----------
        if ($age >= $rejectDays) {
            if ($dryRun) {
                $log("would reject {$label}");
                $rejected++;
                continue;
            }
            try {
                if ($model->reject($id, null, $systemActor)) {
                    $log("rejected {$label} -> realtime leave.rejected");
                    $rejected++;
                } else {
                    $log("skipped  {$label} (no longer Pending)");
                }
            } catch (Throwable $e) {
                $failed++;
                $log("FAILED   reject {$label}: " . $e->getMessage());
                error_log('pending-leave job: reject #' . $id . ' failed: ' . $e->getMessage());
            }
            continue;
        }

        // -------- 2. Otherwise, remind HR on each new 3-day mark ----------
        if ($remindEvery <= 0) {
            continue; // reminders disabled
        }

        // How many reminder marks this request has passed (day 3, 6, 9, ...),
        // capped so we never remind on or after the reject cut-off.
        $lastMark = (int)floor(($rejectDays - 1) / $remindEvery) * $remindEvery;
        $marksDue = min((int)floor($age / $remindEvery) * $remindEvery, $lastMark) / $remindEvery;
        $marksDue = (int)max(0, $marksDue);

        $alreadySent = $model->countReminders($id);

        if ($marksDue <= $alreadySent) {
            continue; // not yet time for the next reminder
        }

        $remindersToSend = $marksDue - $alreadySent; // usually 1; catches up if the job was off

        if ($dryRun) {
            $log("would remind HR about {$label} (reminder " . ($alreadySent + 1) . ")");
            $reminded++;
            continue;
        }

        $sent = 0;
        for ($i = 0; $i < $remindersToSend; $i++) {
            $number = $alreadySent + $i + 1;
            $remarks = "Reminder {$number}: this request has been pending for about "
                . ($number * $remindEvery) . " day(s) and will be auto-rejected at {$rejectDays} days.";
            try {
                if ($model->remind($id, $systemActor, $remarks)) {
                    $sent++;
                } else {
                    break; // no longer Pending
                }
            } catch (Throwable $e) {
                $failed++;
                $log("FAILED   remind {$label}: " . $e->getMessage());
                error_log('pending-leave job: remind #' . $id . ' failed: ' . $e->getMessage());
                break;
            }
        }
        if ($sent > 0) {
            $reminded++;
            $log("reminded HR about {$label} (reminder #" . ($alreadySent + $sent) . ") -> realtime leave.reminder");
        }
    }

    $verb = $dryRun ? 'would remind' : 'reminded';
    $verb2 = $dryRun ? 'would reject' : 'rejected';
    $log("pending-leave job done ({$verb} {$reminded}, {$verb2} {$rejected}"
        . ($failed ? ", {$failed} failed" : '') . ').');

    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    $log('FATAL: ' . $e->getMessage());
    error_log('pending-leave job fatal: ' . $e->getMessage());
    exit(1);
}
