<?php
// admin/users.php: user accounts, their role and their status.
require_once __DIR__ . '/../authentication/guard.php';
$account = require_access('user_management', 'view', 'all');
require_once __DIR__ . '/../classes/AuditLogger.php';


require_once __DIR__ . '/../config/helpers.php'; // h(), fullName()
require_once __DIR__ . '/../classes/User.php';

$canEdit  = Rbac::can($pdo, $account['role'], 'user_management', 'edit');
$isAdmin = Rbac::normalizeRole($account['role']) === 'admin';

// Role assignment is restricted to administrators,
// regardless of the general user_management edit permission.
$canAssignRoles = $isAdmin;
$roles    = Rbac::ROLES;
$ranks = Rbac::RANKS;
$statuses = ['Active', 'Inactive'];
$message  = '';
$error    = '';

if (!empty($_SESSION['user_management_message'])) {
  $message = $_SESSION['user_management_message'];
  unset($_SESSION['user_management_message']);
}

if (!empty($_SESSION['user_management_error'])) {
  $error = $_SESSION['user_management_error'];
  unset($_SESSION['user_management_error']);
}

if (isset($_SESSION['user_message'])) {
  $message = $_SESSION['user_message'];
  unset($_SESSION['user_message']);
}

if (isset($_SESSION['user_error'])) {
  $error = $_SESSION['user_error'];
  unset($_SESSION['user_error']);
}

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  if (!$canEdit) {
    guard_forbidden($account['role']);
  }
  csrf_check($account['role']);

  $targetId  = (int)($_POST['user_id'] ?? 0);
  $newRole = Rbac::normalizeRole($_POST['role'] ?? '');
  $newRank = Rbac::normalizeRank($_POST['rank'] ?? 'employee');
  $newStatus = (string)($_POST['status'] ?? '');

  $st = $pdo->prepare('SELECT
    u.id,
    u.username,
    u.role,
    u.status,
    u.employee_id,
    e.rank
FROM users u
LEFT JOIN employees e
    ON e.id = u.employee_id
WHERE u.id = :id
LIMIT 1');
  $st->execute(['id' => $targetId]);
  $target = $st->fetch(PDO::FETCH_ASSOC);

  if (!$target) {
    $error = 'That user no longer exists.';
  } elseif ($targetId === $account['user_id']) {
    $error = 'You cannot change your own role or status. Ask another admin.';
  } elseif (!in_array($newRole, $roles, true)) {
    $error = 'Unknown role.';
  } elseif (
    $newRole !== Rbac::normalizeRole($target['role'])
    && !$canAssignRoles
  ) {
    $error = 'Only administrators can assign or change user roles.';
  } elseif (!in_array($newRank, $ranks, true)) {
    $error = 'Unknown rank.';
  } elseif (!in_array($newStatus, $statuses, true)) {
    $error = 'Unknown status.';
  } elseif (
    in_array($newRole, ['employee', 'hr'], true)
    && $target['employee_id'] === null
  ) {
    $error = "The {$newRole} role needs a linked employee record, and this account has none.";
  } else {
    // Never leave the system without an active admin.
    $wasActiveAdmin = Rbac::normalizeRole($target['role']) === 'admin'
      && strcasecmp((string)$target['status'], 'Active') === 0;
    if ($wasActiveAdmin && ($newRole !== 'admin' || $newStatus !== 'Active')) {
      $c = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'admin' AND status = 'Active' AND id <> :id");
      $c->execute(['id' => $targetId]);
      if ((int)$c->fetchColumn() === 0) {
        $error = 'This is the last active admin. Make another admin first.';
      }
    }
  }

  if ($error === '') {
    $up = $pdo->prepare(
      'UPDATE users
     SET role = :r,
         status = :s,
         updated_at = NOW()
     WHERE id = :id'
    );

    $up->execute([
      'r' => $newRole,
      's' => $newStatus,
      'id' => $targetId
    ]);

    if ($target['employee_id'] !== null) {
      $rankUpdate = $pdo->prepare(
        'UPDATE employees
         SET `rank` = :rank,
             updated_at = NOW()
         WHERE id = :employee_id'
      );

      $rankUpdate->execute([
        'rank' => $newRank,
        'employee_id' => $target['employee_id']
      ]);
    }
    AuditLogger::log('user.update', 'user', $targetId, [
      'username'   => $target['username'],
      'old_role'   => $target['role'],
      'new_role'   => $newRole,
      'old_rank'   => $target['rank'] ?? 'employee',
      'new_rank'   => $newRank,
      'old_status' => $target['status'],
      'new_status' => $newStatus,
    ]);

    $message = "Saved {$target['username']}: role {$newRole}, rank {$newRank}, status {$newStatus}. "
      . 'If they are signed in, the change applies on their next click.';
  }
}

$userModel = new User($pdo);
$users = $userModel->getAll();

// Get departments for filtering.
$deptStmt = $pdo->query("
    SELECT id, code, name
    FROM departments
    WHERE status = 'Active'
    ORDER BY name ASC
");

$departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);

// Search and filters.
$search = trim($_GET['search'] ?? '');
$departmentFilter = (string)($_GET['department'] ?? '');
$statusFilter = (string)($_GET['account_status'] ?? 'all');
$sort = (string)($_GET['sort'] ?? 'newest');

// Validate sort options.
$allowedSorts = [
  'newest',
  'name_asc',
  'name_desc',
  'username_asc',
  'department_asc'
];

if (!in_array($sort, $allowedSorts, true)) {
  $sort = 'newest';
}

// Filter users.
$filteredUsers = array_filter($users, function ($u) use (
  $search,
  $departmentFilter,
  $statusFilter
) {
  $completed = (int)$u['setup_completed'] === 1;
  $active = strcasecmp((string)$u['status'], 'Active') === 0;

  // Search.
  if ($search !== '') {
    $searchable = [
      $u['username'] ?? '',
      $u['employee_no'] ?? '',
      $u['first_name'] ?? '',
      $u['middle_name'] ?? '',
      $u['last_name'] ?? ''
    ];

    $found = false;

    foreach ($searchable as $value) {
      if (stripos((string)$value, $search) !== false) {
        $found = true;
        break;
      }
    }

    if (!$found) {
      return false;
    }
  }

  // Department filter.
  if ($departmentFilter !== '') {
    if ((string)($u['department_id'] ?? '') !== $departmentFilter) {
      return false;
    }
  }

  // Account status filter.
  if ($statusFilter === 'pending' && $completed) {
    return false;
  }

  if ($statusFilter === 'active' && (!$completed || !$active)) {
    return false;
  }

  if ($statusFilter === 'inactive' && (!$completed || $active)) {
    return false;
  }

  return true;
});

// Sorting.
$filteredUsers = array_values($filteredUsers);

usort($filteredUsers, function ($a, $b) use ($sort) {
  switch ($sort) {
    case 'name_asc':
      return strcasecmp(
        ($a['last_name'] ?? '') . ($a['first_name'] ?? ''),
        ($b['last_name'] ?? '') . ($b['first_name'] ?? '')
      );

    case 'name_desc':
      return strcasecmp(
        ($b['last_name'] ?? '') . ($b['first_name'] ?? ''),
        ($a['last_name'] ?? '') . ($a['first_name'] ?? '')
      );

    case 'username_asc':
      return strcasecmp($a['username'], $b['username']);

    case 'department_asc':
      return strcasecmp(
        $a['department_name'] ?? '',
        $b['department_name'] ?? ''
      );

    default:
      return 0;
  }
});

// Use filtered users for display.
$displayUsers = $filteredUsers;

// Lightweight counts for the metric strip.
// Account metrics.
$totalUsers = count($users);
$activeUsers = 0;
$inactiveUsers = 0;
$pendingUsers = 0;

foreach ($users as $u) {

  $completed = (int)$u['setup_completed'] === 1;

  $active = strcasecmp(
    (string)$u['status'],
    'Active'
  ) === 0;

  if (!$completed) {
    $pendingUsers++;
  } elseif ($active) {
    $activeUsers++;
  } else {
    $inactiveUsers++;
  }
}
require __DIR__ . '/../templates/adminTemplates/header.php';
?>

<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">ACCESS CONTROL</p>
    <h1>User Management</h1>
    <p>Create and review the login accounts that can access the HRIS.</p>
  </div>
  <div class="ph-actions">
    <?php if ($canEdit): ?>
      <a class="primary-button" href="/admin/create_user.php">＋ Create User</a>
    <?php endif; ?>
    <a class="button" href="/admin/permissions.php">View permissions</a>
  </div>
</section>

<?php if ($message !== ''): ?>
  <div class="alert" style="margin-bottom:20px;border-color:rgba(52,211,153,.4);background:rgba(52,211,153,.12);color:#c7f9e5"><?= h($message) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert error" style="margin-bottom:20px"><?= h($error) ?></div>
<?php endif; ?>

<div class="metric-strip">
  <article class="metric-card metric-teal">
    <div class="metric-top"><span class="metric-ico">◉</span><span class="metric-chip">All</span></div>
    <span class="metric-title">Total Users</span>
    <strong class="metric-number teal"><?= number_format($totalUsers) ?></strong>
  </article>
  <article class="metric-card metric-green">
    <div class="metric-top"><span class="metric-ico">✓</span></div>
    <span class="metric-title">Active</span>
    <strong class="metric-number green"><?= number_format($activeUsers) ?></strong>
  </article>
  <article class="metric-card metric-violet">
    <div class="metric-top">
      <span class="metric-ico">◷</span>
    </div>

    <span class="metric-title">Pending Setup</span>

    <strong class="metric-number purple">
      <?= number_format($pendingUsers) ?>
    </strong>
  </article>
  <article class="metric-card metric-blue">
    <div class="metric-top"><span class="metric-ico">✕</span></div>
    <span class="metric-title">Inactive</span>
    <strong class="metric-number blue"><?= number_format($inactiveUsers) ?></strong>
  </article>
</div>

<form method="GET" class="user-filters">

  <div class="filter-search">
    <label for="search">Search Users</label>

    <input
      type="search"
      id="search"
      name="search"
      placeholder="Search username, name, employee number..."
      value="<?= h($search) ?>">
  </div>

  <div>
    <label for="department">Department</label>

    <select name="department" id="department">
      <option value="">All Departments</option>

      <?php foreach ($departments as $dept): ?>
        <option
          value="<?= (int)$dept['id'] ?>"
          <?= $departmentFilter === (string)$dept['id']
            ? 'selected'
            : '' ?>>

          <?= h($dept['name']) ?>

        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div>
    <label for="account_status">Account Status</label>

    <select name="account_status" id="account_status">
      <option value="all">All Accounts</option>
      <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>
        Pending Setup
      </option>
      <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
        Active
      </option>
      <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
        Inactive
      </option>
    </select>
  </div>

  <div>
    <label for="sort">Sort By</label>

    <select name="sort" id="sort">
      <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>
        Newest First
      </option>
      <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>
        Name A-Z
      </option>
      <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>
        Name Z-A
      </option>
      <option value="username_asc" <?= $sort === 'username_asc' ? 'selected' : '' ?>>
        Username A-Z
      </option>
      <option value="department_asc" <?= $sort === 'department_asc' ? 'selected' : '' ?>>
        Department A-Z
      </option>
    </select>
  </div>

  <div class="filter-actions">
    <button type="submit" class="primary-button">
      Apply Filters
    </button>

    <a href="/admin/users.php" class="button">
      Reset
    </a>
  </div>

</form>

<div class="panel table-card">
  <div class="panel-head">
    <h2>Accounts</h2>
    <span class="muted"><?= number_format($totalUsers) ?> user<?= $totalUsers === 1 ? '' : 's' ?></span>
  </div>
  <table>
    <thead>
      <tr>
        <th>Username</th>
        <th>Role</th>
        <th>Rank</th>
        <th>Status</th>
        <th>Employee</th>
        <th>Department</th>
        <?php if ($canEdit): ?><th>Change</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($displayUsers)): ?>
        <tr>
          <td colspan="<?= $canEdit ? 7 : 6 ?>">
            <div class="empty-state">
              <span class="es-ico">◉</span>
              No users found.
            </div>
          </td>
        </tr>
        <?php else: foreach ($displayUsers as $u): ?>
          <?php
          $isActive = strcasecmp((string)($u['status'] ?? ''), 'Active') === 0;
          $isPending = (int)($u['setup_completed'] ?? 1) === 0;
          $pillClass = $isActive ? 'pill-active' : (($u['status'] ?? '') !== '' ? 'pill-inactive' : 'pill-neutral');
          $isMe  = (int)$u['id'] === $account['user_id'];
          $uRole = Rbac::normalizeRole($u['role']);
          ?>
          <tr>
            <td><strong><?= h($u['username']) ?></strong></td>
            <td>
              <span class="pill pill-neutral">
                <?= h(ucfirst((string)$u['role'])) ?>
              </span>
            </td>

            <td>
              <span class="pill pill-neutral">
                <?= h(
                  ucwords(
                    str_replace('_', ' ', $u['rank'] ?? 'employee')
                  )
                ) ?>
              </span>
            </td>

            <td>

              <?php if ((int)$u['setup_completed'] === 0): ?>

                <span class="pill pill-pending">
                  Pending Setup
                </span>

              <?php elseif (strcasecmp($u['status'], 'Active') === 0): ?>

                <span class="pill pill-active">
                  Active
                </span>

              <?php else: ?>

                <span class="pill pill-inactive">
                  Inactive
                </span>

              <?php endif; ?>
            </td>
            <td>
              <?php if ($u['employee_id']): ?>
                <strong><?= h($u['employee_no']) ?></strong> — <?= h(fullName($u)) ?>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($u['department_name'])): ?>
                <?= h($u['department_name']) ?>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <?php if ($canEdit): ?>
              <td>
                <?php if ((int)$u['setup_completed'] === 0): ?>

                  <span class="pill pill-pending">
                    Awaiting Password
                  </span>

                  <form method="post" action="/admin/resend_invitation.php"
                    style="margin-top:8px">

                    <?= csrf_field() ?>

                    <input
                      type="hidden"
                      name="user_id"
                      value="<?= (int)$u['id'] ?>">

                    <button type="submit" class="primary-button">
                      Resend Invitation
                    </button>

                  </form>

                <?php elseif ($isMe): ?>

                  <span class="muted">Your own account</span>

                <?php else: ?>
                  <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <?php if ($canAssignRoles): ?>
                      <select name="role"
                        aria-label="Role for <?= h($u['username']) ?>">
                        <?php foreach ($roles as $r): ?>
                          <option value="<?= h($r) ?>"
                            <?= $r === $uRole ? 'selected' : '' ?>>
                            <?= h($r) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?>
                      <input
                        type="hidden"
                        name="role"
                        value="<?= h($uRole) ?>">

                      <span class="pill pill-neutral">
                        <?= h(ucfirst($uRole)) ?>
                      </span>
                    <?php endif; ?>
                    <select
                      name="rank"
                      aria-label="Rank for <?= h($u['username']) ?>">

                      <?php foreach ($ranks as $rank): ?>
                        <option
                          value="<?= h($rank) ?>"
                          <?= ($rank === ($u['rank'] ?? 'employee'))
                            ? 'selected'
                            : '' ?>>

                          <?= h(
                            ucwords(str_replace('_', ' ', $rank))
                          ) ?>
                        </option>
                      <?php endforeach; ?>

                    </select>

                    <select name="status" aria-label="Status for <?= h($u['username']) ?>">
                      <?php foreach ($statuses as $s): ?>
                        <option value="<?= h($s) ?>" <?= $isActive === ($s === 'Active') ? 'selected' : '' ?>><?= h($s) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="primary-button" type="submit">Save</button>
                  </form>

                  <?php if ($isPending): ?>

                    <form
                      method="post"
                      action="/admin/delete_pending_account.php"
                      style="margin-top:8px"
                      onsubmit="return confirm('Are you sure you want to permanently delete this pending account?');">

                      <?= csrf_field() ?>

                      <input
                        type="hidden"
                        name="user_id"
                        value="<?= (int)$u['id'] ?>">

                      <button
                        type="submit"
                        class="button"
                        style="background:#991b1b;color:white;border-color:#991b1b">
                        Delete Pending Account
                      </button>

                    </form>

                  <?php endif; ?>

                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
      <?php endforeach;
      endif; ?>
    </tbody>
  </table>
</div>