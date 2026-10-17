<?php
/**
 * Prometheus Metrics Endpoint
 * ===========================
 * Exposes HRIS application metrics in Prometheus text format.
 * Scraped by Prometheus at http://<your-host>/monitoring/metrics.php
 *
 * Metrics exposed:
 *   hris_db_up                          - Database connectivity (1=up, 0=down)
 *   hris_employees_total                - Total number of employees
 *   hris_employees_active_total         - Active employees
 *   hris_employees_inactive_total       - Inactive employees
 *   hris_leave_requests_total           - Total leave requests (labelled by status)
 *   hris_leave_requests_pending_total   - Pending leave requests
 *   hris_leave_requests_this_month      - Leave requests filed this calendar month
 *   hris_leave_types_total              - Number of configured leave types
 *   hris_scrape_duration_seconds        - Time taken to collect metrics
 *   hris_scrape_errors_total            - Number of errors during metric collection
 *   hris_php_version_info               - PHP version label gauge (always 1)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Security: only allow Prometheus / internal network access.
// Adjust ALLOWED_NETWORKS to match your Prometheus server IP / Docker subnet.
// ---------------------------------------------------------------------------
define('ALLOWED_NETWORKS', [
    '127.0.0.1',
    '::1',
    '172.0.0.0/8',   // Docker default bridge range
    '10.0.0.0/8',    // Private LAN
    '192.168.0.0/16',
]);

function ipIsAllowed(string $ip): bool
{
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

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (!ipIsAllowed($clientIp)) {
    http_response_code(403);
    exit("403 Forbidden\n");
}

// ---------------------------------------------------------------------------
// Bootstrap: DB connection (suppress errors; metric reports db_up=0 on fail)
// ---------------------------------------------------------------------------
$scrapeStart = microtime(true);
$scrapeErrors = 0;

$pdo = null;
try {
    require_once __DIR__ . '/../config/supabase.php';
    // $pdo is set by supabase.php
} catch (Throwable $e) {
    $pdo = null;
    $scrapeErrors++;
}

// ---------------------------------------------------------------------------
// Metric collector
// ---------------------------------------------------------------------------
class MetricRegistry
{
    private array $lines = [];

    public function gauge(string $name, string $help, float|int $value, array $labels = []): void
    {
        $this->lines[] = "# HELP {$name} {$help}";
        $this->lines[] = "# TYPE {$name} gauge";
        $this->lines[] = $name . $this->labelStr($labels) . " {$value}";
    }

    public function counter(string $name, string $help, float|int $value, array $labels = []): void
    {
        $this->lines[] = "# HELP {$name} {$help}";
        $this->lines[] = "# TYPE {$name} counter";
        $this->lines[] = $name . $this->labelStr($labels) . " {$value}";
    }

    private function labelStr(array $labels): string
    {
        if (empty($labels)) return '';
        $parts = [];
        foreach ($labels as $k => $v) {
            $v = str_replace('"', '\\"', (string)$v);
            $parts[] = "{$k}=\"{$v}\"";
        }
        return '{' . implode(',', $parts) . '}';
    }

    public function render(): string
    {
        return implode("\n", $this->lines) . "\n";
    }
}

$reg = new MetricRegistry();

// ---------------------------------------------------------------------------
// 1. PHP version info
// ---------------------------------------------------------------------------
$reg->gauge(
    'hris_php_version_info',
    'PHP version info (always 1, use labels to read version)',
    1,
    ['version' => PHP_VERSION, 'major' => PHP_MAJOR_VERSION]
);

// ---------------------------------------------------------------------------
// 2. DB connectivity
// ---------------------------------------------------------------------------
$dbUp = ($pdo !== null) ? 1 : 0;
$reg->gauge('hris_db_up', 'Database connectivity check (1=up, 0=down)', $dbUp);

if ($pdo !== null) {

    // -----------------------------------------------------------------------
    // 3. Employee metrics
    // -----------------------------------------------------------------------
    try {
        $row = $pdo->query("
            SELECT
                COUNT(*)                                          AS total,
                COUNT(*) FILTER (WHERE LOWER(status) = 'active') AS active,
                COUNT(*) FILTER (WHERE LOWER(status) != 'active' OR status IS NULL) AS inactive
            FROM public.employee
        ")->fetch(PDO::FETCH_ASSOC);

        $reg->gauge('hris_employees_total',          'Total number of employees in the system',     (int)$row['total']);
        $reg->gauge('hris_employees_active_total',   'Number of active employees',                  (int)$row['active']);
        $reg->gauge('hris_employees_inactive_total', 'Number of inactive / deactivated employees',  (int)$row['inactive']);
    } catch (Throwable $e) {
        $scrapeErrors++;
        $reg->gauge('hris_employees_total',          'Total number of employees in the system', 0);
        $reg->gauge('hris_employees_active_total',   'Number of active employees',              0);
        $reg->gauge('hris_employees_inactive_total', 'Number of inactive employees',             0);
    }

    // -----------------------------------------------------------------------
    // 4. Leave request metrics
    // -----------------------------------------------------------------------
    try {
        $rows = $pdo->query("
            SELECT
                status,
                COUNT(*) AS cnt
            FROM public.leave_requests
            GROUP BY status
        ")->fetchAll(PDO::FETCH_ASSOC);

        $byStatus = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];
        $total    = 0;
        foreach ($rows as $r) {
            $byStatus[$r['status']] = (int)$r['cnt'];
            $total += (int)$r['cnt'];
        }

        $reg->gauge('hris_leave_requests_total', 'Total leave requests ever filed', $total);

        foreach ($byStatus as $status => $cnt) {
            $reg->gauge(
                'hris_leave_requests_by_status_total',
                'Leave requests grouped by current status',
                $cnt,
                ['status' => $status]
            );
        }

    } catch (Throwable $e) {
        $scrapeErrors++;
        $reg->gauge('hris_leave_requests_total', 'Total leave requests ever filed', 0);
    }

    // -----------------------------------------------------------------------
    // 5. Leave requests filed this calendar month
    // -----------------------------------------------------------------------
    try {
        $count = (int)$pdo->query("
            SELECT COUNT(*) FROM public.leave_requests
            WHERE date_trunc('month', created_at) = date_trunc('month', NOW())
        ")->fetchColumn();
        $reg->gauge('hris_leave_requests_this_month', 'Leave requests filed in the current calendar month', $count);
    } catch (Throwable $e) {
        $scrapeErrors++;
        $reg->gauge('hris_leave_requests_this_month', 'Leave requests filed in the current calendar month', 0);
    }

    // -----------------------------------------------------------------------
    // 6. Leave types count
    // -----------------------------------------------------------------------
    try {
        $ltCount = (int)$pdo->query("SELECT COUNT(*) FROM public.leave_types")->fetchColumn();
        $reg->gauge('hris_leave_types_total', 'Number of configured leave types', $ltCount);
    } catch (Throwable $e) {
        $scrapeErrors++;
        $reg->gauge('hris_leave_types_total', 'Number of configured leave types', 0);
    }

    // -----------------------------------------------------------------------
    // 7. DB response time (simple round-trip)
    // -----------------------------------------------------------------------
    try {
        $t0 = microtime(true);
        $pdo->query("SELECT 1")->fetchColumn();
        $dbRtt = round(microtime(true) - $t0, 6);
        $reg->gauge('hris_db_query_duration_seconds', 'Round-trip time for a simple SELECT 1 query', $dbRtt);
    } catch (Throwable $e) {
        $scrapeErrors++;
    }
}

// ---------------------------------------------------------------------------
// 8. Scrape duration & errors
// ---------------------------------------------------------------------------
$scrapeDuration = round(microtime(true) - $scrapeStart, 6);
$reg->gauge(
    'hris_scrape_duration_seconds',
    'Time taken to collect all HRIS metrics (seconds)',
    $scrapeDuration
);
$reg->counter(
    'hris_scrape_errors_total',
    'Number of errors encountered during this metrics scrape',
    $scrapeErrors
);

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
header('Cache-Control: no-cache, no-store');
echo $reg->render();

