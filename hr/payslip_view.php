<?php
// hr/payslip_view.php
require_once __DIR__ . '/../authentication/guard.php';
require_access('payslips', 'view', 'all');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Payroll.php';

$payroll = new Payroll($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    die('Payslip not found.');
}

$ps = $payroll->getPayslipById($id);
if (!$ps) {
    http_response_code(404);
    die('Payslip not found.');
}

require __DIR__ . '/../templates/hrTemplates/header.php';
?>

<!-- Screen action bar (hidden on print) -->
<section class="screen-bar">
  <a class="sb-back" href="/hr/payroll.php?tab=payslips&period_id=<?= (int)$ps['payroll_period_id'] ?>">← Back to Payslips</a>
  <button type="button" class="btn btn-print" onclick="window.print()">🖨️ Print / Save PDF</button>
  <?php
    $nextStatus = null; $nextLabel = '';
    if ($ps['status'] === 'Draft')     { $nextStatus = 'Finalized'; $nextLabel = 'Finalize'; }
    if ($ps['status'] === 'Finalized') { $nextStatus = 'Paid';      $nextLabel = 'Mark Paid'; }
  ?>
  <?php if ($nextStatus): ?>
    <form method="post" action="/hr/payroll.php" style="display:inline">
      <input type="hidden" name="form"       value="update_payslip_status">
      <input type="hidden" name="payslip_id" value="<?= (int)$ps['id'] ?>">
      <input type="hidden" name="period_id"  value="<?= (int)$ps['payroll_period_id'] ?>">
      <input type="hidden" name="status"     value="<?= htmlspecialchars($nextStatus) ?>">
      <button class="btn btn-green" type="submit"><?= htmlspecialchars($nextLabel) ?></button>
    </form>
  <?php endif; ?>
  <span class="badge badge-<?= strtolower($ps['status']) ?>"><?= htmlspecialchars($ps['status']) ?></span>
</section>

<div class="page-wrap">
<div class="slip">

  <!-- ── Header ─────────────────────────────────────────────────────────── -->
  <div class="slip-header">
    <div>
      <div class="company-name">HRIS Company</div>
      <div class="company-sub">Human Resource Information System</div>
    </div>
    <div class="slip-title">
      <h2>PAYSLIP</h2>
      <p><?= htmlspecialchars($ps['period_name']) ?></p>
      <p><?= htmlspecialchars($ps['start_date']) ?> to <?= htmlspecialchars($ps['end_date']) ?></p>
      <p>Pay Date: <strong><?= htmlspecialchars($ps['pay_date']) ?></strong></p>
    </div>
  </div>

  <!-- ── Employee Info ───────────────────────────────────────────────────── -->
  <div class="emp-grid">
    <div class="emp-row">
      <span class="emp-label">Employee Name</span>
      <span class="emp-value"><?= htmlspecialchars($ps['first_name'].' '.$ps['last_name']) ?></span>
    </div>
    <div class="emp-row">
      <span class="emp-label">Employee ID</span>
      <span class="emp-value"><?= htmlspecialchars($ps['emp_code']) ?></span>
    </div>
    <div class="emp-row">
      <span class="emp-label">Department</span>
      <span class="emp-value"><?= htmlspecialchars($ps['department_name'] ?? '—') ?></span>
    </div>
    <div class="emp-row">
      <span class="emp-label">Position</span>
      <span class="emp-value"><?= htmlspecialchars($ps['position_title'] ?? '—') ?></span>
    </div>
    <div class="emp-row">
      <span class="emp-label">Pay Type</span>
      <span class="emp-value"><?= htmlspecialchars($ps['pay_type']) ?></span>
    </div>
    <div class="emp-row">
      <span class="emp-label">Basic Salary (Monthly)</span>
      <span class="emp-value">₱<?= number_format((float)$ps['basic_salary'], 2) ?></span>
    </div>
  </div>

  <!-- ── Attendance Summary ─────────────────────────────────────────────── -->
  <div class="section-title">Attendance Summary</div>
  <div class="attend-grid">
    <div class="attend-box">
      <div class="val"><?= (int)$ps['working_days'] ?></div>
      <div class="lbl">Working Days</div>
    </div>
    <div class="attend-box">
      <div class="val"><?= number_format((float)$ps['days_worked'], 1) ?></div>
      <div class="lbl">Days Worked</div>
    </div>
    <div class="attend-box">
      <div class="val" style="color:#b91c1c"><?= number_format((float)$ps['absent_days'], 1) ?></div>
      <div class="lbl">Absent Days</div>
    </div>
    <div class="attend-box">
      <div class="val"><?= (int)$ps['late_minutes'] ?></div>
      <div class="lbl">Late (min)</div>
    </div>
    <div class="attend-box">
      <div class="val"><?= number_format((float)$ps['ot_hours'], 1) ?></div>
      <div class="lbl">OT Hours</div>
    </div>
  </div>

  <!-- ── Two-column: Earnings | Deductions ──────────────────────────────── -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">

    <!-- Earnings -->
    <div class="section">
      <div class="section-title">Earnings</div>
      <div class="slip-row">
        <span class="label">Basic Pay</span>
        <span class="amount text-green">₱<?= number_format((float)$ps['basic_pay'],    2) ?></span>
      </div>
      <div class="slip-row">
        <span class="label">Allowance</span>
        <span class="amount text-green">₱<?= number_format((float)$ps['allowance'],    2) ?></span>
      </div>
      <div class="slip-row">
        <span class="label">Overtime Pay</span>
        <span class="amount text-green">₱<?= number_format((float)$ps['overtime_pay'], 2) ?></span>
      </div>
      <?php if ((float)$ps['other_earnings'] > 0): ?>
      <div class="slip-row">
        <span class="label">Other Earnings</span>
        <span class="amount text-green">₱<?= number_format((float)$ps['other_earnings'], 2) ?></span>
      </div>
      <?php endif; ?>
      <div class="slip-row total">
        <span class="label">Gross Pay</span>
        <span class="amount text-green">₱<?= number_format((float)$ps['gross_pay'], 2) ?></span>
      </div>
    </div>

    <!-- Deductions -->
    <div class="section">
      <div class="section-title">Deductions</div>
      <div class="slip-row">
        <span class="label">SSS</span>
        <span class="amount text-red">₱<?= number_format((float)$ps['sss'],            2) ?></span>
      </div>
      <div class="slip-row">
        <span class="label">PhilHealth</span>
        <span class="amount text-red">₱<?= number_format((float)$ps['philhealth'],      2) ?></span>
      </div>
      <div class="slip-row">
        <span class="label">Pag-IBIG</span>
        <span class="amount text-red">₱<?= number_format((float)$ps['pagibig'],         2) ?></span>
      </div>
      <div class="slip-row">
        <span class="label">Withholding Tax</span>
        <span class="amount text-red">₱<?= number_format((float)$ps['withholding_tax'], 2) ?></span>
      </div>
      <div class="slip-row">
        <span class="label">Absences</span>
        <span class="amount text-red">₱<?= number_format((float)$ps['absences_deduction'], 2) ?></span>
      </div>
      <div class="slip-row">
        <span class="label">Late</span>
        <span class="amount text-red">₱<?= number_format((float)$ps['late_deduction'],  2) ?></span>
      </div>
      <?php if ((float)$ps['other_deductions'] > 0): ?>
      <div class="slip-row">
        <span class="label">Other Deductions</span>
        <span class="amount text-red">₱<?= number_format((float)$ps['other_deductions'], 2) ?></span>
      </div>
      <?php endif; ?>
      <div class="slip-row total">
        <span class="label">Total Deductions</span>
        <span class="amount text-red">₱<?= number_format((float)$ps['total_deductions'], 2) ?></span>
      </div>
    </div>

  </div>

  <!-- ── Net Pay ────────────────────────────────────────────────────────── -->
  <div class="net-box">
    <div>
      <div class="net-label">NET PAY</div>
      <div style="font-size:11px;opacity:.6;margin-top:2px">
        For period: <?= htmlspecialchars($ps['start_date']) ?> – <?= htmlspecialchars($ps['end_date']) ?>
      </div>
    </div>
    <div class="net-amount">₱<?= number_format((float)$ps['net_pay'], 2) ?></div>
  </div>

  <!-- ── Remarks ────────────────────────────────────────────────────────── -->
  <?php if (!empty($ps['remarks'])): ?>
    <div style="margin-top:14px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:10px 14px;font-size:12px">
      <strong>Remarks:</strong> <?= htmlspecialchars($ps['remarks']) ?>
    </div>
  <?php endif; ?>

  <!-- ── Signatures ────────────────────────────────────────────────────── -->
  <div class="slip-footer">
    <div class="sign-box">
      <div class="sign-line">Employee Signature / Date</div>
    </div>
    <div class="sign-box">
      <div class="sign-line">Authorized by / Date</div>
    </div>
  </div>

  <!-- ── Generated note ─────────────────────────────────────────────────── -->
  <div style="margin-top:16px;text-align:center;font-size:11px;color:#9ca3af">
    Generated: <?= date('F d, Y h:i A') ?> &nbsp;|&nbsp;
    Status: <?= htmlspecialchars($ps['status']) ?>
  </div>

</div><!-- /.slip -->
</div><!-- /.page-wrap -->