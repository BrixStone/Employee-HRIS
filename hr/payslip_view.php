<?php
// hr/payslip_view.php
require_once __DIR__ . '/../authentication/guard.php';
require_role(['admin', 'hr']);
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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Payslip — <?= htmlspecialchars($ps['last_name'].', '.$ps['first_name']) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,Helvetica,sans-serif;background:#f7f7fb;color:#111;font-size:13px}

    /* Screen only */
    .screen-bar{background:#1e293b;color:#fff;padding:10px 20px;display:flex;align-items:center;gap:12px}
    .screen-bar a{color:#93c5fd;text-decoration:none;font-size:13px}
    .screen-bar button{padding:7px 14px;border:0;border-radius:6px;background:#3b82f6;color:#fff;cursor:pointer;font-size:13px}
    .page-wrap{max-width:720px;margin:24px auto;padding:0 16px 40px}

    /* Payslip card */
    .slip{background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:32px;box-shadow:0 4px 12px rgba(0,0,0,.06)}

    /* Header */
    .slip-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;padding-bottom:16px;border-bottom:2px solid #1e293b}
    .company-name{font-size:20px;font-weight:700;color:#1e293b}
    .company-sub{font-size:12px;color:#6b7280;margin-top:2px}
    .slip-title{text-align:right}
    .slip-title h2{font-size:16px;font-weight:700;color:#1e293b}
    .slip-title p{font-size:12px;color:#6b7280;margin-top:2px}

    /* Employee info */
    .emp-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px}
    .emp-row{display:flex;flex-direction:column;gap:2px}
    .emp-label{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600}
    .emp-value{font-size:13px;font-weight:600;color:#111}

    /* Sections */
    .section{margin-bottom:16px}
    .section-title{font-size:12px;font-weight:700;text-transform:uppercase;color:#374151;background:#f3f4f6;padding:6px 10px;border-radius:4px;margin-bottom:8px;letter-spacing:.5px}
    .slip-row{display:flex;justify-content:space-between;padding:5px 10px;font-size:13px}
    .slip-row:nth-child(even){background:#f9fafb}
    .slip-row.total{font-weight:700;border-top:2px solid #1e293b;margin-top:4px;padding-top:8px}
    .slip-row .label{color:#374151}
    .slip-row .amount{font-variant-numeric:tabular-nums}
    .text-green{color:#065f46}
    .text-red{color:#b91c1c}

    /* Net pay box */
    .net-box{background:#1e293b;color:#fff;border-radius:8px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;margin-top:16px}
    .net-box .net-label{font-size:13px;opacity:.8}
    .net-box .net-amount{font-size:24px;font-weight:700}

    /* Attendance summary */
    .attend-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:16px}
    .attend-box{background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px;text-align:center}
    .attend-box .val{font-size:18px;font-weight:700;color:#1e293b}
    .attend-box .lbl{font-size:10px;color:#6b7280;text-transform:uppercase;margin-top:2px}

    /* Status badge */
    .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700}
    .badge-draft{background:#f3f4f6;color:#374151}
    .badge-finalized{background:#ede9fe;color:#5b21b6}
    .badge-paid{background:#d1fae5;color:#065f46}

    /* Footer */
    .slip-footer{margin-top:28px;padding-top:16px;border-top:1px solid #e5e7eb;display:grid;grid-template-columns:1fr 1fr;gap:20px}
    .sign-box{text-align:center}
    .sign-line{border-top:1px solid #374151;margin-top:36px;padding-top:4px;font-size:11px;color:#6b7280}

    /* Print */
    @media print {
      .screen-bar{display:none}
      body{background:#fff}
      .page-wrap{margin:0;padding:0;max-width:100%}
      .slip{box-shadow:none;border:none;padding:16px}
    }
  </style>
</head>
<body>

<!-- Screen navigation bar (hidden on print) -->
<div class="screen-bar">
  <a href="/hr/payroll.php?tab=payslips&period_id=<?= (int)$ps['payroll_period_id'] ?>">← Back to Payslips</a>
  <button onclick="window.print()">🖨️ Print / Save PDF</button>
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
      <button class="btn" type="submit"
              style="background:#10b981;border:0;border-radius:6px;padding:7px 14px;color:#fff;cursor:pointer;font-size:13px">
        <?= htmlspecialchars($nextLabel) ?>
      </button>
    </form>
  <?php endif; ?>
  <span class="badge badge-<?= strtolower($ps['status']) ?>"><?= htmlspecialchars($ps['status']) ?></span>
</div>

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

</body>
</html>