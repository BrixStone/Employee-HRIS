<?php
session_start();
require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Manila');

// Logged in, account still active, employee portal, allowed by the permission matrix
require_once __DIR__ . '/../authentication/guard.php';
// Clocking in/out (POST) is an edit of their own attendance; just looking is a view.
require_access('attendance', ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 'edit' : 'view', 'own', ['employee']);

// Use the employee ID saved during login, not the user ID.
$employeeId = $_SESSION['employee_id'] ?? null;

if (!$employeeId) {
  exit('No employee ID is linked to this account.');
}

$stmt = $pdo->prepare("
    SELECT id, email, first_name, last_name
    FROM employees
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $employeeId]);

$employeeData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employeeData) {
  exit('Employee record not found. Check users.employee_id.');
}

$email = $employeeData['email'];
// employees has first/last name columns; the rest of this page uses 'name'
$employeeData['name'] = trim(($employeeData['first_name'] ?? '') . ' ' . ($employeeData['last_name'] ?? ''));

if (empty($email)) {
  exit('No email is associated with this employee.');
}

$message = '';
$error = '';

$currentTime = date('Y-m-d H:i:s');
$currentHour = (int) date('H');
$currentMinute = (int) date('i');

// Continue with your existing POST attendance logic below.


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $action = $_POST['action'];

  // Check if employee already completed a shift today
  $todayCheckStmt = $pdo->prepare("
        SELECT id FROM attendance 
        WHERE email = :email 
        AND time_out IS NOT NULL 
        AND DATE(time_in) = CURRENT_DATE
    ");
  $todayCheckStmt->execute(['email' => $email]);
  $alreadyWorkedToday = $todayCheckStmt->fetch();

  if ($action === 'time_in') {
    if ($alreadyWorkedToday) {
      $error = "You have already completed your shift for today and cannot time in again.";
    } else {
      $checkStmt = $pdo->prepare("SELECT id FROM attendance WHERE email = :email AND time_out IS NULL ORDER BY time_in DESC LIMIT 1");
      $checkStmt->execute(['email' => $email]);

      if ($checkStmt->fetch()) {
        $error = "You already have an active shift or break. Please check your status.";
      } else {
        $insertStmt = $pdo->prepare("INSERT INTO attendance (employee_id, email, time_in) VALUES (:eid, :email, :time_in)");
        $insertStmt->execute([
          ':eid'     => $employeeId,
          ':email'   => $email,
          ':time_in' => $currentTime,
        ]);
        $message = "Successfully Timed In at " . date('h:i:s A') . "!";
      }
    }
  } elseif ($action === 'break_time') {
    // MySQL quotes identifiers with backticks; "Break_Time" would be a string literal
    $findStmt = $pdo->prepare('SELECT id, time_in, `Break_Time` FROM attendance WHERE email = :email AND time_out IS NULL ORDER BY time_in DESC LIMIT 1');
    $findStmt->execute(['email' => $email]);
    $activeShift = $findStmt->fetch();

    if (!$activeShift) {
      $error = "You must Time In first before taking a break.";
    } elseif (!empty($activeShift['Break_Time'])) {
      $error = "You have already taken your break for this shift.";
    } else {
      // Rule: Break allowed only between 12:00 PM (12:00) and 1:00 PM (13:00)
      $currentDecimalTime = $currentHour + ($currentMinute / 60);
      if ($currentDecimalTime < 12.0 || $currentDecimalTime > 13.0) {
        $error = "Break time is only allowed between 12:00 PM and 1:00 PM. Current time is " . date('h:i A') . ".";
      } else {
        $updateBreak = $pdo->prepare('UPDATE attendance SET `Break_Time` = :break_time WHERE id = :id');
        $updateBreak->execute(['break_time' => $currentTime, 'id' => $activeShift['id']]);
        $message = "Break Time recorded successfully at " . date('h:i:s A') . ".";
      }
    }
  } elseif ($action === 'time_out') {
    $findStmt = $pdo->prepare('SELECT id, time_in, `Break_Time` FROM attendance WHERE email = :email AND time_out IS NULL ORDER BY time_in DESC LIMIT 1');
    $findStmt->execute(['email' => $email]);
    $activeShift = $findStmt->fetch();

    if (!$activeShift) {
      $error = "No active shift found to Time Out.";
    } else {
      $timeInObj = new DateTime($activeShift['time_in']);
      $timeOutObj = new DateTime($currentTime);
      $diffSeconds = $timeOutObj->getTimestamp() - $timeInObj->getTimestamp();
      $hours = $diffSeconds / 3600;

      // Subtract 1-hour break if break was recorded
      if (!empty($activeShift['Break_Time'])) {
        $hours -= 1.0;
      }

      $workhours = round(max(0, $hours), 2);

      $updateStmt = $pdo->prepare("UPDATE attendance SET time_out = :time_out, workhours = :workhours WHERE id = :id");
      $updateStmt->execute([
        'time_out' => $currentTime,
        'workhours' => $workhours,
        'id' => $activeShift['id']
      ]);
      $message = "Successfully Timed Out! Total Work Hours (excluding break): {$workhours} hrs.";
    }
  }
}

// Fetch history (backticks keep the mixed-case Break_Time column name)
// ── Cut-off period logic ──────────────────────────────────────────────
// Determine current cut-off based on today's date
$todayDay   = (int) date('j');
$todayYear  = date('Y');
$todayMonth = date('m');

// Default cut-off: 1–15 or 16–end of current month
if ($todayDay <= 15) {
  $defaultStart = "{$todayYear}-{$todayMonth}-01";
  $defaultEnd   = "{$todayYear}-{$todayMonth}-15";
} else {
  $lastDay      = date('t'); // last day of current month
  $defaultStart = "{$todayYear}-{$todayMonth}-16";
  $defaultEnd   = "{$todayYear}-{$todayMonth}-{$lastDay}";
}

// Build list of all available cut-offs Aug–current month for the dropdown
$availableCutOffs = [];
$start = new DateTime('2026-08-01');
$now   = new DateTime();
$now->modify('first day of this month');

while ($start <= $now) {
  $y = $start->format('Y');
  $m = $start->format('m');
  $t = $start->format('t');
  $monthName = $start->format('F Y');

  $availableCutOffs[] = [
    'label' => "{$monthName} 1st Cut-off",
    'start' => "{$y}-{$m}-01",
    'end'   => "{$y}-{$m}-15",
  ];
  $availableCutOffs[] = [
    'label' => "{$monthName} 2nd Cut-off",
    'start' => "{$y}-{$m}-16",
    'end'   => "{$y}-{$m}-{$t}",
  ];

  $start->modify('first day of next month');
}

// Most recent first
$availableCutOffs = array_reverse($availableCutOffs);

// Allow employee to switch cut-off via GET — validate against allowed list
$selectedStart = $_GET['start'] ?? $defaultStart;
$selectedEnd   = $_GET['end']   ?? $defaultEnd;

// Security: only allow dates from the known cut-off list
$validPair = false;
foreach ($availableCutOffs as $co) {
  if ($co['start'] === $selectedStart && $co['end'] === $selectedEnd) {
    $validPair = true;
    $selectedLabel = $co['label'];
    break;
  }
}

// If someone tampers with GET params, fall back to default
if (!$validPair) {
  $selectedStart = $defaultStart;
  $selectedEnd   = $defaultEnd;
  // Find label for default
  foreach ($availableCutOffs as $co) {
    if ($co['start'] === $selectedStart && $co['end'] === $selectedEnd) {
      $selectedLabel = $co['label'];
      break;
    }
  }
}

// ── Fetch attendance — always locked to this employee, filtered by cut-off
$historyStmt = $pdo->prepare('
    SELECT id, created_at, email, time_in, `Break_Time`, time_out, workhours
    FROM attendance
    WHERE employee_id = :eid
      AND DATE(time_in) BETWEEN :start AND :end
    ORDER BY time_in DESC
');
$historyStmt->execute([
  ':eid'   => $employeeId,
  ':start' => $selectedStart,
  ':end'   => $selectedEnd,
]);
$attendanceLogs = $historyStmt->fetchAll();

$nameParts = explode(' ', trim($employeeData['name']));
$user = [
  'full_name' => htmlspecialchars($employeeData['name']),
  'email' => htmlspecialchars($employeeData['email']),
  'avatar' => 'https://ui-avatars.com/api/?name=' . urlencode($employeeData['name']) . '&background=4763ff&color=fff'
];

// Shell configuration
$title = 'Attendance';
$eyebrow = 'TIME & ATTENDANCE';
$subtitle = 'Daily time record and attendance history';
$pageCss = 'emp_attendance';
require __DIR__ . '/../templates/employeeTemplates/header.php';
?>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">DTR</p>
    <h1>Daily Time Record</h1>
    <p>Break time is allowed strictly between 12:00 PM and 1:00 PM.</p>
  </div>
  <div class="ph-actions dtr-actions">
    <form method="POST">
      <input type="hidden" name="action" value="time_in">
      <button type="submit" class="primary-button">⏱ Time In</button>
    </form>
    <form method="POST">
      <input type="hidden" name="action" value="break_time">
      <button type="submit" class="button btn-break">☕ Break (12PM–1PM)</button>
    </form>
    <form id="timeoutForm" method="POST">
      <input type="hidden" name="action" value="time_out">
      <button type="button" onclick="confirmTimeOut()" class="button btn-timeout">⏻ Time Out</button>
    </form>
  </div>
</section>

<!-- Cut-off switcher -->
<!-- Cut-off switcher — value encodes both start|end to avoid hidden field mismatch -->
<div style="margin-bottom:1rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
    <span style="font-weight:600;">Showing:</span>
    <form method="GET" action="">
        <select
            name="cutoff"
            onchange="
                var parts = this.value.split('|');
                document.getElementById('inp_start').value = parts[0];
                document.getElementById('inp_end').value   = parts[1];
                this.form.submit();
            "
            style="padding:.4rem .8rem; border-radius:6px; border:1px solid #cbd5e1;"
        >
            <?php foreach ($availableCutOffs as $co): ?>
                <option
                    value="<?= htmlspecialchars($co['start'] . '|' . $co['end']) ?>"
                    <?= ($co['start'] === $selectedStart) ? 'selected' : '' ?>
                >
                    <?= htmlspecialchars($co['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" id="inp_start" name="start" value="<?= htmlspecialchars($selectedStart) ?>">
        <input type="hidden" id="inp_end"   name="end"   value="<?= htmlspecialchars($selectedEnd) ?>">
    </form>
    <span style="color:#64748b; font-size:.875rem;">
        <?= htmlspecialchars($selectedStart) ?> → <?= htmlspecialchars($selectedEnd) ?>
    </span>
</div>

<div class="panel table-card">
  <div class="panel-head">
    <h2>Attendance History</h2>
    <span class="muted"><?= number_format(count($attendanceLogs)) ?> record<?= count($attendanceLogs) === 1 ? '' : 's' ?></span>
  </div>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Time In</th>
        <th>Break Time</th>
        <th>Time Out</th>
        <th>Work Hours</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($attendanceLogs)): ?>
        <tr>
          <td colspan="5">
            <div class="empty-state"><span class="es-ico">◷</span>No attendance logs found yet.</div>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($attendanceLogs as $log): ?>
          <tr>
            <td><strong><?= date('M d, Y', strtotime($log['time_in'])) ?></strong></td>
            <td class="col-in"><?= date('h:i:s A', strtotime($log['time_in'])) ?></td>
            <td class="col-break">
              <?= !empty($log['Break_Time']) ? date('h:i:s A', strtotime($log['Break_Time'])) : '<span class="muted">None</span>' ?>
            </td>
            <td class="col-out">
              <?= !empty($log['time_out']) ? date('h:i:s A', strtotime($log['time_out'])) : '<span class="pill pill-neutral">Active…</span>' ?>
            </td>
            <td><strong class="col-hours"><?= $log['workhours'] !== null ? number_format($log['workhours'], 2) . ' hrs' : '—' ?></strong></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php
$footerExtra = <<<'HTML'
<script>
  function confirmTimeOut() {
    if (confirm("Are you sure you want to Time Out? (Ensure you have logged your break time if applicable)")) {
      document.getElementById('timeoutForm').submit();
    }
  }
</script>
HTML;
require __DIR__ . '/../templates/employeeTemplates/footer.php';
?>