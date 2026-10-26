<?php
/**
 * Prometheus Metrics Endpoint
 * ===========================
 * Exposes HRIS application metrics in the Prometheus text exposition format.
 * Scraped by Prometheus from the hris-exporter container at
 *   http://hris-exporter:9091/monitoring/metrics.php
 *
 * Metrics exposed:
 *   hris_up                               - Endpoint reachable (always 1)
 *   hris_db_up                            - Database connectivity (1=up, 0=down)
 *   hris_db_query_duration_seconds        - Round-trip time of SELECT 1
 *   hris_employees_total                  - Total employees
 *   hris_employees_active_total           - Active employees
 *   hris_employees_inactive_total         - Inactive employees
 *   hris_leave_requests_total             - Total leave requests
 *   hris_leave_requests_by_status_total   - Leave requests by status {status}
 *   hris_leave_days_by_status_total       - Leave days by status {status}
 *   hris_leave_requests_this_month        - Requests filed this calendar month
 *   hris_leave_requests_by_type_total     - Leave requests by type {leave_type}
 *   hris_leave_transactions_total         - Audit-log transactions by action {action}
 *   hris_leave_types_total                - Configured leave types
 *   hris_scrape_duration_seconds          - Time taken to collect metrics
 *   hris_scrape_errors                    - Errors during this scrape
 *   hris_php_version_info                 - PHP version label gauge (always 1)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Security: only allow Prometheus / internal network access.
// ---------------------------------------------------------------------------
const ALLOWED_NETWORKS = [
    '127.0.0.1',
    '::1',
    '172.16.0.0/12',  // Docker bridge networks
    '10.0.0.0/8',     // Private LAN
    '192.168.0.0/16', // Private LAN / Docker Desktop
];

function ipIsAllowed(string $ip): bool
{
    // Unwrap IPv4-mapped IPv6 addresses such as ::ffff:172.18.0.3
    if (stripos($ip, '::ffff:') === 0) {
        $ip = substr($ip, 7);
    }
    foreach (ALLOWED_NETWORKS as $cidr) {
        if (strpos($cidr, '/') === false) {
            if ($ip === $cidr) return true;
            continue;
        }
        [$subnet, $bits] = explode('/', $cidr);
        $subnetLong = ip2long($subnet);
        $ipLong     = ip2long($ip);
        if ($subnetLong === false || $ipLong === false) continue;
        $mask = -1 << (32 - (int)$bits);
        if (($ipLong & $mask) === ($subnetLong & $mask)) return true;
    }
    return false;
}

if (PHP_SAPI !== 'cli') {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!ipIsAllowed($clientIp)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("403 Forbidden\n");
    }
}

// ---------------------------------------------------------------------------
// Metric registry: groups samples per metric so HELP/TYPE appear exactly once
// per metric name, as the exposition format requires.
// ---------------------------------------------------------------------------
final class MetricRegistry
{
    /** @var array<string, array{help:string, type:string, samples:list<string>}> */
    private array $metrics = [];

    public function gauge(string $name, string $help, float|int $value, array $labels = []): void
    {
        $this->add($name, 'gauge', $help, $value, $labels);
    }

    private function add(string $name, string $type, string $help, float|int $value, array $labels): void
    {
        if (!isset($this->metrics[$name])) {
            $this->metrics[$name] = ['help' => $help, 'type' => $type, 'samples' => []];
        }
        $this->metrics[$name]['samples'][] = $name . $this->labelStr($labels) . ' ' . $this->num($value);
    }

    private function labelStr(array $labels): string
    {
        if (!$labels) return '';
        $parts = [];
        foreach ($labels as $k => $v) {
            $v = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string)$v);
            $parts[] = $k . '="' . $v . '"';
        }
        return '{' . implode(',', $parts) . '}';
    }

    private function num(float|int $v): string
    {
        if (is_int($v)) return (string)$v;
        if (is_nan($v)) return 'NaN';
        return rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') ?: '0';
    }

    public function render(): string
    {
        $out = [];
        foreach ($this->metrics as $name => $m) {
            $out[] = "# HELP {$name} " . str_replace(["\\", "\n"], ["\\\\", "\\n"], $m['help']);
            $out[] = "# TYPE {$name} {$m['type']}";
            foreach ($m['samples'] as $s) {
                $out[] = $s;
            }
        }
        return implode("\n", $out) . "\n";
    }
}

$reg          = new MetricRegistry();
$scrapeStart  = microtime(true);
$scrapeErrors = 0;

function metricError(string $where, Throwable $e, int &$counter): void
{
    $counter++;
    error_log("metrics.php [{$where}]: " . $e->getMessage());
}

// ---------------------------------------------------------------------------
// DB connection (MySQL). HRIS_DB_THROW_ON_FAIL makes database.php throw instead of
// die(), so a DB outage is reported as hris_db_up 0 rather than breaking
// the scrape with a plain-text error page.
// ---------------------------------------------------------------------------
define('HRIS_DB_THROW_ON_FAIL', true);
$pdo = null;
try {
    require __DIR__ . '/../config/database.php';
} catch (Throwable $e) {
    $pdo = null;
    metricError('connect', $e, $scrapeErrors);
}

$reg->gauge('hris_up', 'HRIS metrics endpoint is reachable (always 1)', 1);
$reg->gauge(
    'hris_php_version_info',
    'PHP version info (always 1, read the version from labels)',
    1,
    ['version' => PHP_VERSION, 'major' => (string)PHP_MAJOR_VERSION]
);
$reg->gauge('hris_db_up', 'Database connectivity check (1=up, 0=down)', $pdo instanceof PDO ? 1 : 0);

if ($pdo instanceof PDO) {

    // DB round trip
    try {
        $t0 = microtime(true);
        $pdo->query('SELECT 1')->fetchColumn();
        $reg->gauge('hris_db_query_duration_seconds', 'Round-trip time for a simple SELECT 1 query', round(microtime(true) - $t0, 6));
    } catch (Throwable $e) {
        metricError('rtt', $e, $scrapeErrors);
    }

    // Employees (employment_status: Active | Inactive | On Leave | Suspended)
    try {
        $row = $pdo->query("
            SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN employment_status = 'Active' THEN 1 ELSE 0 END), 0) AS active
            FROM employees
        ")->fetch(PDO::FETCH_ASSOC);
        $total  = (int)$row['total'];
        $active = (int)$row['active'];
    } catch (Throwable $e) {
        metricError('employees', $e, $scrapeErrors);
        $total = $active = 0;
    }
    $reg->gauge('hris_employees_total', 'Total number of employees in the system', $total);
    $reg->gauge('hris_employees_active_total', 'Number of active employees', $active);
    $reg->gauge('hris_employees_inactive_total', 'Number of inactive / deactivated employees', $total - $active);

    // Leave requests by status (always emit all four so panels never go blank)
    $byStatus = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];
    $daysBy   = ['Pending' => 0.0, 'Approved' => 0.0, 'Rejected' => 0.0, 'Cancelled' => 0.0];
    $reqTotal = 0;
    try {
        $rows = $pdo->query("
            SELECT status, COUNT(*) AS cnt, COALESCE(SUM(number_of_days), 0) AS days
            FROM leave_requests
            GROUP BY status
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            // Normalise 'pending' / 'PENDING' etc. to the label the dashboard uses
            $status = ucfirst(strtolower(trim((string)$r['status']))) ?: 'Unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + (int)$r['cnt'];
            $daysBy[$status]   = ($daysBy[$status] ?? 0.0) + (float)$r['days'];
            $reqTotal += (int)$r['cnt'];
        }
    } catch (Throwable $e) {
        metricError('leave_status', $e, $scrapeErrors);
    }
    $reg->gauge('hris_leave_requests_total', 'Total leave requests ever filed', $reqTotal);
    foreach ($byStatus as $status => $cnt) {
        $reg->gauge('hris_leave_requests_by_status_total', 'Leave requests grouped by current status', $cnt, ['status' => $status]);
    }
    foreach ($daysBy as $status => $days) {
        $reg->gauge('hris_leave_days_by_status_total', 'Leave days grouped by current request status', $days, ['status' => $status]);
    }

    // Filed this month (Asia/Manila calendar month)
    try {
        $count = (int)$pdo->query("
            SELECT COUNT(*) FROM leave_requests
            WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
              AND created_at <  DATE_FORMAT(NOW() + INTERVAL 1 MONTH, '%Y-%m-01')
        ")->fetchColumn();
    } catch (Throwable $e) {
        metricError('this_month', $e, $scrapeErrors);
        $count = 0;
    }
    $reg->gauge('hris_leave_requests_this_month', 'Leave requests filed in the current calendar month', $count);

    // Requests by leave type
    try {
        $rows = $pdo->query("
            SELECT COALESCE(lt.leave_name, 'Other') AS leave_name, COUNT(*) AS cnt
            FROM leave_requests lr
            LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
            GROUP BY COALESCE(lt.leave_name, 'Other')
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $reg->gauge('hris_leave_requests_by_type_total', 'Leave requests grouped by leave type', (int)$r['cnt'], ['leave_type' => $r['leave_name']]);
        }
    } catch (Throwable $e) {
        metricError('by_type', $e, $scrapeErrors);
    }

    // Audit-log transactions (table is created on first logged action)
    $tx = ['Filed' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];
    try {
        $rows = $pdo->query("SELECT action, COUNT(*) AS cnt FROM leave_request_logs GROUP BY action")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $tx[(string)$r['action']] = (int)$r['cnt'];
        }
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') { // table not created yet is not an error
            metricError('transactions', $e, $scrapeErrors);
        }
    }
    foreach ($tx as $action => $cnt) {
        $reg->gauge('hris_leave_transactions_total', 'Recorded leave transactions by action (audit log)', $cnt, ['action' => $action]);
    }

    // Leave types
    try {
        $ltCount = (int)$pdo->query('SELECT COUNT(*) FROM leave_types')->fetchColumn();
    } catch (Throwable $e) {
        metricError('leave_types', $e, $scrapeErrors);
        $ltCount = 0;
    }
    $reg->gauge('hris_leave_types_total', 'Number of configured leave types', $ltCount);
}

$reg->gauge('hris_scrape_duration_seconds', 'Time taken to collect all HRIS metrics (seconds)', round(microtime(true) - $scrapeStart, 6));
$reg->gauge('hris_scrape_errors', 'Number of errors encountered during this metrics scrape', $scrapeErrors);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
}
echo $reg->render();
