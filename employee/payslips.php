<?php
session_start();

require_once __DIR__ . '/../config/database.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

date_default_timezone_set('Asia/Manila');

// Auth: employees only
if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
    header('Location: /authentication/login.php');
    exit;
}
if (strtolower(trim($_SESSION['role'])) !== 'employee') {
    http_response_code(403);
    exit('Access denied: Employee account required.');
}

$employeeId = $_SESSION['employee_id'] ?? null;
if (!$employeeId) {
    exit('No employee record is linked to this user account.');
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
    exit('Employee record not found.');
}

$userEmail = $employeeData['email'] ?? '';
$fullName = trim(($employeeData['first_name'] ?? '') . ' ' . ($employeeData['last_name'] ?? ''));

$user = [
    'full_name' => htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'),
    'email'     => htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'),
    'avatar'    => 'https://ui-avatars.com/api/?name=' . urlencode($fullName) . '&background=4763ff&color=fff',
];

/*
 * This project ships no payroll/payslip table (see database/hris_mysql.sql),
 * so payslips are derived transparently from recorded attendance hours for
 * the current month. When a real payroll module is added, swap this block
 * for a query against the payroll table.
 */
$HOURLY_RATE = 0.00; // No rate configured; keep gross at 0 until payroll exists.

$periodStart = date('Y-m-01 00:00:00');
$periodEnd   = date('Y-m-t 23:59:59');
$periodLabel = date('M 1 - M t, Y');

$monthHours = 0.0;
try {
    $hoursStmt = $pdo->prepare("
        SELECT COALESCE(SUM(workhours), 0) AS total
        FROM attendance
        WHERE email = :email
          AND time_in BETWEEN :start AND :end
    ");
    $hoursStmt->execute([
        'email' => $userEmail,
        'start' => $periodStart,
        'end'   => $periodEnd,
    ]);
    $monthHours = (float) $hoursStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('Payslip hours query failed: ' . $e->getMessage());
}

$gross = round($monthHours * $HOURLY_RATE, 2);
$net = $gross; // No deductions module yet.
$hasPayroll = $gross > 0;

// Build a short list of recent periods (current + prior 2 months) as a history
// scaffold. Each shows the hours actually recorded in that month.
$periods = [];
for ($i = 0; $i < 3; $i++) {
    $monthTs = strtotime("first day of -{$i} month");
    $pStart = date('Y-m-01 00:00:00', $monthTs);
    $pEnd   = date('Y-m-t 23:59:59', $monthTs);
    $h = 0.0;
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(workhours),0) FROM attendance WHERE email = :email AND time_in BETWEEN :start AND :end");
        $s->execute(['email' => $userEmail, 'start' => $pStart, 'end' => $pEnd]);
        $h = (float) $s->fetchColumn();
    } catch (Throwable $e) { /* leave at 0 */ }
    $periods[] = [
        'label' => date('F Y', $monthTs),
        'range' => date('M 1 - M t, Y', $monthTs),
        'hours' => $h,
        'net'   => round($h * $HOURLY_RATE, 2),
    ];
}

// Shell configuration
$title = 'Payslips';
$eyebrow = 'PAYROLL';
$subtitle = 'Your pay periods and earnings summary';
$pageCss = 'emp_payslips';
require __DIR__ . '/../templates/employeeTemplates/header.php';
?>

<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">PAYROLL</p>
    <h1>Payslips</h1>
    <p>Earnings are derived from recorded attendance hours for each pay period.</p>
  </div>
</section>

<div class="metric-strip">
  <article class="metric-card metric-teal">
    <div class="metric-top"><span class="metric-ico">◷</span><span class="metric-chip">This month</span></div>
    <span class="metric-title">Hours Recorded</span>
    <strong class="metric-number teal"><?= number_format($monthHours, 1) ?></strong>
    <span class="metric-description"><?= htmlspecialchars($periodLabel) ?></span>
  </article>
  <article class="metric-card metric-blue">
    <div class="metric-top"><span class="metric-ico">₱</span><span class="metric-chip">Gross</span></div>
    <span class="metric-title">Gross Pay</span>
    <strong class="metric-number blue">₱<?= number_format($gross, 2) ?></strong>
    <span class="metric-description">Before deductions</span>
  </article>
  <article class="metric-card metric-green">
    <div class="metric-top"><span class="metric-ico">✓</span><span class="metric-chip">Net</span></div>
    <span class="metric-title">Net Pay</span>
    <strong class="metric-number green">₱<?= number_format($net, 2) ?></strong>
    <span class="metric-description">Take-home estimate</span>
  </article>
  <article class="metric-card metric-violet">
    <div class="metric-top"><span class="metric-ico">▦</span><span class="metric-chip">Status</span></div>
    <span class="metric-title">Payroll</span>
    <strong class="metric-number purple" style="font-size:20px;margin-top:14px"><?= $hasPayroll ? 'Available' : 'Pending' ?></strong>
  </article>
</div>

<?php if (!$hasPayroll): ?>
  <div class="panel">
    <div class="panel-body notice-row">
      <span class="notice-ico">ℹ</span>
      <div>
        <strong>No payroll disbursement for this period yet.</strong>
        <p class="muted" style="margin:4px 0 0">Once HR configures a pay rate and runs payroll, your computed earnings will appear here automatically. Attendance hours are already being tracked.</p>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="panel table-card">
  <div class="panel-head">
    <h2>Recent Pay Periods</h2>
    <span class="muted"><?= count($periods) ?> period<?= count($periods) === 1 ? '' : 's' ?></span>
  </div>
  <table>
    <thead>
      <tr>
        <th>Pay Period</th>
        <th>Inclusive Dates</th>
        <th>Hours</th>
        <th>Net Pay</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($periods as $i => $p): ?>
        <tr>
          <td><strong><?= htmlspecialchars($p['label']) ?></strong></td>
          <td class="muted"><?= htmlspecialchars($p['range']) ?></td>
          <td><?= number_format($p['hours'], 1) ?> hrs</td>
          <td><strong class="col-net">₱<?= number_format($p['net'], 2) ?></strong></td>
          <td>
            <?php if ($p['net'] > 0): ?>
              <span class="pill pill-active">Paid</span>
            <?php elseif ($i === 0): ?>
              <span class="pill pill-neutral">Current</span>
            <?php else: ?>
              <span class="pill pill-neutral">No payroll</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../templates/employeeTemplates/footer.php'; ?>
