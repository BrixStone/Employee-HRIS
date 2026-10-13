<?php
require __DIR__ . '/../config/supabase.php';
require __DIR__ . '/../classes/LeaveType.php';
require __DIR__ . '/../classes/LeaveBalance.php';

$lt = new LeaveType($pdo);
$lb = new LeaveBalance($pdo);
$error = $ok = '';

$types = $lt->getActive();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id   = (int)($_POST['employee_id'] ?? 0);
    $leave_type_id = (int)($_POST['leave_type_id'] ?? 0);
    $year          = (int)($_POST['year'] ?? date('Y'));
    $allocated     = (float)($_POST['allocated_days'] ?? 0);

    if ($employee_id <= 0 || $leave_type_id <= 0 || $year <= 0 || $allocated < 0) {
        $error = 'Please enter valid values.';
    } else {
        try {
            $lb->upsertAllocated($employee_id, $leave_type_id, $year, $allocated);
            $ok = 'Saved.';
        } catch (PDOException $e) {
            $error = 'Unable to save allocation.';
        }
    }
}

require __DIR__ . '/../templates/hrTemplates/header.php';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<h1>Leave Balances</h1>
<?php if ($error): ?><p style="color:#b00"><?= h($error) ?></p><?php endif; ?>
<?php if ($ok): ?><p style="color:green"><?= h($ok) ?></p><?php endif; ?>

<form method="POST">
  <div><label>Employee ID (internal id)</label>
    <input type="number" name="employee_id" required></div>
  <div><label>Leave Type</label>
    <select name="leave_type_id" required>
      <option value="">Select</option>
      <?php foreach ($types as $t): ?>
        <option value="<?= (int)$t['id'] ?>"><?= h($t['leave_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>Year</label>
    <input type="number" name="year" min="2000" max="2100" value="<?= (int)date('Y') ?>"></div>
  <div><label>Allocated Days</label>
    <input type="number" name="allocated_days" min="0" step="0.5" value="0"></div>
  <button type="submit">Save</button>
</form>