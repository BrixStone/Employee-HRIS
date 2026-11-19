<?php
// api/hr_cron_notifications.php
define('HRIS_DB_THROW_ON_FAIL', true);
require_once __DIR__ . '/../authentication/guard.php';
require_access('dashboard', 'view', null, ['admin', 'hr']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    require_once __DIR__ . '/../config/database.php';
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable', 'notifications' => []]);
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database not ready', 'notifications' => []]);
    exit;
}

$logFile = sys_get_temp_dir() . '/hris_cron_notifications.json';

function loadCronLog(string $file): array {
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function saveCronLog(string $file, array $entries): void {
    @file_put_contents($file, json_encode(array_slice($entries, 0, 20), JSON_PRETTY_PRINT));
}

function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function hasColumn(PDO $pdo, string $table, string $col): bool {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
            return false;
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($col));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'list')));
$cronEntries = loadCronLog($logFile);

// Execute cron job logic (e.g., auto-close stale shifts / sync daily leave & attendance status)
if ($action === 'run_cron') {
    $closedShifts = 0;
    $activeLeavesToday = 0;
    $pendingLeaves = 0;

    try {
        if (tableExists($pdo, 'attendance') && hasColumn($pdo, 'attendance', 'time_in') && hasColumn($pdo, 'attendance', 'time_out')) {
            // Auto-close open shifts from previous days so daily attendance stays clean
            $stmt = $pdo->prepare("
                UPDATE `attendance`
                SET `time_out` = CONCAT(DATE(`time_in`), ' 18:00:00')
                WHERE `time_in` IS NOT NULL
                  AND `time_out` IS NULL
                  AND DATE(`time_in`) < CURRENT_DATE()
            ");
            $stmt->execute();
            $closedShifts = (int)$stmt->rowCount();
        }
    } catch (Throwable $e) {}

    try {
        if (tableExists($pdo, 'leave_requests') && hasColumn($pdo, 'leave_requests', 'status')) {
            $pendingLeaves = (int)$pdo->query("
                SELECT COUNT(*) FROM `leave_requests` WHERE LOWER(COALESCE(`status`, '')) = 'pending'
            ")->fetchColumn();

            if (hasColumn($pdo, 'leave_requests', 'start_date') && hasColumn($pdo, 'leave_requests', 'end_date')) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT `employee_id`)
                    FROM `leave_requests`
                    WHERE LOWER(COALESCE(`status`, '')) = 'approved'
                      AND CURRENT_DATE() BETWEEN DATE(`start_date`) AND DATE(`end_date`)
                ");
                $stmt->execute();
                $activeLeavesToday = (int)$stmt->fetchColumn();
            }
        }
    } catch (Throwable $e) {}

    $entry = [
        'id'         => 'cron_' . uniqid(),
        'category'   => 'cron',
        'title'      => 'Daily HR Cron Job Completed',
        'message'    => "Closed {$closedShifts} stale shift(s) • {$activeLeavesToday} on approved leave today • {$pendingLeaves} pending request(s).",
        'time'       => date('M j, g:i A'),
        'created_at' => date('c'),
    ];
    array_unshift($cronEntries, $entry);
    saveCronLog($logFile, $cronEntries);
}

$notifications = [];

// 1. Pending leave requests alert
try {
    if (tableExists($pdo, 'leave_requests') && hasColumn($pdo, 'leave_requests', 'status')) {
        $pendingCount = (int)$pdo->query("
            SELECT COUNT(*) FROM `leave_requests` WHERE LOWER(COALESCE(`status`, '')) = 'pending'
        ")->fetchColumn();
        if ($pendingCount > 0) {
            $notifications[] = [
                'id'       => 'pending_leaves',
                'category' => 'leave',
                'title'    => "{$pendingCount} Pending Leave Request" . ($pendingCount === 1 ? '' : 's'),
                'message'  => 'Employee leave applications are waiting for HR review.',
                'time'     => 'Live DB',
                'url'      => '/hr/leave_management.php',
            ];
        }
    }
} catch (Throwable $e) {}

// 2. Unclosed shifts from prior days (cron health check)
try {
    if (tableExists($pdo, 'attendance') && hasColumn($pdo, 'attendance', 'time_in') && hasColumn($pdo, 'attendance', 'time_out')) {
        $staleCount = (int)$pdo->query("
            SELECT COUNT(*) FROM `attendance`
            WHERE `time_in` IS NOT NULL AND `time_out` IS NULL AND DATE(`time_in`) < CURRENT_DATE()
        ")->fetchColumn();
        if ($staleCount > 0) {
            $notifications[] = [
                'id'       => 'stale_shifts',
                'category' => 'cron',
                'title'    => "Cron Alert: {$staleCount} Unclosed Past Shift" . ($staleCount === 1 ? '' : 's'),
                'message'  => 'Run the cron job to auto-close forgotten time-outs from earlier dates.',
                'time'     => 'Cron Check',
                'url'      => null,
            ];
        }
    }
} catch (Throwable $e) {}

// 3. Append logged cron job executions
foreach ($cronEntries as $c) {
    $notifications[] = [
        'id'       => $c['id'],
        'category' => 'cron',
        'title'    => $c['title'],
        'message'  => $c['message'],
        'time'     => $c['time'],
        'url'      => null,
    ];
}

// Default informational cron item if empty so the popover always shows cron status
if (empty($notifications)) {
    $notifications[] = [
        'id'       => 'cron_idle',
        'category' => 'cron',
        'title'    => 'Cron Scheduler Ready',
        'message'  => 'Automated daily check for attendance shifts, approved leaves, and pending requests.',
        'time'     => 'Scheduled',
        'url'      => null,
    ];
}

echo json_encode([
    'ok'            => true,
    'unread_count'  => count($cronEntries) + (int)($pendingCount ?? 0),
    'notifications' => array_slice($notifications, 0, 8),
]);