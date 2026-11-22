<?php
session_start();
require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Manila');

$gateHelper = __DIR__ . '/../includes/onboarding_gate.php';
if (is_file($gateHelper)) {
  require_once $gateHelper;
}

function publishRealtimeEvent(array $channels, array $event): void {
  $cfgPath = __DIR__ . '/../config/realtime.json';
  if (!is_file($cfgPath)) {
    return;
  }

  $cfg = json_decode((string)file_get_contents($cfgPath), true);
  $publishUrl = $cfg['publish_url'] ?? getenv('HRIS_REALTIME_PUBLISH_URL') ?? '';
  $secret     = $cfg['secret'] ?? getenv('HRIS_REALTIME_SECRET') ?? '';

  if ($publishUrl === '' || strlen($secret) < 32 || !function_exists('curl_init')) {
    return;
  }

  $body = json_encode([
    'ts'       => time(),
    'channels' => $channels,
    'event'    => $event,
  ], JSON_UNESCAPED_SLASHES);

  if ($body === false) {
    return;
  }

  $sig = 'sha256=' . hash_hmac('sha256', $body, $secret);

  $ch = curl_init($publishUrl);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 2,
    CURLOPT_HTTPHEADER     => [
      'Content-Type: application/json',
      'X-Hris-Signature: ' . $sig,
    ],
  ]);
  @curl_exec($ch);
  @curl_close($ch);
}

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

$orientationGate = function_exists('hris_is_orientation_locked_for_employee')
  ? hris_is_orientation_locked_for_employee($pdo, (int)$employeeId, (string)$email, (string)$employeeData['name'])
  : ['locked' => false, 'message' => ''];

if (isset($_GET['api']) && $_GET['api'] === 'orientation_lock_status') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo json_encode([
    'ok'         => true,
    'locked'     => (bool)$orientationGate['locked'],
    'message'    => (string)$orientationGate['message'],
    'serverTime' => date('h:i:s A'),
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$message = '';
$error = '';

$currentTime = date('Y-m-d H:i:s');
$currentHour = (int) date('H');
$currentMinute = (int) date('i');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $action = $_POST['action'];

  if (!empty($orientationGate['locked']) && in_array($action, ['time_in', 'break_time', 'time_out'], true)) {
    $error = (string)$orientationGate['message'];
  } else {
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

          publishRealtimeEvent(['hr', "employee:{$employeeId}"], [
            'type'        => 'attendance.time_in',
            'employee_id' => (int)$employeeId,
            'employee'    => $employeeData['name'],
            'time_in'     => $currentTime,
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

          publishRealtimeEvent(['hr', "employee:{$employeeId}"], [
            'type'        => 'attendance.break_time',
            'employee_id' => (int)$employeeId,
            'employee'    => $employeeData['name'],
            'break_time'  => $currentTime,
          ]);

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
          'time_out'  => $currentTime,
          'workhours' => $workhours,
          'id'        => $activeShift['id'],
        ]);

        publishRealtimeEvent(['hr', "employee:{$employeeId}"], [
          'type'        => 'attendance.time_out',
          'employee_id' => (int)$employeeId,
          'employee'    => $employeeData['name'],
          'time_out'    => $currentTime,
          'workhours'   => $workhours,
        ]);

        $message = "Successfully Timed Out! Total Work Hours (excluding break): {$workhours} hrs.";
      }
    }
  }
}

// ── Cut-off period logic ──────────────────────────────────────────────
$todayDay   = (int) date('j');
$todayYear  = date('Y');
$todayMonth = date('m');

if ($todayDay <= 15) {
  $defaultStart = "{$todayYear}-{$todayMonth}-01";
  $defaultEnd   = "{$todayYear}-{$todayMonth}-15";
} else {
  $lastDay      = date('t');
  $defaultStart = "{$todayYear}-{$todayMonth}-16";
  $defaultEnd   = "{$todayYear}-{$todayMonth}-{$lastDay}";
}

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

$availableCutOffs = array_reverse($availableCutOffs);

$selectedStart = $_GET['start'] ?? $defaultStart;
$selectedEnd   = $_GET['end']   ?? $defaultEnd;

$validPair = false;
foreach ($availableCutOffs as $co) {
  if ($co['start'] === $selectedStart && $co['end'] === $selectedEnd) {
    $validPair = true;
    $selectedLabel = $co['label'];
    break;
  }
}

if (!$validPair) {
  $selectedStart = $defaultStart;
  $selectedEnd   = $defaultEnd;
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
  'email'     => htmlspecialchars($employeeData['email']),
  'avatar'    => 'https://ui-avatars.com/api/?name=' . urlencode($employeeData['name']) . '&background=4763ff&color=fff',
];

$title = 'Attendance';
$eyebrow = 'TIME & ATTENDANCE';
$subtitle = 'Daily time record and attendance history';
$pageCss = 'emp_attendance';
require __DIR__ . '/../templates/employeeTemplates/header.php';
?>

<div id="attOrientationBanner">
  <?php if (!empty($orientationGate['locked'])): ?>
    <div class="alert alert-error" id="attGateAlert"><?= htmlspecialchars((string)$orientationGate['message']) ?></div>
  <?php endif; ?>
</div>

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
      <button type="submit" id="btnTimeIn" class="primary-button" <?= !empty($orientationGate['locked']) ? 'disabled' : '' ?>>⏱ Time In</button>
    </form>
    <form method="POST">
      <input type="hidden" name="action" value="break_time">
      <button type="submit" id="btnBreakTime" class="button btn-break" <?= !empty($orientationGate['locked']) ? 'disabled' : '' ?>>☕ Break (12PM–1PM)</button>
    </form>
    <form id="timeoutForm" method="POST">
      <input type="hidden" name="action" value="time_out">
      <button type="button" id="btnTimeOut" onclick="confirmTimeOut()" class="button btn-timeout" <?= !empty($orientationGate['locked']) ? 'disabled' : '' ?>>⏻ Time Out</button>
    </form>
  </div>
</section>

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
$initLockedJs = !empty($orientationGate['locked']) ? 'true' : 'false';
$initMsgJs    = json_encode((string)$orientationGate['message'], JSON_UNESCAPED_UNICODE);
$footerExtra  = <<<HTML
<script>
  let eoAttLocked = {$initLockedJs};
  let eoAttLockMsg = {$initMsgJs};

  function confirmTimeOut() {
    if (eoAttLocked) {
      alert(eoAttLockMsg || "Time Out is locked until your orientation attendance is marked Present.");
      return;
    }
    if (confirm("Are you sure you want to Time Out? (Ensure you have logged your break time if applicable)")) {
      document.getElementById('timeoutForm').submit();
    }
  }

  (function () {
    function esc(v) {
      return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function applyLockState() {
      ['btnTimeIn', 'btnBreakTime', 'btnTimeOut'].forEach(id => {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.disabled = eoAttLocked;
        btn.style.opacity = eoAttLocked ? '0.5' : '';
        btn.style.cursor = eoAttLocked ? 'not-allowed' : '';
        if (eoAttLocked) {
          btn.title = eoAttLockMsg;
        } else {
          btn.removeAttribute('title');
        }
      });

      const bannerHost = document.getElementById('attOrientationBanner');
      if (bannerHost) {
        bannerHost.innerHTML = eoAttLocked
          ? `<div class="alert alert-error" id="attGateAlert">\${esc(eoAttLockMsg)}</div>`
          : '';
      }
    }

    async function syncOrientationGate() {
      try {
        const res = await fetch(`?api=orientation_lock_status&_=\${Date.now()}`, { cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();
        if (data && data.ok) {
          const nextLocked = Boolean(data.locked);
          const nextMsg = data.message || '';
          if (nextLocked !== eoAttLocked || nextMsg !== eoAttLockMsg) {
            eoAttLocked = nextLocked;
            eoAttLockMsg = nextMsg;
            applyLockState();
          }
        }
      } catch (_) {}
    }

    applyLockState();

    (function connectWS() {
      const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      let ws;
      try {
        ws = new WebSocket(`\${proto}//\${window.location.hostname}:8090/ws`);
        ws.onmessage = () => syncOrientationGate();
        ws.onclose = () => setTimeout(connectWS, 3000);
      } catch (_) {}
    })();

    setInterval(() => { if (!document.hidden) syncOrientationGate(); }, 1500);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) syncOrientationGate();
    });
  })();
</script>
HTML;
require __DIR__ . '/../templates/employeeTemplates/footer.php';
?>