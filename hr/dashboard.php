<?php
require_once __DIR__ . '/../authentication/guard.php';
require_access('dashboard', 'view', null, ['admin', 'hr']);
$title = 'Dashboard';

$employeeCount = $activeEmployeeCount = $presentTodayCount = $onLeaveCount = $pendingRequestCount = 0;
$overdueLeaveAlerts = [];
$currentPendingLeaveIds = [];

try {
    require_once __DIR__ . '/../config/database.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $employeeCount = (int)$pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn();
        } catch (Throwable $e) {}

        try {
            $activeEmployeeCount = (int)$pdo->query("
                SELECT COUNT(DISTINCT employee_id)
                FROM attendance
                WHERE DATE(time_in) = CURRENT_DATE()
            ")->fetchColumn();
        } catch (Throwable $e) {
            $activeEmployeeCount = 0;
        }

        $presentTodayCount = $activeEmployeeCount;

        try {
            $onLeaveCount = (int)$pdo->query("
                SELECT COUNT(DISTINCT employee_id)
                FROM leave_requests
                WHERE LOWER(status) = 'approved'
                  AND CURRENT_DATE() BETWEEN DATE(start_date) AND DATE(end_date)
            ")->fetchColumn();
        } catch (Throwable $e) {}

        try {
            $pendingRequestCount = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE LOWER(status) = 'pending'")->fetchColumn();
        } catch (Throwable $e) {}

        try {
            $pendingStmt = $pdo->query("SELECT id FROM leave_requests WHERE LOWER(COALESCE(status, '')) = 'pending'");
            foreach ($pendingStmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                if ($pid !== false && $pid !== null) {
                    $currentPendingLeaveIds[] = (int)$pid;
                }
            }
        } catch (Throwable $e) {}

        try {
            $stmt = $pdo->query("
                SELECT
                    lr.id,
                    lr.employee_id,
                    COALESCE(lr.leave_type, 'Leave') AS leave_type,
                    lr.created_at,
                    UNIX_TIMESTAMP(lr.created_at) AS created_ts,
                    TIMESTAMPDIFF(DAY, lr.created_at, NOW()) AS days_pending,
                    TRIM(CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, ''))) AS employee_name
                FROM leave_requests lr
                LEFT JOIN employees e ON lr.employee_id = e.id
                WHERE LOWER(COALESCE(lr.status, '')) = 'pending'
                  AND TIMESTAMPDIFF(DAY, lr.created_at, NOW()) >= 2
                ORDER BY lr.created_at ASC
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $empName = trim((string)($row['employee_name'] ?? ''));
                if ($empName === '') {
                    $empName = 'Employee #' . (int)($row['employee_id'] ?? 0);
                }
                $days = max(2, (int)($row['days_pending'] ?? 2));
                $reqId = (int)($row['id'] ?? 0);
                $createdTs = !empty($row['created_ts']) ? (int)$row['created_ts'] : (!empty($row['created_at']) ? (int)strtotime((string)$row['created_at']) : time());
                $overdueLeaveAlerts[] = [
                    'id'         => 'db_leave_2d_' . $reqId,
                    'request_id' => $reqId,
                    'category'   => 'leave_2d',
                    'title'      => "Unprocessed Leave ({$days}d): {$empName}",
                    'message'    => "{$row['leave_type']} request #{$reqId} has not been approved or rejected for {$days} days.",
                    'created_at' => $createdTs * 1000,
                    'url'        => '/hr/leave_management.php',
                ];
            }
        } catch (Throwable $e) {}
    }
} catch (Throwable $e) {}

$overviewTotal = $activeEmployeeCount + $onLeaveCount;

require __DIR__ . '/../templates/hrTemplates/header.php';

function dashboardMetric($value): string {
    return $value === null ? '—' : number_format((int)$value);
}

$rawSessionName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
$displayProfileName = $rawSessionName !== '' ? ucwords($rawSessionName) : 'Donald Toñacao';
$hrName = ucfirst(explode(' ', trim((string)($_SESSION['username'] ?? 'Hr')))[0] ?: 'Hr');
$avatarInitials = strtoupper(substr($displayProfileName, 0, 1));
$initialBellCount = count($overdueLeaveAlerts) > 0 ? count($overdueLeaveAlerts) : (int)$pendingRequestCount;
?>

<style>
  .top-utility-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 12px 18px;
    margin-bottom: 18px;
    border-radius: 16px;
    background: rgba(15, 23, 42, 0.72);
    border: 1px solid rgba(148, 163, 184, 0.18);
    backdrop-filter: blur(10px);
    position: relative;
    z-index: 30;
  }
  .top-search-box {
    position: relative;
    flex: 1;
    max-width: 440px;
    min-width: 220px;
  }
  .top-search-box .search-ico {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 14px;
    color: #94a3b8;
    pointer-events: none;
  }
  .top-search-input {
    width: 100%;
    padding: 9px 16px 9px 38px;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.28);
    background: rgba(30, 41, 59, 0.65);
    color: #f8fafc;
    font-size: 13px;
    outline: none;
  }
  .top-search-input::placeholder {
    color: #94a3b8;
  }
  .top-utility-right {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    position: relative;
  }
  .icon-badge-btn {
    position: relative;
    width: 36px;
    height: 36px;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.22);
    background: rgba(30, 41, 59, 0.65);
    color: #e2e8f0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    text-decoration: none;
    cursor: pointer;
  }
  .icon-badge-count {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 17px;
    height: 17px;
    padding: 0 4px;
    border-radius: 999px;
    background: #ef4444;
    color: #ffffff;
    font-size: 10px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
  .icon-badge-count.pink {
    background: #ec4899;
  }

  /* Bell Notification Popover Window */
  .bell-popover-wrap {
    position: relative;
  }
  .bell-popover-window {
    display: none;
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 375px;
    max-width: calc(100vw - 32px);
    border-radius: 14px;
    background: #0f172a;
    border: 1px solid rgba(96, 165, 250, 0.35);
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.55);
    z-index: 999;
    overflow: hidden;
  }
  .bell-popover-window.is-open {
    display: block;
  }
  .bell-popover-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 12px 14px;
    background: rgba(30, 41, 59, 0.75);
    border-bottom: 1px solid rgba(148, 163, 184, 0.16);
  }
  .bell-popover-head strong {
    font-size: 13.5px;
    color: #f8fafc;
  }
  .bell-auto-pill {
    font-size: 10.5px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 999px;
    background: rgba(16, 185, 129, 0.18);
    color: #34d399;
    border: 1px solid rgba(52, 211, 153, 0.35);
  }
  .bell-popover-status {
    padding: 6px 14px;
    font-size: 11px;
    color: #93c5fd;
    background: rgba(37, 99, 235, 0.12);
    border-bottom: 1px solid rgba(148, 163, 184, 0.12);
  }
  .bell-popover-body {
    max-height: 310px;
    overflow-y: auto;
    padding: 8px 12px;
  }
  .bell-notif-item {
    padding: 10px 4px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.12);
    font-size: 12.5px;
  }
  .bell-notif-item:last-child {
    border-bottom: none;
  }
  .bell-notif-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 3px;
  }
  .bell-notif-title {
    font-weight: 700;
    color: #f8fafc;
  }
  .bell-notif-tag {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    padding: 2px 6px;
    border-radius: 6px;
    background: rgba(245, 158, 11, 0.2);
    color: #fbbf24;
  }
  .bell-notif-tag.info {
    background: rgba(16, 185, 129, 0.18);
    color: #34d399;
  }
  .bell-notif-msg {
    color: #cbd5e1;
    line-height: 1.35;
    margin: 0;
  }
  .bell-notif-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 4px;
    font-size: 11px;
    color: #94a3b8;
  }
  .bell-notif-link {
    color: #60a5fa;
    text-decoration: none;
    font-weight: 600;
  }

  .user-profile-pill {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 4px 10px 4px 4px;
    border-radius: 999px;
    background: rgba(30, 41, 59, 0.55);
    border: 1px solid rgba(148, 163, 184, 0.2);
  }
  .user-avatar-circle {
    width: 34px;
    height: 34px;
    border-radius: 999px;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    color: #ffffff;
    font-weight: 700;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
  .user-profile-meta {
    display: flex;
    flex-direction: column;
    line-height: 1.15;
  }
  .user-profile-meta strong {
    font-size: 13px;
    color: #f8fafc;
  }
  .user-profile-meta small {
    font-size: 11px;
    color: #94a3b8;
  }

  .dash-header-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
  }
  .live-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    background: rgba(16, 185, 129, 0.14);
    color: #34d399;
    border: 1px solid rgba(52, 211, 153, 0.3);
  }
  .live-pill.is-reconnecting {
    background: rgba(245, 158, 11, 0.14);
    color: #fbbf24;
    border-color: rgba(251, 191, 36, 0.3);
  }
  .live-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #34d399;
  }
  .live-pill.is-reconnecting .live-dot {
    background: #fbbf24;
  }
  .analytics-row {
    display: grid;
    grid-template-columns: 1.1fr 1.3fr 0.9fr;
    gap: 18px;
    margin-bottom: 18px;
  }
  .bottom-row {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 18px;
    margin-bottom: 24px;
  }
  @media (max-width: 1100px) {
    .analytics-row, .bottom-row { grid-template-columns: 1fr; }
  }
  .donut-wrap {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding-top: 8px;
  }
  .donut-canvas-box {
    position: relative;
    width: 165px;
    height: 165px;
    flex-shrink: 0;
  }
  .donut-center-label {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    pointer-events: none;
  }
  .donut-center-label strong { font-size: 22px; line-height: 1.1; color: #f8fafc; }
  .donut-center-label span { font-size: 12px; color: #94a3b8; }
  .donut-legend {
    list-style: none;
    padding: 0;
    margin: 0;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 14px;
  }
  .donut-legend li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 13.5px;
    color: #e2e8f0;
  }
  .legend-left { display: flex; align-items: center; gap: 8px; }
  .legend-swatch { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }

  .trend-rating-strip {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
  }
  .rating-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    background: rgba(30, 41, 59, 0.75);
    border: 1px solid rgba(148, 163, 184, 0.2);
    color: #e2e8f0;
  }
  .rating-chip.present { color: #60a5fa; border-color: rgba(96, 165, 250, 0.35); }
  .rating-chip.absent  { color: #fb923c; border-color: rgba(251, 146, 60, 0.4); }
  .rating-chip.leave   { color: #fbbf24; border-color: rgba(251, 191, 36, 0.4); }

  .quick-action-stack {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 10px;
  }
  .qa-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 14px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    background: rgba(255, 255, 255, 0.05);
    color: #e2e8f0;
    border: 1px solid rgba(148, 163, 184, 0.2);
    transition: background 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
  }
  .qa-btn:hover {
    background: rgba(59, 130, 246, 0.16);
    border-color: rgba(96, 165, 250, 0.45);
    color: #ffffff;
    transform: translateY(-1px);
  }
  .qa-btn.qa-primary {
    background: linear-gradient(135deg, #2563eb, #3b82f6);
    color: #ffffff;
    border-color: rgba(96, 165, 250, 0.5);
  }
  .qa-btn.qa-primary:hover {
    background: linear-gradient(135deg, #1d4ed8, #2563eb);
  }

  .activity-row-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 11px 0;
    border-bottom: 1px solid rgba(148, 163, 184, 0.14);
    font-size: 13.5px;
  }
  .activity-row-item:last-child { border-bottom: none; }
  .event-card-box {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px;
    border-radius: 12px;
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(96, 165, 250, 0.24);
    margin-bottom: 10px;
  }
  .event-cal-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: #2563eb;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
  }
</style>

<!-- Top Search & Profile Utility Bar -->
<div class="top-utility-bar">
  <div class="top-search-box">
    <span class="search-ico">🔍</span>
    <input
      type="text"
      id="top-dashboard-search"
      class="top-search-input"
      placeholder="Search anything..."
      autocomplete="off"
    >
  </div>

  <div class="top-utility-right">
    <!-- Bell Icon + Real-Time HR Notifications Popover -->
    <div class="bell-popover-wrap">
      <button type="button" id="bell-icon-btn" class="icon-badge-btn" title="HR Notifications (Real-Time)" aria-expanded="false">
        <span>🔔</span>
        <span class="icon-badge-count" id="top-notif-badge"><?= $initialBellCount ?></span>
      </button>

      <div class="bell-popover-window" id="bell-popover-window" role="dialog" aria-label="HR Notifications">
        <div class="bell-popover-head">
          <strong>HR Stored Notifications</strong>
          <span class="bell-auto-pill">LIVE</span>
        </div>
        <div class="bell-popover-status" id="bell-popover-status">Real-time 2+ day leave alerts &amp; status sync</div>
        <div class="bell-popover-body" id="bell-popover-list"></div>
      </div>
    </div>

    <a href="/hr/daily_attendance.php" class="icon-badge-btn" title="Daily Attendance Updates">
      <span>💬</span>
      <span class="icon-badge-count pink" id="top-msg-badge"><?= (int)$presentTodayCount ?></span>
    </a>

    <div class="user-profile-pill">
      <span class="user-avatar-circle"><?= htmlspecialchars($avatarInitials, ENT_QUOTES, 'UTF-8') ?></span>
      <span class="user-profile-meta">
        <strong><?= htmlspecialchars($displayProfileName, ENT_QUOTES, 'UTF-8') ?></strong>
        <small>HR Manager</small>
      </span>
    </div>
  </div>
</div>

<div class="dash-header-bar">
  <div>
    <h1 class="welcome-hero" style="margin:0; font-size: 1.65rem;">
      Welcome back, <span class="welcome-name"><?= htmlspecialchars($hrName, ENT_QUOTES, 'UTF-8') ?>!</span>
    </h1>
    <p class="muted" style="margin: 4px 0 0;">Here's what's happening in your organization.</p>
  </div>
  <div class="live-pill is-reconnecting" id="sse-badge">
    <span class="live-dot"></span>
    <span id="sse-status-text">Connecting...</span>
  </div>
</div>

<!-- Row 1: 4 Metric Cards -->
<div class="metric-strip">
  <article class="metric-card metric-blue">
    <div class="metric-top">
      <span class="metric-ico">👥</span>
      <span class="metric-chip up">Directory</span>
    </div>
    <span class="metric-title">Total Employees</span>
    <strong class="metric-number blue" id="kpi-total-employees"><?= dashboardMetric($employeeCount) ?></strong>
    <span class="metric-description">Total registered workforce</span>
  </article>

  <article class="metric-card metric-green">
    <div class="metric-top">
      <span class="metric-ico">📅</span>
      <span class="metric-chip up" id="kpi-present-chip">Today</span>
    </div>
    <span class="metric-title" id="kpi-present-title">Present Today</span>
    <strong class="metric-number green" id="kpi-present-today"><?= dashboardMetric($presentTodayCount) ?></strong>
    <span class="metric-description" id="kpi-present-desc">Checked-in on selected day</span>
  </article>

  <article class="metric-card metric-teal">
    <div class="metric-top">
      <span class="metric-ico">🧳</span>
      <span class="metric-chip">Leave</span>
    </div>
    <span class="metric-title">On Leave</span>
    <strong class="metric-number teal" id="kpi-on-leave"><?= dashboardMetric($onLeaveCount) ?></strong>
    <span class="metric-description">Approved leave on selected day</span>
  </article>

  <article class="metric-card metric-violet">
    <div class="metric-top">
      <span class="metric-ico">⏳</span>
      <span class="metric-chip">Queue</span>
    </div>
    <span class="metric-title">Pending Requests</span>
    <strong class="metric-number purple" id="kpi-pending-requests"><?= dashboardMetric($pendingRequestCount) ?></strong>
    <span class="metric-description">Awaiting HR approval</span>
  </article>
</div>

<!-- Row 2: Employee Overview Donut + Daily Attendance & Leave Trend + Quick Actions -->
<div class="analytics-row">
  <article class="card">
    <div class="card-heading">
      <div>
        <p class="eyebrow">WORKFORCE</p>
        <h2>Employee Overview</h2>
      </div>
    </div>
    <div class="donut-wrap">
      <div class="donut-canvas-box">
        <canvas id="employeeOverviewChart"></canvas>
        <div class="donut-center-label">
          <strong id="donut-total"><?= dashboardMetric($overviewTotal) ?></strong>
          <span id="donut-day-label">Today</span>
        </div>
      </div>
      <ul class="donut-legend">
        <li>
          <span class="legend-left"><span class="legend-swatch" style="background:#0ea5e9;"></span>Active</span>
          <strong id="legend-active"><?= dashboardMetric($activeEmployeeCount) ?></strong>
        </li>
        <li>
          <span class="legend-left"><span class="legend-swatch" style="background:#f59e0b;"></span>On Leave</span>
          <strong id="legend-on-leave"><?= dashboardMetric($onLeaveCount) ?></strong>
        </li>
      </ul>
    </div>
  </article>

  <article class="card">
    <div class="card-heading" style="flex-wrap:wrap;gap:8px;">
      <div>
        <p class="eyebrow">DAILY FLOW (7 DAYS)</p>
        <h2>Attendance Trend</h2>
      </div>
      <div class="trend-rating-strip">
        <span class="rating-chip present" id="trend-present-rating">Present: 0.0%</span>
        <span class="rating-chip absent" id="trend-absent-rating">Absent: 0.0%</span>
        <span class="rating-chip leave" id="trend-leave-rating">Leave: 0.0%</span>
      </div>
    </div>
    <div style="height: 185px; position: relative;">
      <canvas id="attendanceTrendChart"></canvas>
    </div>
  </article>

  <article class="card">
    <div class="card-heading">
      <div>
        <p class="eyebrow">WORKSPACE</p>
        <h2>Quick Actions</h2>
      </div>
    </div>
    <div class="quick-action-stack">
      <a class="qa-btn qa-primary" href="/hr/add_employee.php"><span>👤＋</span> Add Employee</a>
      <a class="qa-btn" href="/hr/leave_management.php"><span>📋</span> Apply / Review Leave</a>
      <a class="qa-btn" href="/hr/report.php"><span>📊</span> Generate Report</a>
      <a class="qa-btn" href="/hr/department.php"><span>⚙️</span> Manage Settings</a>
    </div>
  </article>
</div>

<!-- Row 3: Recent Activities + Upcoming Events -->
<div class="bottom-row">
  <article class="card">
    <div class="card-heading">
      <div>
        <p class="eyebrow">LIVE FEED</p>
        <h2>Recent Activities</h2>
      </div>
      <a class="button" href="/hr/leave_management.php">View all</a>
    </div>
    <div id="live-activities-list">
      <div class="activity-row-item">
        <span><span class="dot teal" style="display:inline-block;margin-right:8px;"></span>Waiting for real-time WebSocket events</span>
        <small class="muted">Just now</small>
      </div>
    </div>
  </article>

  <article class="card">
    <div class="card-heading">
      <div>
        <p class="eyebrow">SCHEDULE</p>
        <h2>Upcoming Events</h2>
      </div>
    </div>
    <div id="live-events-list">
      <div class="event-card-box">
        <div class="event-cal-icon">📅</div>
        <div>
          <strong style="display:block;font-size:14px;">Performance Evaluation</strong>
          <small class="muted">Nov 30, 2026</small>
        </div>
      </div>
    </div>
  </article>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  // Real-Time HR Notification Store (Live Relative Time + Auto-Remove on Approve/Reject/Cancel)
  const bellBtn = document.getElementById('bell-icon-btn');
  const bellWin = document.getElementById('bell-popover-window');
  const bellList = document.getElementById('bell-popover-list');
  const bellStatus = document.getElementById('bell-popover-status');
  const bellBadge = document.getElementById('top-notif-badge');
  const BELL_STORAGE_KEY = 'hris_hr_stored_notifications_v2';

  const dbOverdueLeaves = <?= json_encode($overdueLeaveAlerts, JSON_UNESCAPED_UNICODE) ?>;
  let activePendingLeaveIds = new Set(<?= json_encode($currentPendingLeaveIds, JSON_UNESCAPED_UNICODE) ?>.map(Number));

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
  }

  function formatRelativeTime(createdMs) {
    const ts = Number(createdMs);
    if (!Number.isFinite(ts) || ts <= 0) return 'Just now';
    const diffSec = Math.max(0, Math.floor((Date.now() - ts) / 1000));
    if (diffSec < 45) return 'Just now';
    const diffMin = Math.floor(diffSec / 60);
    if (diffMin < 60) return `${diffMin}m ago`;
    const diffHr = Math.floor(diffMin / 60);
    if (diffHr < 24) return `${diffHr}h ago`;
    const diffDay = Math.floor(diffHr / 24);
    return `${diffDay}d ago`;
  }

  function isItemStillValid(item) {
    if (!item || !item.id) return false;
    const reqId = Number(item.request_id || 0);
    if (reqId > 0 && activePendingLeaveIds.size > 0 && !activePendingLeaveIds.has(reqId)) {
      return false;
    }
    return true;
  }

  function loadStoredBellList() {
    let saved = [];
    try {
      const raw = sessionStorage.getItem(BELL_STORAGE_KEY);
      const parsed = raw ? JSON.parse(raw) : [];
      if (Array.isArray(parsed)) saved = parsed;
    } catch (_) {}

    const merged = [];
    const seenIds = new Set();

    for (const item of (Array.isArray(dbOverdueLeaves) ? dbOverdueLeaves : [])) {
      if (!item || !item.id || seenIds.has(item.id) || !isItemStillValid(item)) continue;
      seenIds.add(item.id);
      merged.push({
        ...item,
        created_at: Number(item.created_at) || Date.now()
      });
    }

    for (const item of saved) {
      if (!item || !item.id || seenIds.has(item.id) || !isItemStillValid(item)) continue;
      seenIds.add(item.id);
      merged.push({
        ...item,
        created_at: Number(item.created_at) || Date.now()
      });
    }

    const pruned = merged.slice(0, 15);
    saveStoredBellList(pruned);
    return pruned;
  }

  function saveStoredBellList(items) {
    try {
      sessionStorage.setItem(BELL_STORAGE_KEY, JSON.stringify((items || []).slice(0, 15)));
    } catch (_) {}
  }

  function storeBellNotification(entry) {
    if (!entry || !entry.id) return;
    const reqId = Number(entry.request_id || 0);
    if (reqId > 0) {
      activePendingLeaveIds.add(reqId);
    }
    const current = loadStoredBellList().filter(x => x.id !== entry.id);
    current.unshift({
      ...entry,
      created_at: Number(entry.created_at) || Date.now()
    });
    saveStoredBellList(current);
    renderBellNotifications();
  }

  function removeLeaveNotificationByRequestId(requestId) {
    const reqId = Number(requestId || 0);
    if (reqId > 0) {
      activePendingLeaveIds.delete(reqId);
    }
    let saved = [];
    try {
      const raw = sessionStorage.getItem(BELL_STORAGE_KEY);
      const parsed = raw ? JSON.parse(raw) : [];
      if (Array.isArray(parsed)) saved = parsed;
    } catch (_) {}

    const filtered = saved.filter(item => {
      if (!item) return false;
      if (reqId > 0 && Number(item.request_id || 0) === reqId) return false;
      return isItemStillValid(item);
    });
    saveStoredBellList(filtered);

    for (let i = dbOverdueLeaves.length - 1; i >= 0; i--) {
      if (reqId > 0 && Number(dbOverdueLeaves[i].request_id || 0) === reqId) {
        dbOverdueLeaves.splice(i, 1);
      }
    }
    renderBellNotifications();
  }

  function syncPendingLeaveIdsFromStats(data) {
    if (!data || typeof data !== 'object') return;
    if (Array.isArray(data.pending_leave_ids)) {
      activePendingLeaveIds = new Set(data.pending_leave_ids.map(Number).filter(n => n > 0));
      renderBellNotifications();
      return;
    }
    if (Array.isArray(data.pending_leaves)) {
      activePendingLeaveIds = new Set(
        data.pending_leaves.map(r => Number(r?.id ?? r?.request_id ?? 0)).filter(n => n > 0)
      );
      renderBellNotifications();
    }
  }

  function renderBellNotifications() {
    const items = loadStoredBellList();
    if (bellBadge) {
      bellBadge.textContent = String(items.length);
    }
    if (bellStatus) {
      const overdueCnt = items.filter(x => x.category === 'leave_2d').length;
      bellStatus.textContent = overdueCnt > 0
        ? `Real-time: ${overdueCnt} pending leave(s) 2+ days • Auto-clears on approve/reject`
        : 'Real-time monitoring active • Auto-clears on approve/reject';
    }

    if (!items.length) {
      bellList.innerHTML = `
        <div class="bell-notif-item">
          <div class="bell-notif-top">
            <span class="bell-notif-title">No Pending Leave Alerts</span>
            <span class="bell-notif-tag info">Live</span>
          </div>
          <p class="bell-notif-msg">Pending leave alerts appear here automatically and disappear once approved or rejected.</p>
        </div>
      `;
      return;
    }

    bellList.innerHTML = items.map(n => {
      const isLeave2d = (n.category || '') === 'leave_2d';
      const tagClass = isLeave2d ? 'bell-notif-tag' : 'bell-notif-tag info';
      const tagText = isLeave2d ? '2d+ Leave' : 'Live HR';
      const linkHtml = n.url ? `<a href="${escapeHtml(n.url)}" class="bell-notif-link">Review →</a>` : '';

      return `
        <div class="bell-notif-item">
          <div class="bell-notif-top">
            <span class="bell-notif-title">${escapeHtml(n.title || 'HR Notification')}</span>
            <span class="${tagClass}">${tagText}</span>
          </div>
          <p class="bell-notif-msg">${escapeHtml(n.message || '')}</p>
          <div class="bell-notif-meta">
            <span>${escapeHtml(formatRelativeTime(n.created_at))}</span>
            ${linkHtml}
          </div>
        </div>
      `;
    }).join('');
  }

  bellBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    const opening = !bellWin.classList.contains('is-open');
    bellWin.classList.toggle('is-open', opening);
    bellBtn.setAttribute('aria-expanded', String(opening));
    if (opening) {
      renderBellNotifications();
    }
  });

  document.addEventListener('click', (e) => {
    if (!bellWin.contains(e.target) && !bellBtn.contains(e.target)) {
      bellWin.classList.remove('is-open');
      bellBtn.setAttribute('aria-expanded', 'false');
    }
  });

  renderBellNotifications();

  // 1. Employee Overview Donut Chart
  const overviewCtx = document.getElementById('employeeOverviewChart').getContext('2d');
  const overviewChart = new Chart(overviewCtx, {
    type: 'doughnut',
    data: {
      labels: ['Active', 'On Leave'],
      datasets: [{
        data: [<?= (int)$activeEmployeeCount ?>, <?= (int)$onLeaveCount ?>],
        backgroundColor: ['#0ea5e9', '#f59e0b'],
        borderWidth: 0,
        hoverOffset: 4
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '72%',
      plugins: { legend: { display: false } }
    }
  });

  // 2. Daily Attendance Trend Line Chart
  const trendCtx = document.getElementById('attendanceTrendChart').getContext('2d');
  const trendChart = new Chart(trendCtx, {
    type: 'line',
    data: {
      labels: [],
      datasets: [
        {
          label: 'Present',
          data: [],
          borderColor: '#3b82f6',
          backgroundColor: 'rgba(59, 130, 246, 0.14)',
          pointBackgroundColor: '#3b82f6',
          pointBorderColor: '#3b82f6',
          pointRadius: 3.5,
          pointHoverRadius: 5,
          borderWidth: 2,
          fill: true,
          tension: 0.25
        },
        {
          label: 'Absent',
          data: [],
          borderColor: '#fb923c',
          backgroundColor: 'rgba(251, 146, 60, 0.08)',
          pointBackgroundColor: '#fb923c',
          pointBorderColor: '#fb923c',
          pointRadius: 3.5,
          pointHoverRadius: 5,
          borderWidth: 2,
          fill: false,
          tension: 0.25
        },
        {
          label: 'On Leave',
          data: [],
          borderColor: '#fbbf24',
          backgroundColor: 'rgba(251, 191, 36, 0.08)',
          pointBackgroundColor: '#fbbf24',
          pointBorderColor: '#fbbf24',
          pointRadius: 3.5,
          pointHoverRadius: 5,
          borderWidth: 2,
          fill: false,
          tension: 0.25
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          position: 'top',
          align: 'end',
          labels: {
            color: '#cbd5e1',
            usePointStyle: true,
            pointStyle: 'circle',
            boxWidth: 8,
            padding: 14,
            font: { size: 12, weight: '600' }
          }
        },
        tooltip: {
          callbacks: {
            label: (ctx) => `${ctx.dataset.label}: ${Number(ctx.parsed.y ?? 0).toFixed(1)}%`
          }
        }
      },
      scales: {
        y: {
          min: 0,
          max: 100,
          ticks: {
            stepSize: 25,
            color: '#94a3b8',
            font: { size: 11 },
            callback: (val) => `${val}%`
          },
          grid: { color: 'rgba(148, 163, 184, 0.14)' }
        },
        x: {
          ticks: { color: '#94a3b8', font: { size: 11 } },
          grid: { color: 'rgba(148, 163, 184, 0.08)' }
        }
      }
    }
  });

  const badgeEl       = document.getElementById('sse-badge');
  const statusEl      = document.getElementById('sse-status-text');
  const presentChipEl = document.getElementById('kpi-present-chip');
  const presentTitle  = document.getElementById('kpi-present-title');
  const donutDayLabel = document.getElementById('donut-day-label');
  const searchInput   = document.getElementById('top-dashboard-search');

  let activeFetchController = null;

  const statsEndpoints = [
    '/api/hr_dashboard_stats.php',
    '../api/hr_dashboard_stats.php',
    '/hr/api/hr_dashboard_stats.php'
  ];
  let resolvedStatsEndpoint = null;

  function setLiveState(isLive, label) {
    badgeEl.classList.toggle('is-reconnecting', !isLive);
    statusEl.textContent = label;
  }

  function formatYmd(offsetDays = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
  }

  function applyTrendChartData(trendObj, fallbackTotalEmployees, fallbackPresentCount, fallbackLeaveCount = 0) {
    const labels  = Array.isArray(trendObj?.labels) && trendObj.labels.length > 0 ? trendObj.labels : null;
    const present = Array.isArray(trendObj?.present) && trendObj.present.length > 0 ? trendObj.present : null;
    const absent  = Array.isArray(trendObj?.absent) && trendObj.absent.length > 0 ? trendObj.absent : null;
    const leaveSeries = Array.isArray(trendObj?.on_leave) && trendObj.on_leave.length > 0 ? trendObj.on_leave : null;

    if (labels && present && absent) {
      trendChart.data.labels = labels;
      trendChart.data.datasets[0].data = present.map(v => Number(v ?? 0));
      trendChart.data.datasets[1].data = absent.map(v => Number(v ?? 0));
      trendChart.data.datasets[2].data = leaveSeries
        ? leaveSeries.map(v => Number(v ?? 0))
        : labels.map((_, idx) => idx === labels.length - 1
            ? (fallbackTotalEmployees > 0 ? Math.min(100, Math.round((fallbackLeaveCount / fallbackTotalEmployees) * 1000) / 10) : 0)
            : 0);
    } else {
      const base = new Date(formatYmd(0) + 'T00:00:00');
      const generatedLabels = [];
      const generatedPresent = [];
      const generatedAbsent = [];
      const generatedLeave = [];
      const basePct = fallbackTotalEmployees > 0
        ? Math.min(100, Math.round((fallbackPresentCount / fallbackTotalEmployees) * 1000) / 10)
        : 0;
      const baseLeavePct = fallbackTotalEmployees > 0
        ? Math.min(100, Math.round((fallbackLeaveCount / fallbackTotalEmployees) * 1000) / 10)
        : 0;

      for (let i = -6; i <= 0; i++) {
        const d = new Date(base);
        d.setDate(base.getDate() + i);
        generatedLabels.push(d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
        const pVal = i === 0 ? basePct : 0;
        const lVal = i === 0 ? baseLeavePct : 0;
        const aVal = fallbackTotalEmployees > 0 ? Math.max(0, Math.round((100 - pVal) * 10) / 10) : 0;
        generatedPresent.push(pVal);
        generatedAbsent.push(aVal);
        generatedLeave.push(lVal);
      }

      trendChart.data.labels = generatedLabels;
      trendChart.data.datasets[0].data = generatedPresent;
      trendChart.data.datasets[1].data = generatedAbsent;
      trendChart.data.datasets[2].data = generatedLeave;
    }

    trendChart.update();

    const sum = trendObj?.summary || {};
    const lastIdx = trendChart.data.datasets[0].data.length - 1;
    const pRate = sum.selected_present_pct ?? sum.avg_present_pct ?? (lastIdx >= 0 ? trendChart.data.datasets[0].data[lastIdx] : 0);
    const aRate = sum.selected_absent_pct  ?? sum.avg_absent_pct  ?? (lastIdx >= 0 ? trendChart.data.datasets[1].data[lastIdx] : 0);
    const lRate = sum.selected_leave_pct   ?? sum.avg_leave_pct   ?? (lastIdx >= 0 ? trendChart.data.datasets[2].data[lastIdx] : 0);

    document.getElementById('trend-present-rating').textContent = `Present: ${Number(pRate).toFixed(1)}%`;
    document.getElementById('trend-absent-rating').textContent  = `Absent: ${Number(aRate).toFixed(1)}%`;
    document.getElementById('trend-leave-rating').textContent   = `Leave: ${Number(lRate).toFixed(1)}%`;
  }

  async function fetchStatsJson(selectedDate, signal) {
    const candidates = resolvedStatsEndpoint
      ? [resolvedStatsEndpoint, ...statsEndpoints.filter(u => u !== resolvedStatsEndpoint)]
      : statsEndpoints;

    let lastStatus = 404;
    for (const baseUrl of candidates) {
      const url = `${baseUrl}?date=${encodeURIComponent(selectedDate)}&_ts=${Date.now()}`;
      const res = await fetch(url, { cache: 'no-store', signal });
      if (res.status === 404) {
        lastStatus = 404;
        continue;
      }
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      resolvedStatsEndpoint = baseUrl;
      return await res.json();
    }
    throw new Error(`HTTP ${lastStatus}`);
  }

  async function refreshDashboardStats(overrideDate = null) {
    const selectedDate = overrideDate || formatYmd(0);
    presentChipEl.textContent = 'Today';
    presentTitle.textContent  = 'Present Today';
    donutDayLabel.textContent = 'Today';

    if (activeFetchController) {
      activeFetchController.abort();
    }
    activeFetchController = new AbortController();

    try {
      const data = await fetchStatsJson(selectedDate, activeFetchController.signal);
      if (!data || !data.kpis) {
        applyTrendChartData(null, <?= (int)$employeeCount ?>, <?= (int)$activeEmployeeCount ?>, <?= (int)$onLeaveCount ?>);
        return;
      }

      syncPendingLeaveIdsFromStats(data);

      const total   = Number(data.kpis.total_employees ?? 0);
      const active  = Number(data.overview?.active_today ?? data.kpis.present_today ?? 0);
      const leave   = Number(data.overview?.on_leave ?? data.kpis.on_leave ?? 0);
      const pending = Number(data.kpis.pending_requests ?? 0);

      document.getElementById('kpi-total-employees').textContent  = total.toLocaleString();
      document.getElementById('kpi-present-today').textContent    = active.toLocaleString();
      document.getElementById('kpi-on-leave').textContent         = leave.toLocaleString();
      document.getElementById('kpi-pending-requests').textContent = pending.toLocaleString();

      document.getElementById('top-msg-badge').textContent = String(active);

      document.getElementById('donut-total').textContent     = (active + leave).toLocaleString();
      document.getElementById('legend-active').textContent   = active.toLocaleString();
      document.getElementById('legend-on-leave').textContent = leave.toLocaleString();

      overviewChart.data.datasets[0].data = [active, leave];
      overviewChart.update();

      applyTrendChartData(data.attendance_trend, total, active, leave);
    } catch (err) {
      if (err && err.name === 'AbortError') return;
      applyTrendChartData(null, <?= (int)$employeeCount ?>, <?= (int)$activeEmployeeCount ?>, <?= (int)$onLeaveCount ?>);
    }
  }

  searchInput?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      const q = (searchInput.value || '').trim().toLowerCase();
      if (!q) return;
      if (q.includes('leave')) window.location.href = '/hr/leave_management.php';
      else if (q.includes('emp') || q.includes('add')) window.location.href = '/hr/add_employee.php';
      else if (q.includes('rep') || q.includes('audit')) window.location.href = '/hr/report.php';
      else if (q.includes('dept') || q.includes('set')) window.location.href = '/hr/department.php';
    }
  });

  function prependActivity(text, createdMs = Date.now()) {
    const listEl = document.getElementById('live-activities-list');
    const row = document.createElement('div');
    row.className = 'activity-row-item';
    row.dataset.createdAt = String(Number(createdMs) || Date.now());
    row.innerHTML = `
      <span><span class="dot teal" style="display:inline-block;margin-right:8px;"></span>${escapeHtml(text)}</span>
      <small class="muted activity-rel-time">${escapeHtml(formatRelativeTime(createdMs))}</small>
    `;
    listEl.prepend(row);
    while (listEl.children.length > 5) {
      listEl.removeChild(listEl.lastElementChild);
    }
  }

  function refreshAllRelativeTimestamps() {
    renderBellNotifications();
    document.querySelectorAll('#live-activities-list .activity-row-item[data-created-at]').forEach(row => {
      const timeEl = row.querySelector('.activity-rel-time');
      if (timeEl) {
        timeEl.textContent = formatRelativeTime(Number(row.dataset.createdAt));
      }
    });
  }

  async function fetchRealtimeToken() {
    const candidates = ['/api/realtime_token.php', '../api/realtime_token.php', '/realtime_token.php'];
    for (const url of candidates) {
      try {
        const res = await fetch(url, { cache: 'no-store' });
        if (!res.ok) continue;
        const json = await res.json();
        const token = json.token || (json.data && json.data.token) || '';
        if (token) return token;
      } catch (_) {}
    }
    throw new Error('Token unavailable');
  }

  let wsInstance = null;

  async function connectWebSocket() {
    setLiveState(false, 'Connecting...');
    try {
      const token = await fetchRealtimeToken();
      const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      wsInstance = new WebSocket(`${proto}//${window.location.hostname}:8090/ws?token=${encodeURIComponent(token)}`);

      wsInstance.onopen = () => {
        setLiveState(true, 'Live • WebSocket');
        refreshDashboardStats();
        renderBellNotifications();
      };

      wsInstance.onmessage = (msg) => {
        try {
          const packet = JSON.parse(msg.data);
          if (packet.type === 'event' && packet.event) {
            const ev = packet.event;
            const eventMs = ev.timestamp
              ? (typeof ev.timestamp === 'number' ? (ev.timestamp < 1e12 ? ev.timestamp * 1000 : ev.timestamp) : Date.parse(ev.timestamp) || Date.now())
              : Date.now();
            refreshDashboardStats();
            if (ev.type === 'leave.reminder') {
              const reqId = Number(ev.request_id || ev.id || 0);
              const emp = ev.employee || 'Employee';
              const days = ev.days_pending || ev.days || '2+';
              storeBellNotification({
                id: `ws_reminder_${reqId || Date.now()}_r${ev.reminder_count || 1}`,
                request_id: reqId,
                category: 'leave_2d',
                title: `Unprocessed Leave (${days}d): ${emp}`,
                message: ev.message || `Leave request #${reqId} is still pending approval/rejection.`,
                created_at: eventMs,
                url: '/hr/leave_management.php'
              });
              prependActivity(`Reminder: ${emp}'s leave #${reqId} pending (${days}d)`, eventMs);
            } else if (ev.type === 'attendance.time_in') {
              prependActivity(`${ev.employee || 'An employee'} timed in`, eventMs);
            } else if (ev.type === 'attendance.time_out') {
              prependActivity(`${ev.employee || 'An employee'} timed out (${ev.workhours ?? ''} hrs)`, eventMs);
            } else if (ev.type === 'attendance.break_time') {
              prependActivity(`${ev.employee || 'An employee'} went on break`, eventMs);
            } else if (ev.type === 'leave.filed') {
              const reqId = Number(ev.request_id || ev.id || 0);
              storeBellNotification({
                id: `ws_filed_${reqId || Date.now()}`,
                request_id: reqId,
                category: 'live',
                title: `New Leave Request: ${ev.employee || 'Employee'}`,
                message: ev.message || 'An employee filed a new leave request awaiting HR review.',
                created_at: eventMs,
                url: '/hr/leave_management.php'
              });
              prependActivity(`${ev.employee || 'An employee'} filed a leave request`, eventMs);
            } else if (ev.type === 'leave.approved') {
              removeLeaveNotificationByRequestId(ev.request_id || ev.id);
              prependActivity(`${ev.employee || 'An employee'}'s leave was approved`, eventMs);
            } else if (ev.type === 'leave.rejected') {
              removeLeaveNotificationByRequestId(ev.request_id || ev.id);
              prependActivity(`${ev.employee || 'An employee'}'s leave was rejected`, eventMs);
            } else if (ev.type === 'leave.cancelled') {
              removeLeaveNotificationByRequestId(ev.request_id || ev.id);
              prependActivity(`${ev.employee || 'An employee'} cancelled a leave request`, eventMs);
            }
          }
        } catch (_) {}
      };

      wsInstance.onclose = () => {
        setLiveState(false, 'Reconnecting...');
        setTimeout(connectWebSocket, 3000);
      };

      wsInstance.onerror = () => {
        wsInstance.close();
      };
    } catch (_) {
      setLiveState(false, 'Reconnecting...');
      setTimeout(connectWebSocket, 4000);
    }
  }

  setInterval(() => {
    refreshDashboardStats();
  }, 10000);

  setInterval(() => {
    refreshAllRelativeTimestamps();
  }, 15000);

  applyTrendChartData(null, <?= (int)$employeeCount ?>, <?= (int)$activeEmployeeCount ?>, <?= (int)$onLeaveCount ?>);
  refreshDashboardStats(formatYmd(0));
  connectWebSocket();
});
</script>