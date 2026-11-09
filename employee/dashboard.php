<?php
session_start();

require_once __DIR__ . '/../config/database.php';
// Prevent browser from displaying a cached dashboard after logout
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Logged in, account still active, employee portal, allowed by the permission matrix
require_once __DIR__ . '/../authentication/guard.php';
require_access('dashboard', 'view', null, ['employee']);

// Get the employee ID saved during login
$employeeId = $_SESSION['employee_id'] ?? null;

if (!$employeeId) {
    exit('No employee record is linked to this user account.');
}

// Use the same employees table as the login query
$stmt = $pdo->prepare("
    SELECT id, email, first_name, last_name
    FROM employees
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $employeeId]);

$employeeData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employeeData) {
    exit('Employee record not found. Check users.employee_id and employees.id.');
}

$userEmail = $employeeData['email'];
$firstName = $employeeData['first_name'] ?? '';
$lastName  = $employeeData['last_name'] ?? '';
$fullName  = trim($firstName . ' ' . $lastName);

$user = [
    'first_name' => htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'),
    'last_name'  => htmlspecialchars($lastName,  ENT_QUOTES, 'UTF-8'),
    'full_name'  => htmlspecialchars($fullName,  ENT_QUOTES, 'UTF-8'),
    'email'      => htmlspecialchars($userEmail ?? '', ENT_QUOTES, 'UTF-8'),
    'role'       => 'Employee',
    'avatar'     => 'https://ui-avatars.com/api/?name='
                    . urlencode($fullName)
                    . '&background=4763ff&color=fff'
];

// Fetch Real-Time Attendance & Calculate Total Weekly Hours
$stmtAtt = $pdo->prepare("SELECT time_in, time_out FROM attendance WHERE email = :email ORDER BY time_in DESC LIMIT 1");
$stmtAtt->execute(['email' => $userEmail]);
$attendanceData = $stmtAtt->fetch();

$stmtWeekly = $pdo->prepare("SELECT SUM(workhours) as total_hours FROM attendance WHERE email = :email");
$stmtWeekly->execute(['email' => $userEmail]);
$weeklyTotalResult = $stmtWeekly->fetch();
$totalWeeklyHours  = $weeklyTotalResult['total_hours'] ? (float) $weeklyTotalResult['total_hours'] : 0;

$todayLogText    = 'No logs yet';
$progressPercent = min(100, round(($totalWeeklyHours / 40) * 100));

if ($attendanceData) {
    $timeIn      = new DateTime($attendanceData['time_in']);
    $timeOutText = 'Current';
    if (!empty($attendanceData['time_out'])) {
        $timeOut     = new DateTime($attendanceData['time_out']);
        $timeOutText = $timeOut->format('h:i A');
    }
    $todayLogText = $timeIn->format('h:i A') . ' - ' . $timeOutText;
}

// Fetch Real-Time Leave Balances from the 'leave' table
$stmtLeave = $pdo->prepare("SELECT annual_leave, sick_leave, personal_leave FROM `leave` WHERE email = :email LIMIT 1");
$stmtLeave->execute(['email' => $userEmail]);
$leaveData = $stmtLeave->fetch();

$timeOffBalances = [
    'annual'   => $leaveData ? $leaveData['annual_leave']   : 0,
    'sick'     => $leaveData ? $leaveData['sick_leave']     : 0,
    'personal' => $leaveData ? $leaveData['personal_leave'] : 0,
];

// Dynamic Philippine Holidays for the current year
$currentYear = date('Y');
$phHolidays  = [
    ['name' => 'New Year\'s Day',       'date' => "January 1, {$currentYear}",   'type' => 'Regular Holiday'],
    ['name' => 'Araw ng Kagitingan',    'date' => "April 9, {$currentYear}",     'type' => 'Regular Holiday'],
    ['name' => 'Labor Day',             'date' => "May 1, {$currentYear}",       'type' => 'Regular Holiday'],
    ['name' => 'Independence Day',      'date' => "June 12, {$currentYear}",     'type' => 'Regular Holiday'],
    ['name' => 'National Heroes Day',   'date' => "August 31, {$currentYear}",   'type' => 'Regular Holiday'],
    ['name' => 'All Saints\' Day',      'date' => "November 1, {$currentYear}",  'type' => 'Special Non-Working'],
    ['name' => 'Bonifacio Day',         'date' => "November 30, {$currentYear}", 'type' => 'Regular Holiday'],
    ['name' => 'Christmas Day',         'date' => "December 25, {$currentYear}", 'type' => 'Regular Holiday'],
    ['name' => 'Rizal Day',             'date' => "December 30, {$currentYear}", 'type' => 'Regular Holiday'],
];

$todayObj        = new DateTime();
$upcomingHolidays = [];
foreach ($phHolidays as $hol) {
    $holDate = DateTime::createFromFormat('F j, Y', $hol['date']);
    if ($holDate && $holDate >= $todayObj) {
        $upcomingHolidays[] = [
            'name' => $hol['name'],
            'date' => $holDate->format('l, M j, Y'),
            'type' => $hol['type'],
        ];
    }
    if (count($upcomingHolidays) >= 3) break;
}

if (empty($upcomingHolidays)) {
    $upcomingHolidays[] = [
        'name' => 'New Year\'s Day',
        'date' => 'January 1, ' . ($currentYear + 1),
        'type' => 'Regular Holiday',
    ];
}

/* ---------------------------------------------------------------
 * Most recent payslip for this employee
 * Joins payroll_periods so we can show the period label + dates.
 * ------------------------------------------------------------- */
$recentPayslip = null;
try {
    $psStmt = $pdo->prepare("
        SELECT
            ps.id,
            ps.gross_pay,
            ps.total_deductions,
            ps.net_pay,
            ps.status,
            pp.period_name AS period_label,
            pp.start_date AS period_start,
            pp.end_date   AS period_end
        FROM payslips ps
        JOIN payroll_periods pp ON pp.id = ps.payroll_period_id
        WHERE ps.employee_id = :eid
        ORDER BY pp.start_date DESC
        LIMIT 1
    ");
    $psStmt->execute([':eid' => $employeeId]);
    $recentPayslip = $psStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('Dashboard payslip query failed: ' . $e->getMessage());
}

// Pill helper (same as payslips.php)
function dashPayslipPill(string $status): array {
    return match ($status) {
        'paid'      => ['pill-active',  'Paid'],
        'finalized' => ['pill-warning', 'Finalized'],
        'generated' => ['pill-info',    'Generated'],
        default     => ['pill-neutral', 'Pending'],
    };
}

// Shell configuration
$title           = 'Employee Dashboard';
$eyebrow         = 'OVERVIEW';
$subtitle        = 'Your attendance, leave, and pay at a glance';
$pageCss         = 'emp_dashboard';
$realtime        = true;
$realtimeRefresh = 'prompt';
require __DIR__ . '/../templates/employeeTemplates/header.php';
?>

<?php
  $hour     = (int) date('G');
  $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<section class="hero-banner">
  <div class="hero-content">
    <p class="eyebrow">EMPLOYEE DASHBOARD</p>
    <h1 class="welcome-hero">
      <span class="w-part"><?= $greeting ?>,</span>
      <span class="w-part welcome-name"><?= $user['first_name'] ?></span>
      <span class="w-part welcome-wave" aria-hidden="true">👋</span>
    </h1>
    <p class="muted welcome-sub">
      Your latest recorded shift started at <?= $attendanceData ? (new DateTime($attendanceData['time_in']))->format('h:i A') : 'N/A' ?>.
      Remember to complete your weekly self-assessment by Saturday.
    </p>
    <div class="hero-actions">
      <a class="primary-button" href="/employee/file_leave.php">＋ Request Time Off</a>
      <a class="button" href="/employee/attendance.php">View attendance</a>
    </div>
  </div>
  <div class="hero-orb" aria-hidden="true"></div>
</section>

<div class="metric-strip">
  <article class="metric-card metric-teal">
    <div class="metric-top"><span class="metric-ico">◷</span><span class="metric-chip"><?= $progressPercent ?>% of 40h</span></div>
    <span class="metric-title">Weekly Hours</span>
    <strong class="metric-number teal"><?= number_format($totalWeeklyHours, 1) ?></strong>
    <div class="metric-bar"><span style="width:<?= (int)$progressPercent ?>%"></span></div>
  </article>
  <article class="metric-card metric-blue">
    <div class="metric-top"><span class="metric-ico">☀</span><span class="metric-chip">Annual</span></div>
    <span class="metric-title">Annual Leave</span>
    <strong class="metric-number blue"><?= (int)$timeOffBalances['annual'] ?></strong>
    <span class="metric-description">Days available</span>
  </article>
  <article class="metric-card metric-violet">
    <div class="metric-top"><span class="metric-ico">✚</span><span class="metric-chip">Sick</span></div>
    <span class="metric-title">Sick Leave</span>
    <strong class="metric-number purple"><?= (int)$timeOffBalances['sick'] ?></strong>
    <span class="metric-description">Days available</span>
  </article>
  <article class="metric-card metric-green">
    <div class="metric-top"><span class="metric-ico">★</span><span class="metric-chip">Personal</span></div>
    <span class="metric-title">Personal Leave</span>
    <strong class="metric-number green"><?= (int)$timeOffBalances['personal'] ?></strong>
    <span class="metric-description">Days available</span>
  </article>
</div>

<div class="dashboard-grid">
  <div class="main-col">
    <article class="card">
      <div class="card-heading"><div><p class="eyebrow">THIS WEEK</p><h2>Attendance summary</h2></div></div>
      <div class="mini-grid">
        <div class="mini-stat">
          <span class="mini-label">Today's Log</span>
          <strong class="mini-value"><?= htmlspecialchars($todayLogText) ?></strong>
        </div>
        <div class="mini-stat">
          <span class="mini-label">Weekly Total</span>
          <strong class="mini-value teal"><?= number_format($totalWeeklyHours, 1) ?> hrs</strong>
        </div>
      </div>
      <div class="progress-block">
        <div class="progress-head"><span class="muted">Progress to 40h target</span><strong><?= $progressPercent ?>%</strong></div>
        <div class="metric-bar"><span style="width:<?= (int)$progressPercent ?>%"></span></div>
      </div>
    </article>

    <article class="card">
      <div class="card-heading"><div><p class="eyebrow">CALENDAR</p><h2>Upcoming Philippine holidays</h2></div></div>
      <ul class="holiday-list">
        <?php foreach ($upcomingHolidays as $holiday): ?>
          <li class="holiday-item">
            <div>
              <strong><?= htmlspecialchars($holiday['name']) ?></strong>
              <small class="muted"><?= htmlspecialchars($holiday['date']) ?></small>
            </div>
            <span class="pill pill-neutral"><?= htmlspecialchars($holiday['type']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </article>
  </div>

  <aside class="side-col">
    <article class="card">
      <div class="card-heading"><div><p class="eyebrow">WORKSPACE</p><h2>Quick actions</h2></div></div>
      <div class="quick-stack">
        <a class="primary-button" href="/employee/file_leave.php" style="width:100%">Request Time Off</a>
        <a class="button" href="/employee/leave_history.php" style="width:100%">View Leave History</a>
        <a class="button" href="/employee/profile.php" style="width:100%">Edit My Profile</a>
      </div>
    </article>

    <!-- ── PAYSLIP CARD ─────────────────────────────────────── -->
    <article class="card">
      <div class="card-heading">
        <div><p class="eyebrow">PAYROLL</p><h2>Recent payslip</h2></div>
      </div>

      <?php if ($recentPayslip): ?>
        <?php
          [$pillClass, $pillLabel] = dashPayslipPill($recentPayslip['status']);
          $dateRange = date('M j', strtotime($recentPayslip['period_start']))
                     . ' – '
                     . date('M j, Y', strtotime($recentPayslip['period_end']));
        ?>
        <div class="payslip-row">
          <span class="muted">Pay Period</span>
          <strong><?= htmlspecialchars($recentPayslip['period_label'], ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
        <div class="payslip-row">
          <span class="muted">Dates</span>
          <span><?= htmlspecialchars($dateRange) ?></span>
        </div>
        <div class="payslip-row">
          <span class="muted">Gross Pay</span>
          <span>₱<?= number_format((float) $recentPayslip['gross_pay'], 2) ?></span>
        </div>
        <div class="payslip-row">
          <span class="muted">Deductions</span>
          <span class="muted">– ₱<?= number_format((float) $recentPayslip['total_deductions'], 2) ?></span>
        </div>
        <div class="payslip-row" style="margin-top:4px;border-top:1px solid var(--border);padding-top:10px">
          <span class="muted">Net Pay</span>
          <strong class="teal" style="font-size:16px">
            ₱<?= number_format((float) $recentPayslip['net_pay'], 2) ?>
          </strong>
        </div>
        <div class="payslip-row" style="margin-top:8px">
          <span class="muted">Status</span>
          <span class="pill <?= $pillClass ?>"><?= $pillLabel ?></span>
        </div>
        <div class="quick-stack" style="margin-top:14px">
          <a class="primary-button"
             href="/employee/payslip_view.php?id=<?= (int) $recentPayslip['id'] ?>"
             style="width:100%;text-align:center">
            View Payslip
          </a>
          <a class="button" href="/employee/payslips.php" style="width:100%;text-align:center">
            All Payslips
          </a>
        </div>

      <?php else: ?>
        <p class="muted" style="margin:6px 0 4px">No payslip released yet.</p>
        <p class="muted" style="font-size:12px">
          Once HR generates a pay period that covers your records,
          your most recent payslip will appear here.
        </p>
        <div class="quick-stack" style="margin-top:14px">
          <a class="button" href="/employee/payslips.php" style="width:100%;text-align:center">
            View All Payslips
          </a>
        </div>
      <?php endif; ?>
    </article>
    <!-- ── END PAYSLIP CARD ──────────────────────────────────── -->

  </aside>
</div>

<?php require __DIR__ . '/../templates/employeeTemplates/footer.php'; ?>