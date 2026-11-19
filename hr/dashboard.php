<?php
require_once __DIR__ . '/../authentication/guard.php';
require_access('dashboard', 'view', null, ['admin', 'hr']);
$title = 'Dashboard';

$employeeCount = $activeEmployeeCount = $presentTodayCount = $onLeaveCount = $pendingRequestCount = 0;

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
    }
} catch (Throwable $e) {}

$overviewTotal = $activeEmployeeCount + $onLeaveCount;

$reportDataReady = false;
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $REPORT_ALLOW_EXPORT = false;
        require_once __DIR__ . '/_report_data.php';
        $reportDataReady = true;
    }
} catch (Throwable $e) {}

require __DIR__ . '/../templates/hrTemplates/header.php';

function dashboardMetric($value): string {
    return $value === null ? '—' : number_format((int)$value);
}

$rawSessionName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
$displayProfileName = $rawSessionName !== '' ? ucwords($rawSessionName) : 'Donald Toñacao';
$hrName = ucfirst(explode(' ', trim((string)($_SESSION['username'] ?? 'Hr')))[0] ?: 'Hr');
$avatarInitials = strtoupper(substr($displayProfileName, 0, 1));
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

  /* Bell Notification & Cron Popover Window */
  .bell-popover-wrap {
    position: relative;
  }
  .bell-popover-window {
    display: none;
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 360px;
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
  .cron-run-btn {
    padding: 5px 10px;
    border-radius: 8px;
    border: 1px solid rgba(96, 165, 250, 0.45);
    background: #2563eb;
    color: #ffffff;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
  }
  .cron-run-btn:hover {
    background: #1d4ed8;
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
    background: rgba(59, 130, 246, 0.18);
    color: #60a5fa;
  }
  .bell-notif-tag.cron {
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
    margin-bottom: 18px;
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
  .day-filter-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
  }
  .day-date-input {
    padding: 7px 11px;
    border-radius: 8px;
    border: 1px solid rgba(96, 165, 250, 0.45);
    background: rgba(15, 23, 42, 0.8);
    color: #f8fafc;
    font-size: 13px;
    font-weight: 600;
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
    <!-- Bell Icon + Small Window Popover for HR & Cron Job Notifications -->
    <div class="bell-popover-wrap">
      <button type="button" id="bell-icon-btn" class="icon-badge-btn" title="HR & Cron Notifications" aria-expanded="false">
        <span>🔔</span>
        <span class="icon-badge-count" id="top-notif-badge"><?= (int)$pendingRequestCount ?></span>
      </button>

      <div class="bell-popover-window" id="bell-popover-window" role="dialog" aria-label="HR Notifications">
        <div class="bell-popover-head">
          <strong>HR &amp; Cron Notifications</strong>
          <button type="button" class="cron-run-btn" id="btn-run-cron-now">▶ Run Cron</button>
        </div>
        <div class="bell-popover-body" id="bell-popover-list">
          <div class="bell-notif-item">
            <div class="bell-notif-top">
              <span class="bell-notif-title">Cron Job Monitor</span>
              <span class="bell-notif-tag cron">Cron</span>
            </div>
            <p class="bell-notif-msg">Click the bell or Run Cron to check automated HR job status.</p>
          </div>
        </div>
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

<!-- Row 4: Calendar Daily Employee Attendance & Leave Tracker + Day Report -->
<article class="card" style="margin-bottom: 24px;">
  <div class="card-heading" style="flex-wrap: wrap; gap: 12px;">
    <div>
      <p class="eyebrow">DAILY ATTENDANCE &amp; REPORT</p>
      <h2 id="daily-attendance-heading">Employee Attendance (Today)</h2>
    </div>
    <div class="day-filter-bar">
      <label for="attendance-date-picker" style="font-size:12.5px;color:#94a3b8;font-weight:600;">Calendar Date:</label>
      <input
        type="date"
        id="attendance-date-picker"
        class="day-date-input"
        value="<?= htmlspecialchars(date('Y-m-d')) ?>"
      >
      <button type="button" class="button" id="btn-day-today">Today</button>
      <button type="button" class="button" id="btn-day-yesterday">Yesterday</button>
      <a
        class="button"
        id="download-day-report"
        href="/api/hr_dashboard_stats.php?export=csv&date=<?= htmlspecialchars(date('Y-m-d')) ?>"
      >⬇ Download Report (CSV)</a>
    </div>
  </div>

  <div style="overflow-x: auto; margin-top: 8px;">
    <table style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
      <thead>
        <tr style="border-bottom: 1px solid rgba(148,163,184,0.2); text-align: left; color: #94a3b8;">
          <th style="padding: 10px 8px;">Employee ID</th>
          <th style="padding: 10px 8px;">Name</th>
          <th style="padding: 10px 8px;">Position</th>
          <th style="padding: 10px 8px;">Department</th>
          <th style="padding: 10px 8px;">Status</th>
          <th style="padding: 10px 8px;">Time In</th>
          <th style="padding: 10px 8px;">Time Out</th>
          <th style="padding: 10px 8px;">Work Hours</th>
        </tr>
      </thead>
      <tbody id="daily-attendance-tbody">
        <tr>
          <td colspan="8" style="padding: 14px 8px; color: #94a3b8;">Loading attendance records...</td>
        </tr>
      </tbody>
    </table>
  </div>
</article>

<!-- Preserved Embedded Reports Section -->
<?php if ($reportDataReady): ?>
<section id="reports-section" class="dashboard-reports">
  <div class="card-heading" style="margin-top:8px">
    <div>
      <p class="eyebrow">ANALYTICS</p>
      <h2>Reports &amp; Audits</h2>
    </div>
    <a class="button" href="/hr/report.php">Open full reports →</a>
  </div>
  <?php
    $reportEmbedded = true;
    require __DIR__ . '/_report_section.php';
  ?>
</section>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  // Bell Icon Popover Window + Cron Job Logic
  const bellBtn = document.getElementById('bell-icon-btn');
  const bellWin = document.getElementById('bell-popover-window');
  const bellList = document.getElementById('bell-popover-list');
  const runCronBtn = document.getElementById('btn-run-cron-now');

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
  }

  function renderBellNotifications(items) {
    if (!Array.isArray(items) || items.length === 0) {
      bellList.innerHTML = `<div class="bell-notif-item"><p class="bell-notif-msg">No HR or cron job notifications right now.</p></div>`;
      return;
    }

    bellList.innerHTML = items.map(n => {
      const isCron = (n.category || '').toLowerCase() === 'cron';
      const tagClass = isCron ? 'bell-notif-tag cron' : 'bell-notif-tag';
      const tagText = isCron ? 'Cron Job' : 'HR Alert';
      const linkHtml = n.url ? `<a href="${escapeHtml(n.url)}" class="bell-notif-link">Open →</a>` : '';

      return `
        <div class="bell-notif-item">
          <div class="bell-notif-top">
            <span class="bell-notif-title">${escapeHtml(n.title || 'Notification')}</span>
            <span class="${tagClass}">${tagText}</span>
          </div>
          <p class="bell-notif-msg">${escapeHtml(n.message || '')}</p>
          <div class="bell-notif-meta">
            <span>${escapeHtml(n.time || '')}</span>
            ${linkHtml}
          </div>
        </div>
      `;
    }).join('');
  }

  async function loadCronNotifications(runCron = false) {
    try {
      const qs = runCron ? '?action=run_cron&_ts=' + Date.now() : '?action=list&_ts=' + Date.now();
      const res = await fetch('/api/hr_cron_notifications.php' + qs, { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      if (data && Array.isArray(data.notifications)) {
        renderBellNotifications(data.notifications);
      }
      if (runCron && typeof refreshDashboardStats === 'function') {
        refreshDashboardStats();
      }
    } catch (_) {}
  }

  bellBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    const opening = !bellWin.classList.contains('is-open');
    bellWin.classList.toggle('is-open', opening);
    bellBtn.setAttribute('aria-expanded', String(opening));
    if (opening) {
      loadCronNotifications(false);
    }
  });

  runCronBtn?.addEventListener('click', async (e) => {
    e.stopPropagation();
    runCronBtn.disabled = true;
    runCronBtn.textContent = 'Running...';
    await loadCronNotifications(true);
    runCronBtn.disabled = false;
    runCronBtn.textContent = '▶ Run Cron';
  });

  document.addEventListener('click', (e) => {
    if (!bellWin.contains(e.target) && !bellBtn.contains(e.target)) {
      bellWin.classList.remove('is-open');
      bellBtn.setAttribute('aria-expanded', 'false');
    }
  });

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
  const datePicker    = document.getElementById('attendance-date-picker');
  const exportLink    = document.getElementById('download-day-report');
  const tbodyEl       = document.getElementById('daily-attendance-tbody');
  const headingEl     = document.getElementById('daily-attendance-heading');
  const presentChipEl = document.getElementById('kpi-present-chip');
  const presentTitle  = document.getElementById('kpi-present-title');
  const donutDayLabel = document.getElementById('donut-day-label');
  const searchInput   = document.getElementById('top-dashboard-search');

  let cachedDailyRows = [];
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

  function describeSelectedDate(ymd) {
    const today = formatYmd(0);
    const yesterday = formatYmd(-1);
    if (ymd === today) return 'Today';
    if (ymd === yesterday) return 'Yesterday';
    return ymd;
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
      const base = new Date((datePicker.value || formatYmd(0)) + 'T00:00:00');
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

  function renderDailyAttendance(rows) {
    const query = (searchInput?.value || '').trim().toLowerCase();
    const filtered = query
      ? (rows || []).filter(r =>
          String(r.employee_id ?? '').toLowerCase().includes(query) ||
          String(r.employee_name ?? '').toLowerCase().includes(query) ||
          String(r.position_name ?? '').toLowerCase().includes(query) ||
          String(r.department_name ?? '').toLowerCase().includes(query) ||
          String(r.day_status ?? '').toLowerCase().includes(query)
        )
      : (rows || []);

    if (!Array.isArray(filtered) || filtered.length === 0) {
      tbodyEl.innerHTML = `<tr><td colspan="8" style="padding:14px 8px;color:#94a3b8;">No employee records found for this date.</td></tr>`;
      return;
    }

    tbodyEl.innerHTML = filtered.map(r => {
      const status = r.day_status || 'Absent';
      let badgeColor = '#f87171';
      if (status === 'Active') badgeColor = '#34d399';
      else if (status === 'Present') badgeColor = '#60a5fa';
      else if (status === 'On Leave') badgeColor = '#fbbf24';

      return `
        <tr style="border-bottom:1px solid rgba(148,163,184,0.12);">
          <td style="padding:10px 8px;"><strong>#${escapeHtml(r.employee_id ?? '')}</strong></td>
          <td style="padding:10px 8px;"><strong style="color:#f8fafc;">${escapeHtml(r.employee_name || ('Employee #' + (r.employee_id ?? '')))}</strong></td>
          <td style="padding:10px 8px;">${escapeHtml(r.position_name || 'Unassigned')}</td>
          <td style="padding:10px 8px;">${escapeHtml(r.department_name || 'Unassigned')}</td>
          <td style="padding:10px 8px;">
            <span style="display:inline-flex;align-items:center;gap:6px;font-weight:600;color:${badgeColor};">
              <span style="width:7px;height:7px;border-radius:50%;background:${badgeColor};"></span>
              ${escapeHtml(status)}
            </span>
          </td>
          <td style="padding:10px 8px;">${escapeHtml(r.time_in || '—')}</td>
          <td style="padding:10px 8px;">${escapeHtml(r.time_out || '—')}</td>
          <td style="padding:10px 8px;"><strong>${r.workhours !== null && r.workhours !== undefined && r.workhours !== '' ? escapeHtml(r.workhours) + ' hrs' : '—'}</strong></td>
        </tr>
      `;
    }).join('');
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
      exportLink.href = `${baseUrl}?export=csv&date=${encodeURIComponent(selectedDate)}`;
      return await res.json();
    }
    throw new Error(`HTTP ${lastStatus}`);
  }

  async function refreshDashboardStats(overrideDate = null) {
    const selectedDate = overrideDate || datePicker.value || formatYmd(0);
    datePicker.value = selectedDate;

    const label = describeSelectedDate(selectedDate);
    headingEl.textContent     = `Employee Attendance (${label})`;
    presentChipEl.textContent = label;
    presentTitle.textContent  = selectedDate === formatYmd(0) ? 'Present Today' : `Present (${label})`;
    donutDayLabel.textContent = label;
    exportLink.href = `${resolvedStatsEndpoint || '/api/hr_dashboard_stats.php'}?export=csv&date=${encodeURIComponent(selectedDate)}`;

    if (activeFetchController) {
      activeFetchController.abort();
    }
    activeFetchController = new AbortController();

    try {
      const data = await fetchStatsJson(selectedDate, activeFetchController.signal);
      if (!data || !data.kpis) {
        applyTrendChartData(null, <?= (int)$employeeCount ?>, <?= (int)$activeEmployeeCount ?>, <?= (int)$onLeaveCount ?>);
        tbodyEl.innerHTML = `<tr><td colspan="8" style="padding:14px 8px;color:#f87171;">Invalid response from attendance API.</td></tr>`;
        return;
      }

      const total   = Number(data.kpis.total_employees ?? 0);
      const active  = Number(data.overview?.active_today ?? data.kpis.present_today ?? 0);
      const leave   = Number(data.overview?.on_leave ?? data.kpis.on_leave ?? 0);
      const pending = Number(data.kpis.pending_requests ?? 0);

      document.getElementById('kpi-total-employees').textContent  = total.toLocaleString();
      document.getElementById('kpi-present-today').textContent    = active.toLocaleString();
      document.getElementById('kpi-on-leave').textContent         = leave.toLocaleString();
      document.getElementById('kpi-pending-requests').textContent = pending.toLocaleString();

      document.getElementById('top-notif-badge').textContent = String(pending);
      document.getElementById('top-msg-badge').textContent   = String(active);

      document.getElementById('donut-total').textContent     = (active + leave).toLocaleString();
      document.getElementById('legend-active').textContent   = active.toLocaleString();
      document.getElementById('legend-on-leave').textContent = leave.toLocaleString();

      overviewChart.data.datasets[0].data = [active, leave];
      overviewChart.update();

      applyTrendChartData(data.attendance_trend, total, active, leave);

      cachedDailyRows = Array.isArray(data.daily_attendance) ? data.daily_attendance : [];
      renderDailyAttendance(cachedDailyRows);
    } catch (err) {
      if (err && err.name === 'AbortError') return;
      applyTrendChartData(null, <?= (int)$employeeCount ?>, <?= (int)$activeEmployeeCount ?>, <?= (int)$onLeaveCount ?>);
      tbodyEl.innerHTML = `<tr><td colspan="8" style="padding:14px 8px;color:#f87171;">Failed to load records (${escapeHtml(err?.message || 'Error')}). Make sure hr_dashboard_stats.php exists in /api/.</td></tr>`;
    }
  }

  document.getElementById('btn-day-today').addEventListener('click', () => refreshDashboardStats(formatYmd(0)));
  document.getElementById('btn-day-yesterday').addEventListener('click', () => refreshDashboardStats(formatYmd(-1)));
  datePicker.addEventListener('change', () => refreshDashboardStats(datePicker.value || formatYmd(0)));
  searchInput?.addEventListener('input', () => renderDailyAttendance(cachedDailyRows));

  function prependActivity(text, timeLabel) {
    const listEl = document.getElementById('live-activities-list');
    const row = document.createElement('div');
    row.className = 'activity-row-item';
    row.innerHTML = `
      <span><span class="dot teal" style="display:inline-block;margin-right:8px;"></span>${escapeHtml(text)}</span>
      <small class="muted">${escapeHtml(timeLabel)}</small>
    `;
    listEl.prepend(row);
    while (listEl.children.length > 5) {
      listEl.removeChild(listEl.lastElementChild);
    }
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
      };

      wsInstance.onmessage = (msg) => {
        try {
          const packet = JSON.parse(msg.data);
          if (packet.type === 'event' && packet.event) {
            const ev = packet.event;
            refreshDashboardStats();
            loadCronNotifications(false);
            if (ev.type === 'attendance.time_in') {
              prependActivity(`${ev.employee || 'An employee'} timed in`, 'Just now');
            } else if (ev.type === 'attendance.time_out') {
              prependActivity(`${ev.employee || 'An employee'} timed out (${ev.workhours ?? ''} hrs)`, 'Just now');
            } else if (ev.type === 'attendance.break_time') {
              prependActivity(`${ev.employee || 'An employee'} went on break`, 'Just now');
            } else if (ev.type === 'leave.filed') {
              prependActivity(`${ev.employee || 'An employee'} filed a leave request`, 'Just now');
            } else if (ev.type === 'leave.approved') {
              prependActivity(`${ev.employee || 'An employee'}'s leave was approved`, 'Just now');
            } else if (ev.type === 'leave.rejected') {
              prependActivity(`${ev.employee || 'An employee'}'s leave was rejected`, 'Just now');
            } else if (ev.type === 'leave.cancelled') {
              prependActivity(`${ev.employee || 'An employee'} cancelled a leave request`, 'Just now');
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

  refreshDashboardStats(formatYmd(0));
  loadCronNotifications(false);
  connectWebSocket();
});
</script>
```[cite: 3, 5, 6, 8, 9, 10]