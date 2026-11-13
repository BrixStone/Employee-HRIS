<?php
// hr/payroll.php
require_once __DIR__ . '/../authentication/guard.php';
require_access('payslips', 'edit', 'all');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Payroll.php';
require_once __DIR__ . '/../classes/DSA/LinkedList.php';

// DDD — Application layer wired to real MySQL repository
require_once __DIR__ . '/../vendor/autoload.php';

use App\Application\Payroll\GeneratePayslipCommand;
use App\Application\Payroll\GeneratePayslipHandler;
use App\Infrastructure\Persistence\PdoPayslipRepository;

$payroll    = new Payroll($pdo);
$psRepo     = new PdoPayslipRepository($pdo);
$psHandler  = new GeneratePayslipHandler($psRepo);

// ─── Active tab ──────────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'salaries';
$allowedTabs = ['salaries', 'periods', 'payslips'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'salaries';

// ─── Messages ────────────────────────────────────────────────────────────────
$success = '';
$errors  = [];

// ─── POST Router ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $form = $_POST['form'] ?? '';

  // ── Set / Update Salary ──────────────────────────────────────────────────
  if ($form === 'set_salary') {
    $empId         = (int)($_POST['employee_id']   ?? 0);
    $payType       = $_POST['pay_type']             ?? 'Monthly';
    $basicSalary   = (float)($_POST['basic_salary'] ?? 0);
    $allowance     = (float)($_POST['allowance']    ?? 0);
    $effectiveDate = trim($_POST['effective_date']  ?? '');

    $allowedPayTypes = ['Monthly', 'Daily', 'Hourly'];
    if ($empId <= 0)                                 $errors[] = 'Please select an employee.';
    if (!in_array($payType, $allowedPayTypes, true)) $errors[] = 'Invalid pay type.';
    if ($basicSalary <= 0)                           $errors[] = 'Basic salary must be greater than 0.';
    if ($effectiveDate === '')                       $errors[] = 'Effective date is required.';

    if (!$errors) {
      $ok = $payroll->setSalary($empId, $payType, $basicSalary, $allowance, $effectiveDate);
      if ($ok) $success = 'Salary saved successfully.';
      else     $errors[] = 'Failed to save salary.';
    }
    $tab = 'salaries';
  }

  // ── Create Period ────────────────────────────────────────────────────────
  elseif ($form === 'create_period') {
    $name      = trim($_POST['period_name'] ?? '');
    $startDate = trim($_POST['start_date']  ?? '');
    $endDate   = trim($_POST['end_date']    ?? '');
    $payDate   = trim($_POST['pay_date']    ?? '');

    if ($name === '')      $errors[] = 'Period name is required.';
    if ($startDate === '') $errors[] = 'Start date is required.';
    if ($endDate === '')   $errors[] = 'End date is required.';
    if ($payDate === '')   $errors[] = 'Pay date is required.';
    if (!$errors && $endDate < $startDate) $errors[] = 'End date must be after start date.';

    if (!$errors) {
      try {
        $newId   = $payroll->createPeriod($name, $startDate, $endDate, $payDate);
        $success = 'Payroll period created. '
          . '<a href="/hr/payroll.php?tab=payslips&period_id=' . $newId . '" '
          . 'style="color:#065f46;font-weight:600">Generate payslips now →</a>';
      } catch (Throwable $e) {
        $errors[] = 'Could not create period. A period with those dates may already exist.';
      }
    }
    $tab = 'periods';
  }

  // ── Generate Payslips (DDD — GeneratePayslipHandler) ─────────────────────
  elseif ($form === 'generate_payslips') {
    $periodId = (int)($_POST['period_id'] ?? 0);
    if ($periodId <= 0) {
      $errors[] = 'Invalid period.';
    } else {
      // Load period dates
      $periodRow = $pdo->prepare("
          SELECT start_date, end_date FROM payroll_periods WHERE id = ?
      ");
      $periodRow->execute([$periodId]);
      $period = $periodRow->fetch(PDO::FETCH_ASSOC);

      if (!$period) {
        $errors[] = 'Period not found.';
      } else {
        // Load deduction rates from DB
        // Load deduction rates from DB
        $rates    = [];
        $rateRows = $pdo->query("SELECT name, type, rate FROM deduction_rates WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rateRows as $r) $rates[strtolower($r['name'])] = $r;

        // SSS — Percentage of basic salary
        $sssRate  = isset($rates['sss'])
          ? (float)$rates['sss']['rate'] / 100
          : 0.045;

        // PhilHealth — Percentage of basic salary
        $philRate = isset($rates['philhealth'])
          ? (float)$rates['philhealth']['rate'] / 100
          : 0.025;

        // Pag-IBIG — Fixed amount
        $pagFixed = isset($rates['pag-ibig'])
          ? (float)$rates['pag-ibig']['rate']
          : 100.0;

        $salaries  = $payroll->getAllSalaries();
        $generated = 0;
        $skipped   = 0;

        foreach ($salaries as $s) {
          $empId = (int) $s['employee_id'];
          if (!$empId || (float)$s['basic_salary'] <= 0) {
            $skipped++;
            continue;
          }

          // Check if employee has ANY attendance in this period
          // If zero records — they did not work this cut-off, skip payslip generation
          $attCountStmt = $pdo->prepare("
        SELECT COUNT(*) FROM attendance
        WHERE employee_id = :eid
          AND DATE(time_in) BETWEEN :start AND :end
    ");
          $attCountStmt->execute([
            ':eid'   => $empId,
            ':start' => $period['start_date'],
            ':end'   => $period['end_date'],
          ]);
          $attCount = (int) $attCountStmt->fetchColumn();

          if ($attCount === 0) {
            $skipped++;
            continue; // No attendance this cut-off — do not generate payslip
          }

          // Fetch late minutes per attendance day in this period
          $lateStmt = $pdo->prepare("
        SELECT
            GREATEST(0, TIMESTAMPDIFF(MINUTE,
                CONCAT(DATE(time_in), ' 08:00:00'), time_in
            )) AS late_minutes
        FROM attendance
        WHERE employee_id = :eid
          AND DATE(time_in) BETWEEN :start AND :end
          AND TIMESTAMPDIFF(MINUTE, CONCAT(DATE(time_in), ' 08:00:00'), time_in) > 0
    ");
          $lateStmt->execute([
            ':eid'   => $empId,
            ':start' => $period['start_date'],
            ':end'   => $period['end_date'],
          ]);
          $lateRecords = $lateStmt->fetchAll(PDO::FETCH_ASSOC);

          $command = new GeneratePayslipCommand(
            employeeId: $empId,
            basicSalary: (float) $s['basic_salary'],
            allowance: (float) ($s['allowance'] ?? 0),
            periodStart: $period['start_date'],
            periodEnd: $period['end_date'],
            lateRecords: $lateRecords,
            sssRate: $sssRate,
            philRate: $philRate,
            pagIbigFixed: $pagFixed,
            payrollPeriodId: $periodId,
          );

          try {
            $psHandler->handle($command);
            $generated++;
          } catch (Throwable $e) {
            $skipped++;
          }
        }

        $msg = "Generated {$generated} payslip(s) via DDD handler.";
        if ($skipped > 0) $msg .= " Skipped {$skipped}.";
        header('Location: /hr/payroll.php?tab=payslips&period_id=' . $periodId
          . '&success=' . urlencode($msg));
        exit;
      }
    }
    $tab = 'payslips';
  }

  // ── Update Payslip Status ─────────────────────────────────────────────────
  elseif ($form === 'update_payslip_status') {
    $payslipId = (int)($_POST['payslip_id'] ?? 0);
    $status    = $_POST['status']            ?? '';
    $periodId  = (int)($_POST['period_id']   ?? 0);
    $ok = $payroll->updatePayslipStatus($payslipId, $status);
    if ($ok) {
      header('Location: /hr/payroll.php?tab=payslips&period_id=' . $periodId
        . '&success=' . urlencode('Payslip status updated.'));
      exit;
    }
    $errors[] = 'Could not update payslip status.';
    $tab = 'payslips';
  }

  // ── Update Period Status ──────────────────────────────────────────────────
  elseif ($form === 'update_period_status') {
    $periodId = (int)($_POST['period_id'] ?? 0);
    $status   = $_POST['status']           ?? '';
    $ok = $payroll->updatePeriodStatus($periodId, $status);
    if ($ok) {
      header('Location: /hr/payroll.php?tab=periods&success='
        . urlencode('Period status updated.'));
      exit;
    }
    $errors[] = 'Could not update period status.';
    $tab = 'periods';
  }
}

// ─── Flash success from redirect ─────────────────────────────────────────────
if (!$success && !empty($_GET['success'])) {
  $success = htmlspecialchars($_GET['success']);
}

// ─── Load tab data ────────────────────────────────────────────────────────────
$salaries    = [];
$employees   = [];
$periods     = [];
$payslips    = [];
$selPeriod   = null;
$selPeriodId = (int)($_GET['period_id'] ?? 0);

if ($tab === 'salaries') {
  $salaries  = $payroll->getAllSalaries();
  $employees = $pdo->query("
        SELECT e.id, e.employee_id AS emp_code,
               e.first_name, e.last_name,
               d.name AS department_name
        FROM employees e
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE e.employment_status = 'Active'
        ORDER BY e.last_name, e.first_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

if ($tab === 'periods' || $tab === 'payslips') {
  $periods = $payroll->getAllPeriods();

  // ── DSA: Linked List — payroll periods chain ──────────────
  $periodList = new LinkedList();
  foreach ($periods as $period) {
    $periodList->append($period); // O(1) per node
  }
  $latestPeriod = $periodList->tail(); // most recent
  $oldestPeriod = $periodList->head(); // first ever
}

if ($tab === 'payslips' && $selPeriodId > 0) {
  $selPeriod = $payroll->getPeriodById($selPeriodId);
  $payslips  = $payroll->getPayslipsByPeriod($selPeriodId);
}

require __DIR__ . '/../templates/hrTemplates/header.php';
?>

<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">PAYROLL</p>
    <h1>Payroll Management</h1>
    <p>Set salaries, create pay periods, and generate payslips from attendance.</p>
  </div>
  <div class="ph-actions">
    <a class="button" href="/hr/dashboard.php">← HR Dashboard</a>
  </div>
</section>

<!-- Tabs -->
<div class="tabs">
  <a href="/hr/payroll.php?tab=salaries" class="<?= $tab === 'salaries' ? 'active' : '' ?>"><span>💰</span> Salaries</a>
  <a href="/hr/payroll.php?tab=periods" class="<?= $tab === 'periods' ? 'active' : '' ?>"><span>📅</span> Periods</a>
  <a href="/hr/payroll.php?tab=payslips" class="<?= $tab === 'payslips' ? 'active' : '' ?>"><span>🧾</span> Payslips</a>
</div>

<!-- Alerts -->
<?php if ($success): ?>
  <div class="alert alert-ok"><?= $success ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="alert alert-err">
    <?php foreach ($errors as $e): ?>
      <div><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- TAB: SALARIES                                                        -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<?php if ($tab === 'salaries'): ?>

  <div class="card">
    <h3>Set / Update Employee Salary</h3>
    <form method="post">
      <input type="hidden" name="form" value="set_salary">
      <div class="grid-2">
        <div class="row">
          <label>Employee</label>
          <select name="employee_id" required>
            <option value="">-- Select employee --</option>
            <?php foreach ($employees as $emp): ?>
              <option value="<?= (int)$emp['id'] ?>">
                [<?= htmlspecialchars($emp['emp_code']) ?>]
                <?= htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']) ?>
                <?= $emp['department_name'] ? ' — ' . htmlspecialchars($emp['department_name']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row">
          <label>Pay Type</label>
          <select name="pay_type">
            <option value="Monthly">Monthly (semi-monthly payout)</option>
            <option value="Daily">Daily</option>
            <option value="Hourly">Hourly</option>
          </select>
        </div>
      </div>
      <div class="grid-3">
        <div class="row">
          <label>Basic Salary (₱/month)</label>
          <input type="number" name="basic_salary" step="0.01" min="0"
            required placeholder="e.g. 25000.00">
          <div class="hint">Monthly rate — system splits per cut-off automatically.</div>
        </div>
        <div class="row">
          <label>Allowance (₱/month)</label>
          <input type="number" name="allowance" step="0.01" min="0" value="0.00">
          <div class="hint">Also split per cut-off automatically.</div>
        </div>
        <div class="row">
          <label>Effective Date</label>
          <input type="date" name="effective_date" required value="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <button class="btn btn-primary" type="submit">Save Salary</button>
    </form>
  </div>

  <div class="card">
    <h3>Current Active Salaries</h3>
    <?php if (empty($salaries)): ?>
      <p class="muted">No salaries set yet. Use the form above to add one.</p>
    <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Employee</th>
              <th>Department</th>
              <th>Position</th>
              <th>Pay Type</th>
              <th class="text-right">Basic Salary</th>
              <th class="text-right">Per Cut-off</th>
              <th class="text-right">Allowance</th>
              <th>Effective Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($salaries as $i => $s): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td>
                  <?= htmlspecialchars($s['last_name'] . ', ' . $s['first_name']) ?><br>
                  <span class="muted"><?= htmlspecialchars($s['emp_code']) ?></span>
                </td>
                <td><?= htmlspecialchars($s['department_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($s['position_title']  ?? '—') ?></td>
                <td><?= htmlspecialchars($s['pay_type']) ?></td>
                <td class="text-right">₱<?= number_format((float)$s['basic_salary'], 2) ?></td>
                <td class="text-right">
                  <?php if ($s['pay_type'] === 'Monthly'): ?>
                    ₱<?= number_format((float)$s['basic_salary'] / 2, 2) ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td class="text-right">₱<?= number_format((float)$s['allowance'], 2) ?></td>
                <td><?= htmlspecialchars($s['effective_date']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ════════════════════════════════════════════════════════════════════ -->
  <!-- TAB: PERIODS                                                         -->
  <!-- ════════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'periods'): ?>

  <div class="card">
    <h3>Create Payroll Period</h3>

    <!-- Quick fill buttons -->
    <div class="flex" style="margin-bottom:16px">
      <span class="muted">Quick fill:</span>
      <button type="button" class="btn btn-secondary btn-sm" onclick="fillCutoff(1)">
        1st Cut-off (1–15)
      </button>
      <button type="button" class="btn btn-secondary btn-sm" onclick="fillCutoff(2)">
        2nd Cut-off (16–end)
      </button>
    </div>

    <form method="post" id="period-form">
      <input type="hidden" name="form" value="create_period">
      <div class="grid-2">
        <div class="row">
          <label>Period Name</label>
          <input type="text" name="period_name" id="period_name" required
            placeholder="e.g. November 1–15, 2026"
            value="<?= htmlspecialchars($_POST['period_name'] ?? '') ?>">
        </div>
        <div class="row">
          <label>Pay Date</label>
          <input type="date" name="pay_date" id="pay_date" required
            value="<?= htmlspecialchars($_POST['pay_date'] ?? '') ?>">
        </div>
      </div>
      <div class="grid-2">
        <div class="row">
          <label>Start Date</label>
          <input type="date" name="start_date" id="start_date" required
            value="<?= htmlspecialchars($_POST['start_date'] ?? '') ?>">
        </div>
        <div class="row">
          <label>End Date</label>
          <input type="date" name="end_date" id="end_date" required
            value="<?= htmlspecialchars($_POST['end_date'] ?? '') ?>">
        </div>
      </div>
      <div class="hint" style="margin-bottom:12px">
        💡 Semi-monthly: <strong>1st cut-off</strong> = 1–15 &nbsp;|&nbsp;
        <strong>2nd cut-off</strong> = 16–30/31 of the same month.
        Salary is automatically split in half per cut-off.
      </div>
      <button class="btn btn-primary" type="submit">Create Period</button>
    </form>
  </div>

  <div class="card">
    <h3>All Payroll Periods</h3>
    <?php if (empty($periods)): ?>
      <p class="muted">No periods yet. Create one above.</p>
    <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Period Name</th>
              <th>Start</th>
              <th>End</th>
              <th>Pay Date</th>
              <th>Payslips</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($periods as $i => $p):
              // ── DSA: Linked List — get adjacent periods ──
              $prevPeriod = $periodList->getPrev('id', $p['id']);
              $nextPeriod = $periodList->getNext('id', $p['id']);
            ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td>
                  <?= htmlspecialchars($p['period_name']) ?>
                  <div style="font-size:11px;margin-top:4px">
                    <?php if ($prevPeriod): ?>
                      <span class="muted">← <?= htmlspecialchars($prevPeriod['period_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($nextPeriod): ?>
                      <span class="muted"> → <?= htmlspecialchars($nextPeriod['period_name']) ?></span>
                    <?php endif; ?>
                    <?php if (!$prevPeriod && !$nextPeriod): ?>
                      <span class="muted">Only period</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td><?= htmlspecialchars($p['start_date'])  ?></td>
                <td><?= htmlspecialchars($p['end_date'])    ?></td>
                <td><?= htmlspecialchars($p['pay_date'])    ?></td>
                <td><?= (int)$p['payslip_count'] ?></td>
                <td>
                  <?php
                  $bs = [
                    'Draft'     => 'badge-draft',
                    'Processed' => 'badge-processed',
                    'Paid'      => 'badge-paid',
                    'Cancelled' => 'badge-cancelled'
                  ];
                  $bc = $bs[$p['status']] ?? 'badge-draft';
                  ?>
                  <span class="badge <?= $bc ?>"><?= htmlspecialchars($p['status']) ?></span>
                </td>
                <td>
                  <div class="flex">
                    <a class="btn btn-primary btn-sm"
                      href="/hr/payroll.php?tab=payslips&period_id=<?= (int)$p['id'] ?>">
                      View Payslips
                    </a>
                    <?php if ($p['status'] === 'Draft'): ?>
                      <form method="post" style="display:inline">
                        <input type="hidden" name="form" value="update_period_status">
                        <input type="hidden" name="period_id" value="<?= (int)$p['id'] ?>">
                        <input type="hidden" name="status" value="Processed">
                        <button class="btn btn-orange btn-sm" type="submit">Mark Processed</button>
                      </form>
                    <?php elseif ($p['status'] === 'Processed'): ?>
                      <form method="post" style="display:inline">
                        <input type="hidden" name="form" value="update_period_status">
                        <input type="hidden" name="period_id" value="<?= (int)$p['id'] ?>">
                        <input type="hidden" name="status" value="Paid">
                        <button class="btn btn-green btn-sm" type="submit">Mark Paid</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ════════════════════════════════════════════════════════════════════ -->
  <!-- TAB: PAYSLIPS                                                        -->
  <!-- ════════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'payslips'): ?>

  <!-- Period selector -->
  <div class="card">
    <h3>Select Payroll Period</h3>
    <form method="get" class="flex">
      <input type="hidden" name="tab" value="payslips">
      <select name="period_id" style="max-width:400px">
        <option value="">-- Choose a period --</option>
        <?php foreach ($periods as $p): ?>
          <option value="<?= (int)$p['id'] ?>"
            <?= $selPeriodId === (int)$p['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($p['period_name']) ?>
            (<?= htmlspecialchars($p['start_date']) ?> → <?= htmlspecialchars($p['end_date']) ?>)
            — <?= htmlspecialchars($p['status']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary" type="submit">Load</button>
    </form>
  </div>

  <?php if ($selPeriod): ?>

    <!-- Generate payslips -->
    <?php if (in_array($selPeriod['status'], ['Draft', 'Processed'], true)): ?>
      <div class="card">
        <h3>Generate / Refresh Payslips</h3>
        <p class="muted" style="margin-bottom:12px">
          Auto-computes pay from <strong>attendance</strong> and <strong>salaries</strong>
          for <strong><?= htmlspecialchars($selPeriod['period_name']) ?></strong>
          (<?= htmlspecialchars($selPeriod['start_date']) ?> →
          <?= htmlspecialchars($selPeriod['end_date']) ?>).
          Running again will update existing drafts.
        </p>
        <form method="post">
          <input type="hidden" name="form" value="generate_payslips">
          <input type="hidden" name="period_id" value="<?= (int)$selPeriod['id'] ?>">
          <button class="btn btn-green" type="submit">⚙️ Generate / Refresh Payslips</button>
        </form>
      </div>
    <?php endif; ?>

    <!-- Payslips table -->
    <div class="card">
      <div class="flex" style="margin-bottom:12px;justify-content:space-between">
        <h3 style="margin:0">
          <?= htmlspecialchars($selPeriod['period_name']) ?>
          &nbsp;
          <?php
          $bs = [
            'Draft'     => 'badge-draft',
            'Processed' => 'badge-processed',
            'Paid'      => 'badge-paid',
            'Cancelled' => 'badge-cancelled'
          ];
          $bc = $bs[$selPeriod['status']] ?? 'badge-draft';
          ?>
          <span class="badge <?= $bc ?>"><?= htmlspecialchars($selPeriod['status']) ?></span>
        </h3>
        <span class="muted"><?= count($payslips) ?> record(s)</span>
      </div>

      <?php if (empty($payslips)): ?>
        <p class="muted">No payslips yet. Click "Generate Payslips" above.</p>
      <?php else: ?>
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Employee</th>
                <th>Department</th>
                <th class="text-right">Basic Pay</th>
                <th class="text-right">Allowance</th>
                <th class="text-right">OT Pay</th>
                <th class="text-right">Gross</th>
                <th class="text-right">Deductions</th>
                <th class="text-right">Net Pay</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($payslips as $i => $ps): ?>
                <tr>
                  <td><?= $i + 1 ?></td>
                  <td>
                    <?= htmlspecialchars($ps['last_name'] . ', ' . $ps['first_name']) ?><br>
                    <span class="muted"><?= htmlspecialchars($ps['emp_code']) ?></span>
                  </td>
                  <td><?= htmlspecialchars($ps['department_name'] ?? '—') ?></td>
                  <td class="text-right">₱<?= number_format((float)$ps['basic_pay'],       2) ?></td>
                  <td class="text-right">₱<?= number_format((float)$ps['allowance'],       2) ?></td>
                  <td class="text-right">₱<?= number_format((float)$ps['overtime_pay'],    2) ?></td>
                  <td class="text-right">₱<?= number_format((float)$ps['gross_pay'],       2) ?></td>
                  <td class="text-right">₱<?= number_format((float)$ps['total_deductions'], 2) ?></td>
                  <td class="text-right">
                    <strong>₱<?= number_format((float)$ps['net_pay'], 2) ?></strong>
                  </td>
                  <td>
                    <?php
                    $sb = [
                      'Draft'     => 'badge-draft',
                      'Finalized' => 'badge-finalized',
                      'Paid'      => 'badge-paid'
                    ];
                    $sc = $sb[$ps['status']] ?? 'badge-draft';
                    ?>
                    <span class="badge <?= $sc ?>"><?= htmlspecialchars($ps['status']) ?></span>
                  </td>
                  <td>
                    <div class="flex">
                      <a class="btn btn-secondary btn-sm"
                        href="/hr/payslip_view.php?id=<?= (int)$ps['id'] ?>">
                        View
                      </a>
                      <?php if ($ps['status'] === 'Draft'): ?>
                        <form method="post" style="display:inline">
                          <input type="hidden" name="form" value="update_payslip_status">
                          <input type="hidden" name="payslip_id" value="<?= (int)$ps['id'] ?>">
                          <input type="hidden" name="period_id" value="<?= (int)$selPeriod['id'] ?>">
                          <input type="hidden" name="status" value="Finalized">
                          <button class="btn btn-orange btn-sm" type="submit">Finalize</button>
                        </form>
                      <?php elseif ($ps['status'] === 'Finalized'): ?>
                        <form method="post" style="display:inline">
                          <input type="hidden" name="form" value="update_payslip_status">
                          <input type="hidden" name="payslip_id" value="<?= (int)$ps['id'] ?>">
                          <input type="hidden" name="period_id" value="<?= (int)$selPeriod['id'] ?>">
                          <input type="hidden" name="status" value="Paid">
                          <button class="btn btn-green btn-sm" type="submit">Mark Paid</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="font-weight:700">
                <td colspan="3">TOTAL (<?= count($payslips) ?> employees)</td>
                <td class="text-right">₱<?= number_format(array_sum(array_column($payslips, 'basic_pay')),       2) ?></td>
                <td class="text-right">₱<?= number_format(array_sum(array_column($payslips, 'allowance')),       2) ?></td>
                <td class="text-right">₱<?= number_format(array_sum(array_column($payslips, 'overtime_pay')),    2) ?></td>
                <td class="text-right">₱<?= number_format(array_sum(array_column($payslips, 'gross_pay')),       2) ?></td>
                <td class="text-right">₱<?= number_format(array_sum(array_column($payslips, 'total_deductions')), 2) ?></td>
                <td class="text-right">₱<?= number_format(array_sum(array_column($payslips, 'net_pay')),         2) ?></td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>

  <?php endif; // selPeriod 
  ?>

<?php endif; // tab 
?>

<div class="mt">
  <a class="btn btn-secondary" href="/hr/dashboard.php">← Back to HR Dashboard</a>
</div>

<script>
  function fillCutoff(half) {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    const lastDay = new Date(y, now.getMonth() + 1, 0).getDate();
    const monthName = now.toLocaleString('default', {
      month: 'long'
    });

    if (half === 1) {
      document.getElementById('period_name').value = `${monthName} 1–15, ${y}`;
      document.getElementById('start_date').value = `${y}-${m}-01`;
      document.getElementById('end_date').value = `${y}-${m}-15`;
      document.getElementById('pay_date').value = `${y}-${m}-15`;
    } else {
      document.getElementById('period_name').value = `${monthName} 16–${lastDay}, ${y}`;
      document.getElementById('start_date').value = `${y}-${m}-16`;
      document.getElementById('end_date').value = `${y}-${m}-${String(lastDay).padStart(2,'0')}`;
      // Pay date = 5th of next month
      const nextMonth = new Date(y, now.getMonth() + 1, 5);
      const ny = nextMonth.getFullYear();
      const nm = String(nextMonth.getMonth() + 1).padStart(2, '0');
      document.getElementById('pay_date').value = `${ny}-${nm}-05`;
    }
  }
</script>