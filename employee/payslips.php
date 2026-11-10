<?php
session_start();

require_once __DIR__ . '/../config/database.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

date_default_timezone_set('Asia/Manila');

// Logged in, account still active, employee portal, allowed by the permission matrix
require_once __DIR__ . '/../authentication/guard.php';
require_access('payslips', 'view', 'own', ['employee']);

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
$fullName  = trim(($employeeData['first_name'] ?? '') . ' ' . ($employeeData['last_name'] ?? ''));

$user = [
    'full_name' => htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'),
    'email'     => htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'),
    'avatar'    => 'https://ui-avatars.com/api/?name=' . urlencode($fullName) . '&background=4763ff&color=fff',
];

/* ---------------------------------------------------------------
 * Fetch all payslips for this employee from the real payslips table,
 * joined with payroll_periods for label + date range.
 * Ordered newest period first.
 * ------------------------------------------------------------- */
$payslips = [];
try {
    $psStmt = $pdo->prepare("
        SELECT
            ps.id,
            ps.gross_pay,
            ps.total_deductions,
            ps.net_pay,
            ps.days_worked,
            (ps.days_worked * 8) AS hours_worked,
            ps.late_minutes,
            ps.status,
            pp.period_name AS period_label,
            pp.start_date AS period_start,
            pp.end_date   AS period_end
        FROM payslips ps
        JOIN payroll_periods pp ON pp.id = ps.payroll_period_id
        WHERE ps.employee_id = :eid
        ORDER BY pp.start_date DESC
    ");
    $psStmt->execute([':eid' => $employeeId]);
    $payslips = $psStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Payslip list query failed: ' . $e->getMessage());
}

/* ---------------------------------------------------------------
 * Metric card values — driven by the most recent payslip row.
 * Falls back to zeroes / "Pending" when no payslip exists yet.
 * ------------------------------------------------------------- */
$latest      = $payslips[0] ?? null;
$latestHours = $latest ? (float) $latest['hours_worked'] : 0.0;
$latestGross = $latest ? (float) $latest['gross_pay']    : 0.0;
$latestNet   = $latest ? (float) $latest['net_pay']      : 0.0;
$latestLabel = $latest
    ? htmlspecialchars($latest['period_label'], ENT_QUOTES, 'UTF-8')
    : date('M Y');
$latestStatus = $latest ? $latest['status'] : 'pending';
$hasPayroll  = !empty($payslips);

/* ---------------------------------------------------------------
 * Pill helper — maps DB status → CSS class + display label.
 * ------------------------------------------------------------- */
function payslipPill(string $status): array {
    return match ($status) {
        'paid'      => ['pill-active',  'Paid'],
        'finalized' => ['pill-warning', 'Finalized'],
        'generated' => ['pill-info',    'Generated'],
        default     => ['pill-neutral', 'Pending'],
    };
}

// Shell configuration
$title    = 'Payslips';
$eyebrow  = 'PAYROLL';
$subtitle = 'Your pay periods and earnings summary';
$pageCss  = 'emp_payslips';
require __DIR__ . '/../templates/employeeTemplates/header.php';
?>

<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">PAYROLL</p>
    <h1>Payslips</h1>
    <p>Your computed earnings for each semi-monthly pay period.</p>
  </div>
</section>

<div class="metric-strip">
  <article class="metric-card metric-teal">
    <div class="metric-top"><span class="metric-ico">◷</span><span class="metric-chip">Latest period</span></div>
    <span class="metric-title">Hours Worked</span>
    <strong class="metric-number teal"><?= number_format($latestHours, 1) ?></strong>
    <span class="metric-description"><?= $latestLabel ?></span>
  </article>
  <article class="metric-card metric-blue">
    <div class="metric-top"><span class="metric-ico">₱</span><span class="metric-chip">Gross</span></div>
    <span class="metric-title">Gross Pay</span>
    <strong class="metric-number blue">₱<?= number_format($latestGross, 2) ?></strong>
    <span class="metric-description">Before deductions</span>
  </article>
  <article class="metric-card metric-green">
    <div class="metric-top"><span class="metric-ico">✓</span><span class="metric-chip">Net</span></div>
    <span class="metric-title">Net Pay</span>
    <strong class="metric-number green">₱<?= number_format($latestNet, 2) ?></strong>
    <span class="metric-description">Take-home amount</span>
  </article>
  <article class="metric-card metric-violet">
    <div class="metric-top"><span class="metric-ico">▦</span><span class="metric-chip">Status</span></div>
    <span class="metric-title">Latest Payslip</span>
    <strong class="metric-number purple" style="font-size:20px;margin-top:14px">
      <?= ucfirst($latestStatus) ?>
    </strong>
  </article>
</div>

<?php if (!$hasPayroll): ?>
  <div class="panel">
    <div class="panel-body notice-row">
      <span class="notice-ico">ℹ</span>
      <div>
        <strong>No payslips released yet.</strong>
        <p class="muted" style="margin:4px 0 0">
          Once HR generates and finalises a pay period that covers your records,
          your payslips will appear here automatically.
        </p>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="panel table-card">
  <div class="panel-head">
    <h2>Pay History</h2>
    <span class="muted"><?= count($payslips) ?> payslip<?= count($payslips) === 1 ? '' : 's' ?></span>
  </div>
  <table>
    <thead>
      <tr>
        <th>Pay Period</th>
        <th>Inclusive Dates</th>
        <th>Hours</th>
        <th>Gross Pay</th>
        <th>Deductions</th>
        <th>Net Pay</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($payslips)): ?>
        <tr>
          <td colspan="8" style="text-align:center;padding:32px;" class="muted">
            No payslip records found.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($payslips as $ps):
            [$pillClass, $pillLabel] = payslipPill($ps['status']);
            $dateRange = date('M j', strtotime($ps['period_start']))
                       . ' – '
                       . date('M j, Y', strtotime($ps['period_end']));
        ?>
          <tr>
            <td><strong><?= htmlspecialchars($ps['period_label'], ENT_QUOTES, 'UTF-8') ?></strong></td>
            <td class="muted"><?= htmlspecialchars($dateRange) ?></td>
            <td><?= number_format((float) $ps['hours_worked'], 1) ?> hrs</td>
            <td>₱<?= number_format((float) $ps['gross_pay'], 2) ?></td>
            <td class="muted">– ₱<?= number_format((float) $ps['total_deductions'], 2) ?></td>
            <td><strong class="col-net">₱<?= number_format((float) $ps['net_pay'], 2) ?></strong></td>
            <td><span class="pill <?= $pillClass ?>"><?= $pillLabel ?></span></td>
            <td>
              <a href="/employee/payslip_view.php?id=<?= (int) $ps['id'] ?>"
                 class="btn btn-sm btn-outline">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../templates/employeeTemplates/footer.php'; ?>