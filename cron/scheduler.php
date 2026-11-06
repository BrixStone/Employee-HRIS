<?php
/**
 * Container scheduler: runs the pending-leave job on a fixed interval.
 * ====================================================================
 *
 * This is the long-running process of the "hris-cron" Docker service
 * (monitoring/docker-compose.yml). It replaces the Linux cron daemon, which
 * the php:8.3-cli image does not have:
 *
 *   - runs cron/expire_pending_leaves.php right away on start, then every
 *     CRON_INTERVAL_SECONDS (default 3600 = hourly),
 *   - runs each job in its own PHP process, so a crash in one run cannot
 *     stop the scheduler and every run gets a fresh database connection,
 *   - prints everything to stdout, so `docker logs hris_cron` is the log,
 *   - touches a heartbeat file after each run, which the container's
 *     healthcheck uses to report "healthy" / "unhealthy".
 *
 * Settings (environment variables, set in monitoring/docker-compose.yml):
 *   CRON_INTERVAL_SECONDS  seconds between runs, 10-86400   (default 3600)
 *   CRON_REJECT_DAYS       auto-reject after N days, >= 1    (default 14)
 *   CRON_REMIND_EVERY      remind HR every N days, 0 = off   (default 3)
 *
 * Debugging: `php cron/scheduler.php --once` runs the job a single time and
 * exits with the job's exit code.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Asia/Manila');

const HEARTBEAT_FILE = '/tmp/hris_cron_heartbeat';

$log = static function (string $message): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] scheduler: ' . $message . "\n");
};

/** Read an integer setting from the environment, or stop with a clear error. */
$setting = static function (string $name, int $default, int $min, int $max) use ($log): int {
    $raw = getenv($name);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }
    $raw = trim($raw);
    if (!ctype_digit($raw) || (int)$raw < $min || (int)$raw > $max) {
        fwrite(STDERR, "ERROR: {$name} must be a whole number from {$min} to {$max} (got \"{$raw}\").\n");
        exit(2);
    }
    return (int)$raw;
};

$once        = in_array('--once', $argv, true);
$interval    = $setting('CRON_INTERVAL_SECONDS', 3600, 10, 86400);
$rejectDays  = $setting('CRON_REJECT_DAYS', 14, 1, 3650);
$remindEvery = $setting('CRON_REMIND_EVERY', 3, 0, 3650);

$job = __DIR__ . '/expire_pending_leaves.php';
if (!is_file($job)) {
    fwrite(STDERR, "ERROR: job script not found: {$job}\n");
    exit(2);
}

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($job)
    . ' --days=' . $rejectDays . ' --remind-every=' . $remindEvery;

$log(sprintf(
    'started (every %d s, remind every %s, reject at %d day(s))',
    $interval,
    $remindEvery > 0 ? "{$remindEvery} day(s)" : 'never',
    $rejectDays
));

$run  = 0;
$next = time();

while (true) {
    $run++;
    @touch(HEARTBEAT_FILE);
    $log("run #{$run} starting");

    $exitCode = 0;
    passthru($command, $exitCode);

    $log("run #{$run} finished (exit code {$exitCode}" . ($exitCode === 0 ? '' : ', see errors above') . ')');
    @touch(HEARTBEAT_FILE);

    if ($once) {
        exit($exitCode);
    }

    // Keep a steady rhythm: the next run is one interval after the previous
    // *scheduled* time, so run duration does not make the schedule drift.
    $next += $interval;
    if ($next <= time()) {
        $next = time() + $interval; // fell behind (e.g. laptop slept): do not burst-run
    }
    $log('next run at ' . date('Y-m-d H:i:s', $next));
    sleep(max(1, $next - time()));
}
