<?php

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/Position.php';

$positionModel = new Position($pdo);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $data = [
        'code' => trim($_POST['code'] ?? ''),
        'title' => trim($_POST['title'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'status' => $_POST['status'] ?? 'Active'
    ];

    if ($data['code'] === '' || $data['title'] === '') {

        $error = 'Position code and title are required.';

    } else {

        try {

            $positionModel->create($data);

            header('Location: position.php');
            exit;

        } catch (PDOException $e) {

            $error = $e->getMessage();

        }
    }
}

$positions = $positionModel->getAll();

require __DIR__ . '/../templates/hrTemplates/header.php';
?>

<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">ORGANIZATION</p>
    <h1>Position Management</h1>
    <p>Define job positions and keep role records up to date.</p>
  </div>
  <div class="ph-actions">
    <a class="button" href="department.php">View Departments →</a>
  </div>
</section>

<?php if ($error !== ''): ?>
  <div class="error" style="margin-bottom:20px"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="dashboard-grid">
  <div class="panel table-card">
    <div class="panel-head">
      <h2>Positions</h2>
      <span class="muted"><?= count($positions) ?> record<?= count($positions) === 1 ? '' : 's' ?></span>
    </div>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Code</th>
          <th>Title</th>
          <th>Description</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($positions)): ?>
          <tr>
            <td colspan="5">
              <div class="empty-state"><span class="es-ico">▣</span>No positions found.</div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($positions as $position): ?>
            <?php $active = strcasecmp((string)($position['status'] ?? ''), 'Active') === 0; ?>
            <tr>
              <td><?= htmlspecialchars($position['id']) ?></td>
              <td><strong><?= htmlspecialchars($position['code']) ?></strong></td>
              <td><?= htmlspecialchars($position['title']) ?></td>
              <td><?= htmlspecialchars($position['description'] ?? '') ?></td>
              <td><span class="pill <?= $active ? 'pill-active' : 'pill-inactive' ?>"><?= htmlspecialchars($position['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <aside class="panel side-form">
    <div class="panel-head"><h2>Add Position</h2></div>
    <div class="panel-body">
      <form method="POST">
        <div>
          <label for="code">Position Code *</label>
          <input type="text" id="code" name="code" required>
        </div>
        <div>
          <label for="title">Position Title *</label>
          <input type="text" id="title" name="title" required>
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
        <button class="primary-button" type="submit">Add Position</button>
      </form>
    </div>
  </aside>
</div>
