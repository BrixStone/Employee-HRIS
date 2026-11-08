<?php
// admin/permissions.php: System Settings. Shows the live permission matrix.
require_once __DIR__ . '/../authentication/guard.php';
$account = require_access('system_settings', 'view', 'all');

require_once __DIR__ . '/../config/helpers.php'; // h()

$matrix = Rbac::matrix($pdo);

$levelText = [
    'full'         => 'Full',
    'manage'       => 'Manage',
    'view'         => 'View',
    'team'         => 'Team only',
    'approve_team' => 'Approve team',
    'own'          => 'Own only',
    'submit_own'   => 'Submit own',
    'none'         => 'No access',
];

$cssPageOverride = 'users';
require __DIR__ . '/../templates/adminTemplates/header.php';
?>
<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">ADMINISTRATION</p>
    <h1>System Settings</h1>
    <p>Who can do what. This is the live permission matrix every page and API call checks.</p>
  </div>
  <div class="ph-actions"><a class="button" href="/admin/users.php">User management</a></div>
</section>

<div class="panel table-card">
  <div class="panel-head"><h2>Roles and permissions</h2></div>
  <table>
    <thead>
      <tr>
        <th>Module</th>
        <?php foreach (Rbac::ROLES as $r): ?><th><?= h(ucfirst($r)) ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach (Rbac::MODULES as $key => $label): ?>
        <tr>
          <td><?= h($label) ?></td>
          <?php foreach (Rbac::ROLES as $r):
            $level = Rbac::level($pdo, $r, $key);
          ?>
            <td><span class="pill <?= $level === 'none' ? 'pill-inactive' : 'pill-active' ?>"><?= h($levelText[$level] ?? $level) ?></span></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel" style="margin-top:20px">
  <div class="panel-head"><h2>How to change it</h2></div>
  <div class="panel-body">
    <p>The matrix is stored in the database table <strong>role_permissions</strong>. Open phpMyAdmin
       (<a href="http://localhost:8080" target="_blank" rel="noopener">localhost:8080</a>), choose database
       <strong>hris</strong>, open <strong>role_permissions</strong>, and change the <em>access</em> column.
       The change applies on the next click, with no restart.</p>
    <p>Allowed access values:</p>
    <ul>
      <li><strong>full</strong>: everything, on everyone's data</li>
      <li><strong>manage</strong>: view, change and approve, on everyone's data</li>
      <li><strong>view</strong>: view only, on everyone's data</li>
      <li><strong>team</strong>: view only, for the manager's direct reports</li>
      <li><strong>approve_team</strong>: view and approve, for the manager's direct reports</li>
      <li><strong>own</strong>: view and edit the person's own record</li>
      <li><strong>submit_own</strong>: view and submit the person's own requests</li>
      <li><strong>none</strong>: no access</li>
    </ul>
    <p class="muted">Safety nets: admins always keep full access to User Management and System Settings, so a
       wrong edit can never lock every admin out. If the table is empty or missing, the defaults above are used.
       To restore the defaults, run
       <code>docker exec hris_app php /app/database/migrate_rbac.php --reset</code>.</p>
  </div>
</div>
  </section>
</main>
</body>
</html>
