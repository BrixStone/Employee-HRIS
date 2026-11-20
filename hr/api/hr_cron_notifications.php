<?php
// api/hr_cron_notifications.php
// Automatic + persistent HR & Employee notification engine (2+ day unprocessed leaves + payout due).

define('HRIS_DB_THROW_ON_FAIL', true);
require_once __DIR__ . '/../authentication/guard.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$rawRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$isHrOrAdmin = in_array($rawRole, ['admin', 'hr'], true);
$isAllowed = in_array($rawRole, ['employee', 'staff', 'user', 'manager'], true) || $isHrOrAdmin;

if (!$isAllowed) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized', 'notifications' => [], 'unread_count' => 0]);
    exit;
}

$pdo = null;
try {
    require_once __DIR__ . '/../config/database.php';
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable', 'notifications' => [], 'unread_count' => 0]);
    exit;
}

if (!($pdo instanceof PDO)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database not ready', 'notifications' => [], 'unread_count' => 0]);
    exit;
}

$storeFile = sys_get_temp_dir() . '/hris_notifications_store_v3.json';

function hris_load_store(string $file): array {
    $default = [
        'last_auto_check' => 0,
        'last_run_label'  => 'Never',
        'last_summary'    => 'Initializing...',
        'hr_store'        => [],
        'emp_store'       => [],
        'payout_store'    => [],
        'cycles_fired'    => [],
    ];
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? array_merge($default, $decoded) : $default;
}

function hris_save_store(string $file, array $store): void {
    $store['hr_store']     = array_slice(array_values($store['hr_store'] ?? []), 0, 50);
    $store['emp_store']    = array_slice(array_values($store['emp_store'] ?? []), 0, 100);
    $store['payout_store'] = array_slice(array_values($store['payout_store'] ?? []), 0, 20);
    @file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function hris_has_table(PDO $pdo, string $table): bool {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
        $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function hris_has_column(PDO $pdo, string $table, string $col): bool {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $col)) return false;
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($col));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function hris_current_employee_id(PDO $pdo): ?int {
    foreach (['employee_id', 'emp_id', 'staff_id'] as $k) {
        if (!empty($_SESSION[$k]) && is_numeric($_SESSION[$k])) return (int)$_SESSION[$k];
    }
    $uid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
    if ($uid !== null && is_numeric($uid) && hris_has_table($pdo, 'employees')) {
        foreach (['user_id', 'users_id', 'account_id'] as $col) {
            if (hris_has_column($pdo, 'employees', $col)) {
                try {
                    $stmt = $pdo->prepare("SELECT `id` FROM `employees` WHERE `{$col}` = :u LIMIT 1");
                    $stmt->execute([':u' => (int)$uid]);
                    $val = $stmt->fetchColumn();
                    if ($val !== false && is_numeric($val)) return (int)$val;
                } catch (Throwable $e) {}
            }
        }
    }
    return is_numeric($uid) ? (int)$uid : null;
}

function hris_check_payout_due(PDO $pdo): array {
    foreach (['payroll_runs', 'payouts', 'payroll_cycles', 'payroll'] as $tbl) {
        if (!hris_has_table($pdo, $tbl)) continue;
        foreach (['pay_date', 'payout_date', 'payment_date', 'release_date', 'cutoff_end'] as $col) {
            if (!hris_has_column($pdo, $tbl, $col)) continue;
            try {
                if (hris_has_column($pdo, $tbl, 'status')) {
                    $stmt = $pdo->query("
                        SELECT `{$col}` AS due_dt
                        FROM `{$tbl}`
                        WHERE LOWER(COALESCE(`status`, '')) IN ('due', 'pending', 'ready', 'scheduled', 'for_release', 'for release', 'processing')
                          AND DATE(`{$col}`) <= CURRENT_DATE()
                        ORDER BY DATE(`{$col}`) DESC LIMIT 1
                    ");
                } else {
                    $stmt = $pdo->query("SELECT `{$col}` AS due_dt FROM `{$tbl}` WHERE DATE(`{$col}`) = CURRENT_DATE() LIMIT 1");
                }
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['due_dt'])) {
                    return [
                        'due'   => true,
                        'key'   => $tbl . ':' . $row['due_dt'],
                        'label' => date('M j, Y', strtotime((string)$row['due_dt'])),
                    ];
                }
            } catch (Throwable $e) {}
        }
    }

    $today = new DateTimeImmutable('today');
    $day = (int)$today->format('j');
    $lastDay = (int)$today->format('t');
    if ($day === 15 || $day === $lastDay) {
        return [
            'due'   => true,
            'key'   => 'cycle:' . $today->format('Y-m-d'),
            'label' => $today->format('M j, Y'),
        ];
    }

    return ['due' => false, 'key' => '', 'label' => ''];
}

function hris_run_automated_checks(PDO $pdo, array &$store, bool $force = false): void {
    $now = time();
    if (!$force && ($now - (int)($store['last_auto_check'] ?? 0)) < 15) {
        return;
    }

    $store['last_auto_check'] = $now;
    $nowLabel = date('M j, g:i A', $now);
    $overdueCount = 0;
    $payoutCount = 0;

    try {
        if (
            hris_has_table($pdo, 'leave_requests') &&
            hris_has_column($pdo, 'leave_requests', 'status') &&
            hris_has_column($pdo, 'leave_requests', 'created_at')
        ) {
            $idExpr  = hris_has_column($pdo, 'leave_requests', 'id') ? 'lr.`id`' : '0';
            $empExpr = hris_has_column($pdo, 'leave_requests', 'employee_id') ? 'lr.`employee_id`' : 'NULL';
            $typeExpr = hris_has_column($pdo, 'leave_requests', 'leave_type')
                ? "COALESCE(lr.`leave_type`, 'Leave')"
                : (hris_has_column($pdo, 'leave_requests', 'type') ? "COALESCE(lr.`type`, 'Leave')" : "'Leave'");

            $join = '';
            $nameExpr = "CONCAT('Employee #', COALESCE(CAST(lr.`employee_id` AS CHAR), 'Unknown'))";
            if (hris_has_table($pdo, 'employees') && hris_has_column($pdo, 'employees', 'id')) {
                $join = "LEFT JOIN `employees` e ON lr.`employee_id` = e.`id`";
                $fn = hris_has_column($pdo, 'employees', 'first_name') ? "COALESCE(e.`first_name`, '')" : "''";
                $ln = hris_has_column($pdo, 'employees', 'last_name') ? "COALESCE(e.`last_name`, '')" : "''";
                $nameExpr = "TRIM(CONCAT({$fn}, ' ', {$ln}))";
            }

            $sql = "
                SELECT
                    {$idExpr} AS req_id,
                    {$empExpr} AS emp_id,
                    {$typeExpr} AS leave_type,
                    {$nameExpr} AS emp_name,
                    TIMESTAMPDIFF(DAY, lr.`created_at`, NOW()) AS days_pending
                FROM `leave_requests` lr
                {$join}
                WHERE LOWER(COALESCE(lr.`status`, '')) = 'pending'
                  AND TIMESTAMPDIFF(DAY, lr.`created_at`, NOW()) >= 2
            ";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $overdueCount++;
                $reqId = (string)($r['req_id'] ?? uniqid('lr_'));
                $empId = isset($r['emp_id']) && is_numeric($r['emp_id']) ? (int)$r['emp_id'] : null;
                $empName = trim((string)($r['emp_name'] ?? ''));
                if ($empName === '') $empName = $empId ? "Employee #{$empId}" : 'An employee';
                $leaveType = (string)($r['leave_type'] ?? 'Leave');
                $days = max(2, (int)($r['days_pending'] ?? 2));
                $cycle = intdiv($days, 2);
                $cycleKey = "leave2d_{$reqId}_c{$cycle}";

                if (empty($store['cycles_fired'][$cycleKey])) {
                    $store['cycles_fired'][$cycleKey] = $now;

                    array_unshift($store['hr_store'], [
                        'id'       => 'hr_leave2d_' . $reqId . '_c' . $cycle,
                        'category' => 'leave_2d',
                        'title'    => "Unprocessed Leave ({$days}d): {$empName}",
                        'message'  => "{$leaveType} request has not been approved or rejected for {$days} days.",
                        'time'     => $nowLabel,
                        'url'      => '/hr/leave_management.php',
                    ]);

                    if ($empId !== null) {
                        array_unshift($store['emp_store'], [
                            'id'          => 'emp_leave2d_' . $reqId . '_c' . $cycle,
                            'employee_id' => $empId,
                            'category'    => 'leave_2d',
                            'title'       => "{$leaveType} Request Still Pending ({$days}d)",
                            'message'     => "Your {$leaveType} request has not been approved or rejected after {$days} days.",
                            'time'        => $nowLabel,
                            'url'         => '/employee/leave.php',
                        ]);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[HRIS Auto Cron Leave 2d] ' . $e->getMessage());
    }

    $payout = hris_check_payout_due($pdo);
    if ($payout['due']) {
        $pKey = 'payout_due_' . $payout['key'];
        if (empty($store['cycles_fired'][$pKey])) {
            $store['cycles_fired'][$pKey] = $now;
            $payoutCount = 1;
            array_unshift($store['payout_store'], [
                'id'       => 'payout_' . md5($pKey),
                'category' => 'payout',
                'title'    => 'Payout Due — ' . $payout['label'],
                'message'  => 'Payroll payout is due on ' . $payout['label'] . '. Please check your payslip and payout status.',
                'time'     => $nowLabel,
                'url'      => '/employee/payslips.php',
            ]);
        }
    }

    $store['last_run_label'] = $nowLabel;
    $store['last_summary']   = "2d+ Unprocessed Leaves: {$overdueCount} • Payout Due Alerts: {$payoutCount}";
    hris_save_store($storeFile, $store);
}

$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'list')));
$audience = strtolower(trim((string)($_GET['audience'] ?? $_POST['audience'] ?? ($isHrOrAdmin ? 'hr' : 'employee'))));
if ($audience === 'hr' && !$isHrOrAdmin) {
    $audience = 'employee';
}

$store = hris_load_store($storeFile);
hris_run_automated_checks($pdo, $store, $action === 'run_cron');

$notifications = [];
$unreadCount = 0;

if ($audience === 'employee') {
    $empId = hris_current_employee_id($pdo);
    foreach ($store['payout_store'] as $p) {
        $notifications[] = $p;
        $unreadCount++;
    }
    foreach ($store['emp_store'] as $eItem) {
        $targetEmp = isset($eItem['employee_id']) ? (int)$eItem['employee_id'] : null;
        if ($empId === null || $targetEmp === $empId) {
            $notifications[] = $eItem;
            $unreadCount++;
        }
    }
    if (empty($notifications)) {
        $notifications[] = [
            'id'       => 'emp_none',
            'category' => 'info',
            'title'    => 'No New Notifications',
            'message'  => 'You will be notified here if a leave request is pending 2+ days or when payout is due.',
            'time'     => $store['last_run_label'] ?? 'Auto',
            'url'      => null,
        ];
    }
} else {
    foreach ($store['hr_store'] as $hItem) {
        $notifications[] = $hItem;
        if (($hItem['category'] ?? '') === 'leave_2d') {
            $unreadCount++;
        }
    }

    try {
        if (hris_has_table($pdo, 'leave_requests') && hris_has_column($pdo, 'leave_requests', 'status') && hris_has_column($pdo, 'leave_requests', 'created_at')) {
            $live2d = (int)$pdo->query("
                SELECT COUNT(*) FROM `leave_requests`
                WHERE LOWER(COALESCE(`status`, '')) = 'pending'
                  AND TIMESTAMPDIFF(DAY, `created_at`, NOW()) >= 2
            ")->fetchColumn();
            if ($live2d > $unreadCount) {
                $unreadCount = $live2d;
            }
        }
    } catch (Throwable $e) {}

    if (empty($notifications)) {
        $notifications[] = [
            'id'       => 'hr_none',
            'category' => 'info',
            'title'    => 'Auto-Monitor Active',
            'message'  => 'Automatically tracks leave requests unprocessed for 2+ days and payout due alerts.',
            'time'     => $store['last_run_label'] ?? 'Auto',
            'url'      => null,
        ];
    }
}

echo json_encode([
    'ok'            => true,
    'audience'      => $audience,
    'last_run_at'   => $store['last_run_label'] ?? 'Auto',
    'last_summary'  => $store['last_summary'] ?? '',
    'unread_count'  => $unreadCount,
    'notifications' => array_slice($notifications, 0, 12),
], JSON_UNESCAPED_UNICODE);