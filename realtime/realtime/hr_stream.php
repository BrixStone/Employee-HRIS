<?php
// realtime/hr_stream.php
require_once __DIR__ . '/../authentication/guard.php';
require_access('dashboard', 'view', null, ['admin', 'hr']);

require_once __DIR__ . '/../config/database.php';

function buildDashboardPayload(?PDO $pdo): array {
    $payload = [
        'kpis' => [
            'total_employees'  => 0,
            'present_today'    => 0,
            'on_leave'         => 0,
            'pending_requests' => 0,
        ],
        'overview' => [
            'regular'      => 0,
            'probationary' => 0,
            'contractual'  => 0,
            'total'        => 0,
        ],
        'recent_activities' => [],
        'upcoming_events'   => [],
        'timestamp'         => date('h:i:s A'),
        'source'            => 'docker-db',
    ];

    if (!($pdo instanceof PDO)) {
        return $payload;
    }

    // 1. Total Employees & Employment Type Breakdown
    try {
        $total = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
        $payload['kpis']['total_employees'] = $total;
        $payload['overview']['total'] = $total;

        $stmt = $pdo->query("
            SELECT
                SUM(CASE WHEN LOWER(COALESCE(employment_type, '')) = 'regular' THEN 1 ELSE 0 END) AS reg_cnt,
                SUM(CASE WHEN LOWER(COALESCE(employment_type, '')) = 'probationary' THEN 1 ELSE 0 END) AS prob_cnt,
                SUM(CASE WHEN LOWER(COALESCE(employment_type, '')) IN ('contractual', 'contract') THEN 1 ELSE 0 END) AS cont_cnt
            FROM employees
        ");
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $reg  = (int)($row['reg_cnt'] ?? 0);
            $prob = (int)($row['prob_cnt'] ?? 0);
            $cont = (int)($row['cont_cnt'] ?? 0);

            // If employment_type isn't set on rows, show active employees under Regular
            if (($reg + $prob + $cont) === 0 && $total > 0) {
                try {
                    $reg = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status = 'Active'")->fetchColumn();
                } catch (Throwable $e) {
                    $reg = $total;
                }
            }

            $payload['overview']['regular']      = $reg;
            $payload['overview']['probationary'] = $prob;
            $payload['overview']['contractual']  = $cont;
        }
    } catch (Throwable $e) {
        error_log('[HRIS Stream] employees query: ' . $e->getMessage());
    }

    // 2. Present Today — ONLY actual check-ins recorded today (0 if none checked in)
    try {
        $sql = "
            SELECT COUNT(DISTINCT employee_id)
            FROM attendance
            WHERE (
                DATE(COALESCE(check_in_time, check_in, time_in, created_at, attendance_date, date)) = CURDATE()
            )
            AND (
                check_in_time IS NOT NULL
                OR check_in IS NOT NULL
                OR time_in IS NOT NULL
                OR LOWER(COALESCE(status, '')) IN ('present', 'late', 'checked_in', 'checked in', 'on time')
            )
        ";
        $payload['kpis']['present_today'] = (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        // Keep 0 rather than guessing all employees are present
        $payload['kpis']['present_today'] = 0;
        error_log('[HRIS Stream] attendance query: ' . $e->getMessage());
    }

    // 3. On Leave Today & Pending Requests
    try {
        $payload['kpis']['on_leave'] = (int)$pdo->query("
            SELECT COUNT(DISTINCT employee_id)
            FROM leave_requests
            WHERE LOWER(status) = 'approved'
              AND CURDATE() BETWEEN DATE(start_date) AND DATE(end_date)
        ")->fetchColumn();
    } catch (Throwable $e) {
        $payload['kpis']['on_leave'] = 0;
    }

    try {
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE LOWER(status) = 'pending'")->fetchColumn();
        try {
            $pending += (int)$pdo->query("SELECT COUNT(*) FROM promotion_requests WHERE LOWER(status) = 'pending'")->fetchColumn();
        } catch (Throwable $e) {}
        $payload['kpis']['pending_requests'] = $pending;
    } catch (Throwable $e) {
        $payload['kpis']['pending_requests'] = 0;
    }

    // 4. Recent Activities
    try {
        $stmt = $pdo->query("
            SELECT
                CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, ''), ' — ', COALESCE(lr.leave_type, 'Leave'), ' request (', COALESCE(lr.status, 'Pending'), ')') AS text,
                DATE_FORMAT(lr.created_at, '%b %d, %h:%i %p') AS ts
            FROM leave_requests lr
            LEFT JOIN employees e ON lr.employee_id = e.id
            ORDER BY lr.created_at DESC
            LIMIT 4
        ");
        $payload['recent_activities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    // 5. Upcoming Approved Leaves / Events
    try {
        $stmt = $pdo->query("
            SELECT
                CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, ''), ' — ', COALESCE(lr.leave_type, 'Leave')) AS title,
                DATE_FORMAT(lr.start_date, '%b %d, %Y') AS event_date
            FROM leave_requests lr
            LEFT JOIN employees e ON lr.employee_id = e.id
            WHERE LOWER(lr.status) = 'approved' AND DATE(lr.start_date) >= CURDATE()
            ORDER BY lr.start_date ASC
            LIMIT 3
        ");
        $payload['upcoming_events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    return $payload;
}

$acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
$isSseRequest = (($_GET['mode'] ?? '') === 'sse') || (stripos($acceptHeader, 'text/event-stream') !== false);

// If called via standard fetch/XHR, return immediate JSON
if (!$isSseRequest) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo json_encode(buildDashboardPayload($pdo));
    exit;
}

// SSE stream mode
session_write_close();
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

set_time_limit(0);
ignore_user_abort(true);

$configFile = __DIR__ . '/../config/realtime.json';
$config     = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
$interval   = max(1, min(10, (int)($config['poll_interval_seconds'] ?? 3)));

// Send initial snapshot immediately on connect
$initial = buildDashboardPayload($pdo);
$lastHash = md5(json_encode($initial));
echo "event: dashboard_update\n";
echo "data: " . json_encode($initial) . "\n\n";
if (ob_get_level() > 0) @ob_flush();
@flush();

$ticks = 0;
while (!connection_aborted() && $ticks < 100) {
    sleep($interval);
    $ticks++;

    $snapshot = buildDashboardPayload($pdo);
    $json     = json_encode($snapshot);
    $hash     = md5($json);

    if ($hash !== $lastHash) {
        echo "event: dashboard_update\n";
        echo "data: {$json}\n\n";
        $lastHash = $hash;
    } else {
        echo "event: heartbeat\n";
        echo "data: " . json_encode(['timestamp' => $snapshot['timestamp']]) . "\n\n";
    }

    if (ob_get_level() > 0) @ob_flush();
    @flush();
}