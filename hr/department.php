<?php

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/Department.php';

$departmentModel = new Department($pdo);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $data = [
        'code' => trim($_POST['code'] ?? ''),
        'name' => trim($_POST['name'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'status' => $_POST['status'] ?? 'Active'
    ];

    if ($data['code'] === '' || $data['name'] === '') {
        $error = 'Department code and name are required.';
    } else {
        try {

            $departmentModel->create($data);

            header('Location: department.php');
            exit;

        } catch (PDOException $e) {

            $error = $e->getMessage();

        }
    }
}

$departments = $departmentModel->getAll();

require __DIR__ . '/../templates/hrTemplates/header.php';
?>

<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">ORGANIZATION</p>
    <h1>Department Management</h1>
    <p>Create departments and review the organization structure.</p>
  </div>
  <div class="ph-actions">
    <a class="button" href="position.php">View Positions →</a>
  </div>
</section>

<?php if ($error !== ''): ?>
  <div class="error" style="margin-bottom:20px"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="dashboard-grid">
  <div class="panel table-card">
    <div class="panel-head">
      <h2>Departments</h2>
      <span class="muted"><?= count($departments) ?> record<?= count($departments) === 1 ? '' : 's' ?></span>
    </div>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Code</th>
          <th>Name</th>
          <th>Description</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($departments)): ?>
          <tr>
            <td colspan="5">
              <div class="empty-state"><span class="es-ico">▤</span>No departments found.</div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($departments as $department): ?>
            <?php $active = strcasecmp((string)($department['status'] ?? ''), 'Active') === 0; ?>
            <tr>
              <td><?= htmlspecialchars($department['id']) ?></td>
              <td><strong><?= htmlspecialchars($department['code']) ?></strong></td>
              <td><?= htmlspecialchars($department['name']) ?></td>
              <td><?= htmlspecialchars($department['description'] ?? '') ?></td>
              <td><span class="pill <?= $active ? 'pill-active' : 'pill-inactive' ?>"><?= htmlspecialchars($department['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <aside class="panel side-form">
    <div class="panel-head"><h2>Add Department</h2></div>
    <div class="panel-body">
      <form method="POST">
        <div>
          <label for="code">Department Code *</label>
          <input type="text" id="code" name="code" required>
        </div>
        <div>
          <label for="name">Department Name *</label>
          <input type="text" id="name" name="name" required>
        </div>
        <div>
          <label for="description">Description</label>
          <textarea id="description" name="description"></textarea>
        </div>
        <div>
          <label for="status">Status</label>
          <select id="status" name="status">
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>
        <button class="primary-button" type="submit">Add Department</button>
      </form>
    </div>
  </aside>
</div>
