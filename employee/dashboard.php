<?php
// employee/dashboard.php

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Rbac.php';
require_once __DIR__ . '/../classes/AttendanceService.php';
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
    SELECT
    id,
    email,
    first_name,
    last_name,
    `rank`
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
  'role' => ucwords(
    str_replace(
      '_',
      ' ',
      Rbac::normalizeRank(
        $employeeData['rank'] ?? 'employee'
      )
    )
  ),
  'avatar'     => 'https://ui-avatars.com/api/?name='
    . urlencode($fullName)
    . '&background=4763ff&color=fff'
];

function dashEsc($value): string
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ---------------------------------------------------------------
 * Attendance, hours and overtime
 * Local (Asia/Manila) shift dates are used everywhere so a shift
 * never splits across midnight.
 * ------------------------------------------------------------- */
$nowDt     = AttendanceService::now();
$tz        = AttendanceService::tz();
$todayKey  = $nowDt->format('Y-m-d');
$monthKey  = $nowDt->format('Y-m');
$empIdInt  = (int)$employeeId;
$emailStr  = (string)($userEmail ?? '');

// Flags any forgotten Time Out as MISSING_OUT first, then returns the running shift.
$activeShift = AttendanceService::findActiveShift($pdo, $empIdInt, $emailStr, $nowDt);
$shiftCfg    = AttendanceService::getShiftConfig($pdo, $empIdInt);
$stdHours    = $shiftCfg['standard_hours'];

$attStmt = $pdo->prepare("
    SELECT id, time_in, time_out, shift_date, workhours, status,
           regular_hours, overtime_hours, overtime_status
    FROM attendance
    WHERE (employee_id = :eid OR email = :email)
      AND time_in >= :since
    ORDER BY time_in DESC
");
$attStmt->execute([
  ':eid'   => $empIdInt,
  ':email' => $emailStr,
  ':since' => $nowDt->format('Y') . '-01-01 00:00:00',
]);
$attRows = $attStmt->fetchAll(PDO::FETCH_ASSOC);

$kpi = ['regular' => 0.0, 'overtime' => 0.0, 'ot_pending' => 0.0, 'days' => 0, 'late' => 0, 'missing' => 0, 'on_time' => 0];
$dailyHours    = [];   // day of month => [regular, overtime]
$dayMap        = [];   // Y-m-d => details for the calendar
$missingShifts = [];
$weeklyHours   = 0.0;
$weekStart     = $nowDt->modify('monday this week')->format('Y-m-d');

foreach ($attRows as $r) {
  $status = AttendanceService::effectiveStatus($r);
  $date   = !empty($r['shift_date']) ? (string)$r['shift_date'] : substr((string)$r['time_in'], 0, 10);
  $hrs    = AttendanceService::rowHours($r, $stdHours);

  $in      = new DateTimeImmutable((string)$r['time_in'], $tz);
  $startAt = new DateTimeImmutable($date . ' ' . $shiftCfg['start_time'], $tz);
  $isLate  = intdiv($in->getTimestamp() - $startAt->getTimestamp(), 60) > 0;

  // Rows are newest first, so the first row seen for a date is its latest shift.
  if (!isset($dayMap[$date])) {
    $dayMap[$date] = [
      'in'        => $in->format('h:i A'),
      'out'       => !empty($r['time_out']) ? (new DateTimeImmutable((string)$r['time_out'], $tz))->format('h:i A') : null,
      'status'    => $status,
      'regular'   => $hrs['regular'],
      'overtime'  => $hrs['overtime'],
      'ot_status' => $r['overtime_status'] ?? null,
      'late'      => $isLate,
    ];
  }

  if ($status === AttendanceService::STATUS_MISSING_OUT) {
    $missingShifts[] = $date;
  }

  if ($date >= $weekStart) {
    $weeklyHours += (float)($r['workhours'] ?? 0);
  }

  if (substr($date, 0, 7) === $monthKey) {
    $kpi['days']++;
    $kpi['regular']  += $hrs['regular'];
    $kpi['overtime'] += $hrs['overtime'];
    if (($r['overtime_status'] ?? null) === AttendanceService::OT_PENDING_APPROVAL) {
      $kpi['ot_pending'] += $hrs['overtime'];
    }
    if ($status === AttendanceService::STATUS_MISSING_OUT) {
      $kpi['missing']++;
    } elseif ($isLate) {
      $kpi['late']++;
    } else {
      $kpi['on_time']++;
    }
    $d = (int)substr($date, 8, 2);
    $dailyHours[$d][0] = ($dailyHours[$d][0] ?? 0) + $hrs['regular'];
    $dailyHours[$d][1] = ($dailyHours[$d][1] ?? 0) + $hrs['overtime'];
  }
}

$recentRows = array_slice($attRows, 0, 8);

// Chart series: one bar per day of the month so far.
$chartLabels = $chartRegular = $chartOvertime = [];
$daysSoFar   = (int)$nowDt->format('j');
$workingDaysSoFar = 0;
for ($d = 1; $d <= $daysSoFar; $d++) {
  $chartLabels[]   = (string)$d;
  $chartRegular[]  = round($dailyHours[$d][0] ?? 0, 2);
  $chartOvertime[] = round($dailyHours[$d][1] ?? 0, 2);
  if ((int)$nowDt->setDate((int)$nowDt->format('Y'), (int)$nowDt->format('n'), $d)->format('N') <= 5) {
    $workingDaysSoFar++;
  }
}

// Latest shift for the banner text.
$attendanceData = $attRows[0] ?? null;
$completedToday = isset($dayMap[$todayKey]) && $dayMap[$todayKey]['status'] === AttendanceService::STATUS_COMPLETED;

$progressPercent = min(100, (int)round(($weeklyHours / 40) * 100));

// Fetch Real-Time Leave Balances from the 'leave' table
$stmtLeave = $pdo->prepare("SELECT annual_leave, sick_leave, personal_leave FROM `leave` WHERE email = :email LIMIT 1");
$stmtLeave->execute(['email' => $userEmail]);
$leaveData = $stmtLeave->fetch();

$timeOffBalances = [
  'annual'   => $leaveData ? $leaveData['annual_leave']   : 0,
  'sick'     => $leaveData ? $leaveData['sick_leave']     : 0,
  'personal' => $leaveData ? $leaveData['personal_leave'] : 0,
];
$leaveTotal = (int)$timeOffBalances['annual'] + (int)$timeOffBalances['sick'] + (int)$timeOffBalances['personal'];

/* ---------------------------------------------------------------
 * Philippine holidays (this year and next, so the calendar can page forward)
 * ------------------------------------------------------------- */
function dashHolidays(int $year): array
{
  return [
    "{$year}-01-01" => ['name' => "New Year's Day",     'type' => 'Regular Holiday'],
    "{$year}-04-09" => ['name' => 'Araw ng Kagitingan', 'type' => 'Regular Holiday'],
    "{$year}-05-01" => ['name' => 'Labor Day',          'type' => 'Regular Holiday'],
    "{$year}-06-12" => ['name' => 'Independence Day',   'type' => 'Regular Holiday'],
    "{$year}-08-31" => ['name' => 'National Heroes Day', 'type' => 'Regular Holiday'],
    "{$year}-11-01" => ['name' => "All Saints' Day",    'type' => 'Special Non-Working'],
    "{$year}-11-30" => ['name' => 'Bonifacio Day',      'type' => 'Regular Holiday'],
    "{$year}-12-25" => ['name' => 'Christmas Day',      'type' => 'Regular Holiday'],
    "{$year}-12-30" => ['name' => 'Rizal Day',          'type' => 'Regular Holiday'],
  ];
}

$currentYear = (int)$nowDt->format('Y');
$holidayMap  = dashHolidays($currentYear) + dashHolidays($currentYear + 1);
ksort($holidayMap);

$upcomingHolidays = [];
foreach ($holidayMap as $hDate => $hol) {
  if ($hDate >= $todayKey) {
    $upcomingHolidays[] = [
      'name' => $hol['name'],
      'date' => (new DateTimeImmutable($hDate, $tz))->format('D, M j, Y'),
      'type' => $hol['type'],
    ];
  }
  if (count($upcomingHolidays) >= 3) break;
}

/* ---------------------------------------------------------------
 * Leave: my approved leave (calendar + schedule) and teammates on leave
 * ------------------------------------------------------------- */
$leaveDayMap = [];
$myUpcomingLeave = [];
$teamLeave = [];
try {
  $lvStmt = $pdo->prepare("
      SELECT start_date, end_date, number_of_days
      FROM leave_requests
      WHERE employee_id = :eid
        AND status = 'Approved'
        AND end_date >= :year_start
        AND start_date <= :year_end
      ORDER BY start_date
  ");
  $lvStmt->execute([
    ':eid'        => $empIdInt,
    ':year_start' => $currentYear . '-01-01',
    ':year_end'   => ($currentYear + 1) . '-12-31',
  ]);
  foreach ($lvStmt->fetchAll(PDO::FETCH_ASSOC) as $lv) {
    $cursor = new DateTimeImmutable($lv['start_date'], $tz);
    $last   = new DateTimeImmutable($lv['end_date'], $tz);
    for ($i = 0; $cursor <= $last && $i < 60; $i++, $cursor = $cursor->modify('+1 day')) {
      $leaveDayMap[$cursor->format('Y-m-d')] = 'Approved leave';
    }
    if ($lv['end_date'] >= $todayKey && count($myUpcomingLeave) < 1) {
      $myUpcomingLeave[] = $lv;
    }
  }

  $weekEnd = $nowDt->modify('sunday this week')->format('Y-m-d');
  $tlStmt = $pdo->prepare("
      SELECT e.first_name, e.last_name, lr.start_date, lr.end_date
      FROM leave_requests lr
      JOIN employees e ON e.id = lr.employee_id
      WHERE lr.status = 'Approved'
        AND e.id <> :eid
        AND e.department_id = (SELECT department_id FROM employees WHERE id = :eid2)
        AND lr.end_date >= :today
        AND lr.start_date <= :week_end
      ORDER BY lr.start_date
      LIMIT 4
  ");
  $tlStmt->execute([':eid' => $empIdInt, ':eid2' => $empIdInt, ':today' => $todayKey, ':week_end' => $weekEnd]);
  $teamLeave = $tlStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log('Dashboard leave query failed: ' . $e->getMessage());
}

/* ---------------------------------------------------------------
- Most recent payslip for this employee
- Joins payroll_periods so we can show the period label + dates.
- ------------------------------------------------------------- */
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
function dashPayslipPill(string $status): array
{
  return match (strtolower($status)) {
    'paid'      => ['pill-active',  'Paid'],
    'finalized' => ['pill-warning', 'Finalized'],
    'draft'     => ['pill-info',    'Draft'],
    default     => ['pill-neutral', 'Pending'],
  };
}

// Check if this employee has an active (In Progress) onboarding checklist
$activeOnboarding = null;
try {
  $onbStmt = $pdo->prepare("
        SELECT id, status,
               (step_info + step_documents + step_orientation + step_policy) AS done_steps
        FROM employee_onboarding
        WHERE (LOWER(email) = LOWER(:email) OR LOWER(full_name) = LOWER(:full_name))
          AND status = 'In Progress'
        ORDER BY id DESC
        LIMIT 1
    ");
  $onbStmt->execute([
    ':email'     => $userEmail ?? '',
    ':full_name' => $fullName,
  ]);
  $activeOnboarding = $onbStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  error_log('Dashboard onboarding check failed: ' . $e->getMessage());
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
$hour     = (int) $nowDt->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$dashboardRank = Rbac::normalizeRank($employeeData['rank'] ?? 'employee');

// Banner: status text, alert badges and the one quick action that fits right now.
$latestMissing = $missingShifts ? (new DateTimeImmutable($missingShifts[0], $tz))->format('M j') : null;
if ($activeShift) {
  $activeSince = (new DateTimeImmutable((string)$activeShift['time_in'], $tz))->format('h:i A');
  $bannerText  = "Your shift started at {$activeSince}. Remember to Time Out when you finish.";
  $quickAction = ['⏻ Go to Time Out', '/employee/attendance.php'];
} elseif ($missingShifts) {
  $bannerText  = 'A previous shift has no Time Out and needs attendance correction. Review it before your next shift.';
  $quickAction = ['⚠ Fix Missing Time Out', '/employee/attendance.php'];
} elseif ($completedToday) {
  $bannerText  = "You've completed today's shift. Remember to complete your weekly self-assessment by Saturday.";
  $quickAction = ['◷ View Today\'s Attendance', '/employee/attendance.php'];
} else {
  $latestIn   = $attendanceData ? (new DateTimeImmutable((string)$attendanceData['time_in'], $tz))->format('h:i A') : 'N/A';
  $bannerText = "Your latest recorded shift started at {$latestIn}. Remember to complete your weekly self-assessment by Saturday.";
  $quickAction = ['⏱ Time In', '/employee/attendance.php'];
}
if ($missingShifts && $activeShift) {
  $bannerText .= ' You also have a previous shift missing its Time Out.';
}

$fmtHours = static fn(float $h): string => number_format($h, 1);
$shiftLabel = date('h:i A', strtotime($shiftCfg['start_time'])) . ' – ' . date('h:i A', strtotime($shiftCfg['end_time']));
?>
<section class="hero-banner">
  <div class="hero-content">
    <p class="eyebrow">EMPLOYEE DASHBOARD</p>
    <h1 class="welcome-hero">
      <span class="w-part"><?= $greeting ?>,</span>
      <span class="w-part welcome-name"><?= $user['first_name'] ?></span>
      <span class="w-part welcome-wave" aria-hidden="true">👋</span>
    </h1>

    <?php if ($activeShift || $missingShifts): ?>
      <div class="hero-badges" role="status">
        <?php if ($activeShift): ?>
          <span class="hero-badge hero-badge-live"><span class="live-dot" aria-hidden="true"></span>Shift running since <?= dashEsc($activeSince) ?></span>
        <?php endif; ?>
        <?php if ($missingShifts): ?>
          <span class="hero-badge hero-badge-alert">⚠ Missing Time Out · <?= dashEsc($latestMissing) ?><?= count($missingShifts) > 1 ? ' +' . (count($missingShifts) - 1) . ' more' : '' ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <p class="muted welcome-sub"><?= dashEsc($bannerText) ?></p>
    <div class="hero-actions">
      <a class="primary-button" href="<?= dashEsc($quickAction[1]) ?>"><?= dashEsc($quickAction[0]) ?></a>
      <a class="button" href="/employee/file_leave.php">＋ Request Time Off</a>

      <?php if (in_array($dashboardRank, ['team_leader', 'manager'], true)): ?>
        <a class="button" href="/employee/team.php">
          <?= $dashboardRank === 'manager' ? 'Department Overview' : 'My Team' ?>
        </a>
        <a class="button" href="/employee/promotion_requests.php">Promotions</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="hero-orb" aria-hidden="true"></div>
</section>

<?php if ($activeOnboarding): ?>
<div style="margin: 16px 0; padding: 14px 18px; border-radius: 12px; background: rgba(79, 70, 229, 0.16); border: 1px solid rgba(129, 140, 248, 0.35); display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px;">
  <div>
    <strong style="color: #e0e7ff;">New Hire Onboarding Checklist (<?= (int)$activeOnboarding['done_steps'] ?>/4 Completed)</strong>
    <div style="font-size: 13px; color: #94a3b8;">View your Company Orientation schedule and sign your Policy Acknowledgements.</div>
  </div>
  <a href="/employee/onboarding.php" style="padding: 9px 16px; border-radius: 9px; background: #4f46e5; color: #ffffff; text-decoration: none; font-size: 13px; font-weight: 700;">
    Open My Onboarding →
  </a>
</div>
<?php endif; ?>

<div class="dashboard-grid">
  <div class="main-col">

    <!-- ── KPI SUMMARY ─────────────────────────────────────── -->
    <div class="kpi-strip">
      <article class="metric-card metric-teal">
        <div class="metric-top"><span class="metric-ico">◷</span><span class="metric-chip"><?= dashEsc($nowDt->format('F')) ?></span></div>
        <span class="metric-title">Regular Hours</span>
        <strong class="metric-number teal"><?= $fmtHours($kpi['regular']) ?></strong>
        <span class="metric-description"><?= $fmtHours($weeklyHours) ?> h this week</span>
        <div class="metric-bar"><span style="width:<?= (int)$progressPercent ?>%"></span></div>
      </article>
      <article class="metric-card metric-violet">
        <div class="metric-top"><span class="metric-ico">✦</span><span class="metric-chip"><?= $kpi['ot_pending'] > 0 ? dashEsc($fmtHours($kpi['ot_pending'])) . ' h pending' : 'Overtime' ?></span></div>
        <span class="metric-title">Overtime Hours</span>
        <strong class="metric-number purple"><?= $fmtHours($kpi['overtime']) ?></strong>
        <span class="metric-description">After <?= (int)$shiftCfg['ot_grace_minutes'] ?> min past shift end</span>
      </article>
      <article class="metric-card metric-green">
        <div class="metric-top"><span class="metric-ico">✔</span><span class="metric-chip"><?= $kpi['late'] ?> late</span></div>
        <span class="metric-title">Days Present</span>
        <strong class="metric-number green"><?= (int)$kpi['days'] ?></strong>
        <span class="metric-description">of <?= (int)$workingDaysSoFar ?> working days so far</span>
      </article>
      <article class="metric-card metric-blue">
        <div class="metric-top"><span class="metric-ico">☀</span><span class="metric-chip">Balance</span></div>
        <span class="metric-title">Leave Balance</span>
        <strong class="metric-number blue"><?= $leaveTotal ?></strong>
        <span class="metric-description">A <?= (int)$timeOffBalances['annual'] ?> · S <?= (int)$timeOffBalances['sick'] ?> · P <?= (int)$timeOffBalances['personal'] ?> days</span>
      </article>
    </div>

    <!-- ── HOURS & ATTENDANCE CHARTS ───────────────────────── -->
    <article class="card">
      <div class="card-heading">
        <div>
          <p class="eyebrow"><?= strtoupper(dashEsc($nowDt->format('F Y'))) ?></p>
          <h2>Hours &amp; attendance</h2>
        </div>
      </div>
      <?php $hasChartData = $kpi['days'] > 0; ?>
      <?php if ($hasChartData): ?>
        <div class="chart-split">
          <figure class="chart-box chart-box-wide">
            <figcaption>Daily hours</figcaption>
            <div class="chart-canvas"><canvas id="hoursChart" aria-label="Daily regular and overtime hours" role="img"></canvas></div>
          </figure>
          <figure class="chart-box">
            <figcaption>Attendance breakdown (days)</figcaption>
            <div class="chart-canvas chart-canvas-donut"><canvas id="breakdownChart" aria-label="On time, late and missing time out days" role="img"></canvas></div>
          </figure>
        </div>
      <?php else: ?>
        <div class="empty-state"><span class="es-ico">◷</span>No attendance recorded this month yet.</div>
      <?php endif; ?>
    </article>

    <!-- ── RECENT ATTENDANCE ───────────────────────────────── -->
    <div class="panel table-card">
      <div class="panel-head">
        <h2>Recent attendance</h2>
        <a href="/employee/attendance.php">View all →</a>
      </div>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th>Shift date</th>
              <th>Time In</th>
              <th>Time Out</th>
              <th>Regular</th>
              <th>Overtime</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$recentRows): ?>
              <tr>
                <td colspan="6"><div class="empty-state"><span class="es-ico">◷</span>No attendance logs found yet.</div></td>
              </tr>
            <?php else: ?>
              <?php foreach ($recentRows as $row): ?>
                <?php
                $rs      = AttendanceService::effectiveStatus($row);
                $rh      = AttendanceService::rowHours($row, $stdHours);
                $rDate   = !empty($row['shift_date']) ? (string)$row['shift_date'] : substr((string)$row['time_in'], 0, 10);
                $rIn     = (new DateTimeImmutable((string)$row['time_in'], $tz))->format('h:i A');
                $rOut    = !empty($row['time_out']) ? (new DateTimeImmutable((string)$row['time_out'], $tz))->format('h:i A') : null;
                $rPill   = ['COMPLETED' => 'pill-active', 'OPEN' => 'pill-open', 'MISSING_OUT' => 'pill-inactive'][$rs];
                $otState = (string)($row['overtime_status'] ?? '');
                ?>
                <tr>
                  <td><strong><?= dashEsc((new DateTimeImmutable($rDate, $tz))->format('M d, Y')) ?></strong></td>
                  <td class="col-in"><?= dashEsc($rIn) ?></td>
                  <td class="col-out"><?= $rOut !== null ? dashEsc($rOut) : '<span class="muted">—</span>' ?></td>
                  <td><?= $rs === 'COMPLETED' ? number_format($rh['regular'], 2) . ' h' : '<span class="muted">—</span>' ?></td>
                  <td>
                    <?php if ($rh['overtime'] > 0): ?>
                      <?= number_format($rh['overtime'], 2) ?> h
                      <?php if (in_array($otState, ['PENDING_APPROVAL', 'APPROVED', 'REJECTED'], true)): ?>
                        <span class="pill <?= $otState === 'APPROVED' ? 'pill-active' : ($otState === 'REJECTED' ? 'pill-inactive' : 'pill-pending') ?>"><?= dashEsc(str_replace('_', ' ', $otState)) ?></span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="muted"><?= $rs === 'COMPLETED' ? '0.00 h' : '—' ?></span>
                    <?php endif; ?>
                  </td>
                  <td><span class="pill <?= $rPill ?>"><?= dashEsc($rs) ?></span></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="split-row">
      <article class="card">
        <div class="card-heading">
          <div>
            <p class="eyebrow">WORKSPACE</p>
            <h2>Quick actions</h2>
          </div>
        </div>
        <div class="quick-stack">
          <a class="primary-button" href="/employee/file_leave.php" style="width:100%">Request Time Off</a>
          <a class="button" href="/employee/leave_history.php" style="width:100%">View Leave History</a>
          <a class="button" href="/employee/onboarding.php" style="width:100%">My Onboarding</a>
          <a class="button" href="/employee/profile.php" style="width:100%">Edit My Profile</a>
        </div>
      </article>

      <!-- ── PAYSLIP CARD ─────────────────────────────────────── -->
      <article class="card">
        <div class="card-heading">
          <div>
            <p class="eyebrow">PAYROLL</p>
            <h2>Recent payslip</h2>
          </div>
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
    </div>
  </div>

  <aside class="side-col">

    <!-- ── CALENDAR ────────────────────────────────────────── -->
    <article class="card cal-card">
      <div class="cal-head">
        <button type="button" class="cal-nav" id="calPrev" aria-label="Previous month">‹</button>
        <h2 id="calTitle" aria-live="polite"></h2>
        <button type="button" class="cal-nav" id="calNext" aria-label="Next month">›</button>
      </div>
      <div class="cal-grid cal-dow" aria-hidden="true">
        <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
      </div>
      <div class="cal-grid" id="calGrid" role="grid" aria-labelledby="calTitle"></div>
      <ul class="cal-legend">
        <li><span class="cal-dot dot-present"></span>Present</li>
        <li><span class="cal-dot dot-missing"></span>Missing Time Out</li>
        <li><span class="cal-dot dot-holiday"></span>Holiday</li>
        <li><span class="cal-dot dot-leave"></span>Leave</li>
      </ul>
      <div class="cal-detail" id="calDetail" aria-live="polite"></div>
    </article>

    <!-- ── SCHEDULE & HOLIDAYS ─────────────────────────────── -->
    <article class="card">
      <div class="card-heading">
        <div>
          <p class="eyebrow">CALENDAR</p>
          <h2>Schedule &amp; holidays</h2>
        </div>
      </div>
      <ul class="holiday-list">
        <li class="holiday-item">
          <div>
            <strong><?= dashEsc($shiftCfg['name']) ?></strong>
            <small class="muted"><?= dashEsc($shiftLabel) ?> · <?= dashEsc(rtrim(rtrim(number_format($stdHours, 2), '0'), '.')) ?>h</small>
          </div>
          <span class="pill pill-open">Your shift</span>
        </li>
        <?php foreach ($myUpcomingLeave as $lv): ?>
          <li class="holiday-item">
            <div>
              <strong>Approved leave</strong>
              <small class="muted"><?= dashEsc((new DateTimeImmutable($lv['start_date'], $tz))->format('M j')) ?> – <?= dashEsc((new DateTimeImmutable($lv['end_date'], $tz))->format('M j, Y')) ?></small>
            </div>
            <span class="pill pill-neutral">Leave</span>
          </li>
        <?php endforeach; ?>
        <?php foreach ($upcomingHolidays as $holiday): ?>
          <li class="holiday-item">
            <div>
              <strong><?= dashEsc($holiday['name']) ?></strong>
              <small class="muted"><?= dashEsc($holiday['date']) ?></small>
            </div>
            <span class="pill pill-neutral"><?= dashEsc($holiday['type']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </article>

    <!-- ── TEAM & COMPANY UPDATES ──────────────────────────── -->
    <article class="card">
      <div class="card-heading">
        <div>
          <p class="eyebrow">UPDATES</p>
          <h2>Team &amp; company</h2>
        </div>
      </div>
      <?php
      $updates = [];
      if ($missingShifts) {
        $updates[] = ['⚠', count($missingShifts) . ' shift' . (count($missingShifts) > 1 ? 's' : '') . ' missing Time Out', 'Needs attendance correction', '/employee/attendance.php'];
      }
      if ($kpi['ot_pending'] > 0) {
        $updates[] = ['✦', $fmtHours($kpi['ot_pending']) . ' h overtime pending', 'Waiting for approval', '/employee/attendance.php'];
      }
      foreach ($teamLeave as $tl) {
        $range = (new DateTimeImmutable($tl['start_date'], $tz))->format('M j') . ' – ' . (new DateTimeImmutable($tl['end_date'], $tz))->format('M j');
        $updates[] = ['☀', trim($tl['first_name'] . ' ' . $tl['last_name']) . ' is on leave', $range, null];
      }
      ?>
      <?php if (!$updates): ?>
        <p class="muted" style="margin:0">No new updates for your team right now.</p>
      <?php else: ?>
        <ul class="update-list">
          <?php foreach ($updates as [$ico, $head, $meta, $href]): ?>
            <li class="update-item">
              <span class="update-ico" aria-hidden="true"><?= $ico ?></span>
              <div>
                <strong><?= $href ? '<a href="' . dashEsc($href) . '">' . dashEsc($head) . '</a>' : dashEsc($head) ?></strong>
                <small class="muted"><?= dashEsc($meta) ?></small>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </article>

  </aside>
</div>

<?php
$dashData = [
  'today'      => $todayKey,
  'attendance' => $dayMap,
  'holidays'   => $holidayMap,
  'leave'      => $leaveDayMap,
  'chart'      => [
    'labels'   => $chartLabels,
    'regular'  => $chartRegular,
    'overtime' => $chartOvertime,
    'breakdown' => [$kpi['on_time'], $kpi['late'], $kpi['missing']],
  ],
];
$dashJson = json_encode($dashData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

ob_start();
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  (function () {
    var DASH = <?= $dashJson ?>;
    var css = getComputedStyle(document.documentElement);
    function token(name, fallback) { return (css.getPropertyValue(name) || '').trim() || fallback; }
    var C = {
      teal: token('--teal', '#76f5e8'),
      violet: token('--violet', '#8b5cf6'),
      danger: token('--danger', '#fb7185'),
      green: token('--green', '#34d399'),
      muted: token('--muted', '#9aa6cf'),
      line: token('--line', '#2a3566'),
      text: token('--text', '#eef2ff'),
      amber: '#f6c667'
    };

    /* ---------- Charts ---------- */
    if (window.Chart) {
      Chart.defaults.color = C.muted;
      Chart.defaults.font.family = 'Inter, system-ui, sans-serif';

      var hoursEl = document.getElementById('hoursChart');
      if (hoursEl) {
        new Chart(hoursEl, {
          type: 'bar',
          data: {
            labels: DASH.chart.labels,
            datasets: [
              { label: 'Regular hours', data: DASH.chart.regular, backgroundColor: C.teal, borderRadius: 4, stack: 'h' },
              { label: 'Overtime hours', data: DASH.chart.overtime, backgroundColor: C.violet, borderRadius: 4, stack: 'h' }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
            scales: {
              x: { stacked: true, grid: { display: false }, ticks: { color: C.muted }, title: { display: true, text: 'Day of month', color: C.muted } },
              y: { stacked: true, beginAtZero: true, grid: { color: C.line }, ticks: { color: C.muted }, title: { display: true, text: 'Hours', color: C.muted } }
            }
          }
        });
      }

      var brEl = document.getElementById('breakdownChart');
      if (brEl) {
        new Chart(brEl, {
          type: 'doughnut',
          data: {
            labels: ['On time', 'Late', 'Missing Time Out'],
            datasets: [{ data: DASH.chart.breakdown, backgroundColor: [C.green, C.amber, C.danger], borderColor: C.line, borderWidth: 2 }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } }
          }
        });
      }
    }

    /* ---------- Calendar ---------- */
    var grid = document.getElementById('calGrid');
    var title = document.getElementById('calTitle');
    var detail = document.getElementById('calDetail');
    if (!grid) return;

    var today = DASH.today.split('-').map(Number);
    var view = { y: today[0], m: today[1] - 1 };
    var selected = DASH.today;
    var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    function pad(n) { return String(n).padStart(2, '0'); }
    function key(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }

    function renderDetail() {
      var parts = selected.split('-').map(Number);
      var label = MONTHS[parts[1] - 1] + ' ' + parts[2] + ', ' + parts[0];
      var html = '<strong class="cal-detail-date">' + esc(label) + '</strong>';
      var any = false;

      var hol = DASH.holidays[selected];
      if (hol) { any = true; html += '<div class="cal-detail-row"><span class="cal-dot dot-holiday"></span>' + esc(hol.name) + ' <span class="muted">· ' + esc(hol.type) + '</span></div>'; }

      if (DASH.leave[selected]) { any = true; html += '<div class="cal-detail-row"><span class="cal-dot dot-leave"></span>' + esc(DASH.leave[selected]) + '</div>'; }

      var att = DASH.attendance[selected];
      if (att) {
        any = true;
        var dot = att.status === 'MISSING_OUT' ? 'dot-missing' : 'dot-present';
        var out = att.out ? esc(att.out) : (att.status === 'MISSING_OUT' ? 'Missing' : 'Running');
        html += '<div class="cal-detail-row"><span class="cal-dot ' + dot + '"></span>' + esc(att.in) + ' – ' + out + ' <span class="muted">· ' + esc(att.status.replace('_', ' ')) + '</span></div>';
        if (att.status === 'COMPLETED') {
          html += '<div class="cal-detail-row muted">Regular ' + att.regular.toFixed(2) + ' h · Overtime ' + att.overtime.toFixed(2) + ' h</div>';
        }
      }

      if (!any) html += '<div class="cal-detail-row muted">No records for this day.</div>';
      detail.innerHTML = html;
    }

    function render() {
      title.textContent = MONTHS[view.m] + ' ' + view.y;
      var first = new Date(view.y, view.m, 1).getDay();
      var days = new Date(view.y, view.m + 1, 0).getDate();
      var html = '';
      for (var i = 0; i < first; i++) html += '<span class="cal-blank"></span>';
      for (var d = 1; d <= days; d++) {
        var k = key(view.y, view.m, d);
        var cls = 'cal-day';
        var dots = '';
        var att = DASH.attendance[k];
        if (att) dots += '<i class="cal-dot ' + (att.status === 'MISSING_OUT' ? 'dot-missing' : 'dot-present') + '"></i>';
        if (DASH.holidays[k]) dots += '<i class="cal-dot dot-holiday"></i>';
        if (DASH.leave[k]) dots += '<i class="cal-dot dot-leave"></i>';
        if (k === DASH.today) cls += ' is-today';
        if (k === selected) cls += ' is-selected';
        html += '<button type="button" class="' + cls + '" data-date="' + k + '" role="gridcell" aria-selected="' + (k === selected) + '" aria-label="' + MONTHS[view.m] + ' ' + d + '">' + d + '<span class="cal-dots">' + dots + '</span></button>';
      }
      grid.innerHTML = html;
      renderDetail();
    }

    grid.addEventListener('click', function (e) {
      var btn = e.target.closest('.cal-day');
      if (!btn) return;
      selected = btn.getAttribute('data-date');
      render();
    });
    document.getElementById('calPrev').addEventListener('click', function () {
      view.m--; if (view.m < 0) { view.m = 11; view.y--; } render();
    });
    document.getElementById('calNext').addEventListener('click', function () {
      view.m++; if (view.m > 11) { view.m = 0; view.y++; } render();
    });

    render();
  })();
</script>
<?php
$footerExtra = ob_get_clean();
require __DIR__ . '/../templates/employeeTemplates/footer.php';
?>
