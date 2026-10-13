<?php
require __DIR__ . '/../config/supabase.php';
require __DIR__ . '/../classes/LeaveType.php';

$lt = new LeaveType($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'leave_name'   => trim($_POST['leave_name'] ?? ''),
        'description'  => trim($_POST['description'] ?? ''),
        'default_days' => (int)($_POST['default_days'] ?? 0),
        'status'       => $_POST['status'] ?? 'Active',
    ];
    if ($data['leave_name'] === '' || $data['default_days'] < 0) {
        $error = 'Leave name required; default days >= 0.';
    } else {
        try {
            $lt->create($data);
            header('Location: /hr/leave_types.php'); exit;
        } catch (PDOException $e) {
            $error = (str_contains($e->getMessage(),'23505') ? 'Leave name already exists.' : 'Unable to create leave type.');
        }
    }
}

$types = $lt->getAll();
require __DIR__ . '/../templates/hrTemplates/header.php';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<h1>Leave Types</h1>
<?php if ($error): ?><p style="color:#b00"><?= h($error) ?></p><?php endif; ?>
<form method="POST">
  <div><label>Leave Name *</label><input type="text" name="leave_name" required></div>
  <div><label>Default Days</label><input type="number" name="default_days" min="0" step="1" value="0"></div>
  <div><label>Description</label><textarea name="description"></textarea></div>
  <div><label>Status</label>
    <select name="status"><option value="Active">Active</option><option value="Inactive">Inactive</option></select>
  </div>
  <button type="submit">Add</button>
</form>
<hr>
<table border="1" cellpadding="6" cellspacing="0">
  <thead><tr><th>ID</th><th>Leave Name</th><th>Default Days</th><th>Status</th></tr></thead>
  <tbody>
    <?php if (empty($types)): ?>
      <tr><td colspan="4">No leave types yet.</td></tr>
    <?php else: foreach ($types as $t): ?>
      <tr>
        <td><?= h($t['id']) ?></td>
        <td><?= h($t['leave_name']) ?></td>
        <td><?= h($t['default_days']) ?></td>
        <td><?= h($t['status']) ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>