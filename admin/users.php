<?php
// admin/users.php
// User directory, account details, account editing, and soft deactivation.

require_once __DIR__ . '/../authentication/guard.php';
$account = require_access('user_management', 'view', 'all');

require_once __DIR__ . '/../classes/AuditLogger.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../classes/User.php';

$canEdit = Rbac::can($pdo, $account['role'], 'user_management', 'edit');
$isAdmin = Rbac::normalizeRole($account['role']) === 'admin';
$roles = Rbac::ROLES;
$ranks = Rbac::RANKS;
$canCreateUser = $isAdmin;
$statuses = ['Active', 'Inactive'];
$currentUserId = (int)($account['user_id'] ?? $account['id'] ?? 0);
/** @var User $userModel */
$userModel = new User($pdo);
$employees = $userModel->getEmployeesWithoutUsers();


// Flash messages (supports the keys used by the existing invitation handlers).
$message = '';
$error = '';
foreach (['user_management_message', 'user_message'] as $key) {
  if (isset($_SESSION[$key]) && $_SESSION[$key] !== '') {
    $message = (string)$_SESSION[$key];
    unset($_SESSION[$key]);
  }
}
foreach (['user_management_error', 'user_error'] as $key) {
  if (isset($_SESSION[$key]) && $_SESSION[$key] !== '') {
    $error = (string)$_SESSION[$key];
    unset($_SESSION[$key]);
  }
}

// Handle account edits and account activation/deactivation.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  if (!$canEdit) {
    guard_forbidden($account['role']);
  }

  csrf_check($account['role']);

  $action = (string)($_POST['action'] ?? '');
  $targetId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
  $postError = '';

  if (!$targetId || $targetId < 1) {
    $postError = 'Please select a valid account.';
  } else {
    try {
      $pdo->beginTransaction();

      // Lock the target row while validating and updating it.
      $find = $pdo->prepare(
        'SELECT u.id, u.username, u.role, u.status, u.employee_id,
                        u.setup_completed, e.`rank`
                 FROM users u
                 LEFT JOIN employees e ON e.id = u.employee_id
                 WHERE u.id = :id
                 LIMIT 1 FOR UPDATE'
      );
      $find->execute(['id' => $targetId]);
      $target = $find->fetch(PDO::FETCH_ASSOC);

      if (!$target) {
        $postError = 'That account no longer exists.';
      } elseif ((int)$target['id'] === $currentUserId) {
        $postError = 'You cannot change your own account role or status.';
      } elseif ((int)$target['setup_completed'] !== 1) {
        $postError = 'Pending accounts must be managed through the invitation actions.';
      } elseif (Rbac::normalizeRole($target['role']) === 'admin' && !$isAdmin) {
        $postError = 'Only an administrator can edit an administrator account.';
      } elseif (!in_array($action, ['save_account', 'change_status'], true)) {
        $postError = 'Invalid account action.';
      } else {
        $newUsername = trim((string)($target['username'] ?? ''));
        $newRole = Rbac::normalizeRole($target['role'] ?? '');
        $newRank = Rbac::normalizeRank($target['rank'] ?? 'employee');
        $newStatus = (string)$target['status'];

        if ($action === 'save_account') {
          $newUsername = trim((string)($_POST['username'] ?? ''));
          $newRole = Rbac::normalizeRole($_POST['role'] ?? '');
          $newRank = Rbac::normalizeRank($_POST['rank'] ?? 'employee');
          $newStatus = (string)($_POST['status'] ?? '');

          if ($newUsername === '' || strlen($newUsername) > 100) {
            $postError = 'Username is required and must be 100 characters or fewer.';
          } elseif (!in_array($newRole, $roles, true)) {
            $postError = 'Please select a valid system role.';
          } elseif ($newRole === 'admin' && !$isAdmin) {
            $postError = 'Only an administrator can assign the Admin role.';
          } elseif (!in_array($newRank, $ranks, true)) {
            $postError = 'Please select a valid employee rank.';
          } elseif (!in_array($newStatus, $statuses, true)) {
            $postError = 'Please select a valid account status.';
          } elseif (
            in_array($newRole, ['employee', 'hr'], true)
            && $target['employee_id'] === null
          ) {
            $postError = "The {$newRole} role requires a linked employee record.";
          }
        } else {
          $requestedStatus = (string)($_POST['status'] ?? '');
          if (!in_array($requestedStatus, $statuses, true)) {
            $postError = 'Invalid account status.';
          } else {
            $newStatus = $requestedStatus;
          }
        }

        // Prevent the system from being left without an active administrator.
        $wasActiveAdmin = Rbac::normalizeRole($target['role']) === 'admin'
          && strcasecmp((string)$target['status'], 'Active') === 0;
        $willRemainActiveAdmin = $newRole === 'admin'
          && strcasecmp($newStatus, 'Active') === 0;

        if ($postError === '' && $wasActiveAdmin && !$willRemainActiveAdmin) {
          $admins = $pdo->query(
            "SELECT id FROM users
                         WHERE LOWER(role) = 'admin' AND status = 'Active'
                         FOR UPDATE"
          )->fetchAll(PDO::FETCH_COLUMN);

          if (count($admins) <= 1) {
            $postError = 'This is the last active administrator. Make another administrator first.';
          }
        }

        if ($postError === '') {
          $update = $pdo->prepare(
            'UPDATE users
                         SET username = :username,
                             role = :role,
                             status = :status,
                             updated_at = NOW()
                         WHERE id = :id'
          );
          $update->execute([
            'username' => $newUsername,
            'role' => $newRole,
            'status' => $newStatus,
            'id' => $targetId,
          ]);

          if ($target['employee_id'] !== null && $action === 'save_account') {
            $rankUpdate = $pdo->prepare(
              'UPDATE employees
                             SET `rank` = :rank, updated_at = NOW()
                             WHERE id = :employee_id'
            );
            $rankUpdate->execute([
              'rank' => $newRank,
              'employee_id' => $target['employee_id'],
            ]);
          }

          // Deactivation invalidates outstanding password reset links.
          if (
            strcasecmp($newStatus, 'Inactive') === 0
            && strcasecmp((string)$target['status'], 'Inactive') !== 0
          ) {
            $invalidate = $pdo->prepare(
              'UPDATE password_reset_tokens
                             SET used_at = NOW()
                             WHERE user_id = :id AND used_at IS NULL'
            );
            $invalidate->execute(['id' => $targetId]);
          }

          $pdo->commit();

          AuditLogger::log(
            $action === 'change_status' ? 'user.status_change' : 'user.update',
            'user',
            $targetId,
            [
              'username' => $target['username'],
              'new_username' => $newUsername,
              'old_role' => $target['role'],
              'new_role' => $newRole,
              'old_rank' => $target['rank'] ?? 'employee',
              'new_rank' => $newRank,
              'old_status' => $target['status'],
              'new_status' => $newStatus,
            ]
          );

          $_SESSION['user_management_message'] = $action === 'change_status'
            ? "Account status changed to {$newStatus}."
            : 'Account details were successfully updated.';
        }
      }

      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      if ($postError !== '') {
        $_SESSION['user_management_error'] = $postError;
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('User management update failed: ' . $e->getMessage());
      $_SESSION['user_management_error'] = 'The account could not be updated. Check the server error log.';
    }
  }

  $queryString = http_build_query($_GET);
  header('Location: /admin/users.php' . ($queryString !== '' ? '?' . $queryString : ''));
  exit;
}

// Fetch accounts and department names using the existing User model.
$hiddenUsernames = ['admin', 'hr']; // Use the exact predefined usernames.

$users = array_values(array_filter(
  $userModel->getAll(),
  function (array $user) use ($hiddenUsernames): bool {
    return !in_array(
      strtolower(trim((string)($user['username'] ?? ''))),
      array_map('strtolower', $hiddenUsernames),
      true
    );
  }
));
$deptStmt = $pdo->query(
  "SELECT id, code, name FROM departments WHERE status = 'Active' ORDER BY name ASC"
);
$departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);

// Search, filtering, and sorting.
$search = trim((string)($_GET['search'] ?? ''));
$departmentFilter = (string)($_GET['department'] ?? '');
$statusFilter = (string)($_GET['account_status'] ?? 'all');
$sort = (string)($_GET['sort'] ?? 'newest');
$allowedSorts = ['newest', 'oldest', 'name_asc', 'name_desc', 'username_asc', 'department_asc', 'department_desc'];
if (!in_array($sort, $allowedSorts, true)) {
  $sort = 'newest';
}

$filteredUsers = array_values(array_filter($users, function (array $u) use ($search, $departmentFilter, $statusFilter): bool {
  $completed = (int)($u['setup_completed'] ?? 1) === 1;
  $active = strcasecmp((string)($u['status'] ?? ''), 'Active') === 0;

  if ($search !== '') {
    $searchable = [
      $u['username'] ?? '',
      $u['employee_no'] ?? '',
      $u['first_name'] ?? '',
      $u['middle_name'] ?? '',
      $u['last_name'] ?? '',
    ];
    $found = false;
    foreach ($searchable as $value) {
      if (stripos((string)$value, $search) !== false) {
        $found = true;
        break;
      }
    }
    if (!$found) return false;
  }

  if ($departmentFilter !== '' && (string)($u['department_id'] ?? '') !== $departmentFilter) {
    return false;
  }
  if ($statusFilter === 'pending' && $completed) return false;
  if ($statusFilter === 'active' && (!$completed || !$active)) return false;
  if ($statusFilter === 'inactive' && (!$completed || $active)) return false;
  return true;
}));

usort($filteredUsers, function (array $a, array $b) use ($sort): int {
  $nameA = trim(($a['last_name'] ?? '') . ' ' . ($a['first_name'] ?? ''));
  $nameB = trim(($b['last_name'] ?? '') . ' ' . ($b['first_name'] ?? ''));
  switch ($sort) {
    case 'oldest':
      return (int)$a['id'] <=> (int)$b['id'];
    case 'name_asc':
      return strcasecmp($nameA, $nameB);
    case 'name_desc':
      return strcasecmp($nameB, $nameA);
    case 'username_asc':
      return strcasecmp((string)$a['username'], (string)$b['username']);
    case 'department_asc':
      return strcasecmp((string)($a['department_name'] ?? ''), (string)($b['department_name'] ?? ''));
    case 'department_desc':
      return strcasecmp((string)($b['department_name'] ?? ''), (string)($a['department_name'] ?? ''));
    default:
      return (int)$b['id'] <=> (int)$a['id'];
  }
});

// Account totals are based on all accounts, not just the filtered results.
$totalUsers = count($users);
$activeUsers = $inactiveUsers = $pendingUsers = 0;
foreach ($users as $u) {
  if ((int)($u['setup_completed'] ?? 1) !== 1) {
    $pendingUsers++;
  } elseif (strcasecmp((string)($u['status'] ?? ''), 'Active') === 0) {
    $activeUsers++;
  } else {
    $inactiveUsers++;
  }
}

// Ten records per page.
$perPage = 10;
$totalFiltered = count($filteredUsers);
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;
$displayUsers = array_slice($filteredUsers, $offset, $perPage);
$paginationParams = $_GET;
unset($paginationParams['page']);

require __DIR__ . '/../templates/adminTemplates/header.php';
?>

<style>
  /* User Management page layout */

  /* User Management page layout */

  .user-management {
    width: 100%;
    max-width: 1200px;
    margin-inline: auto;
    padding: 0 20px 30px;
    display: grid;
    gap: 16px;
    min-width: 0;
  }

  .user-management,
  .user-management *,
  .user-management *::before,
  .user-management *::after {
    box-sizing: border-box;
  }

  /* Heading */

  .um-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    flex-wrap: wrap;
    padding: 4px 2px 2px;
  }

  .um-heading h1 {
    margin: 5px 0 6px;
    font-size: clamp(25px, 3vw, 32px);
    line-height: 1.15;
  }

  .um-heading p:not(.um-eyebrow) {
    margin: 0;
    color: var(--muted, #aaa0b2);
    font-size: 13px;
  }

  .um-eyebrow {
    margin: 0;
    color: #8da8ff;
    font-size: 10px;
    letter-spacing: .14em;
    font-weight: 800;
  }

  .um-heading-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }

  .um-heading-actions .button,
  .um-heading-actions .primary-button {
    min-height: 38px;
    padding: 9px 13px;
  }

  /* Statistic cards */

  .um-metrics {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
  }

  .um-metric {
    position: relative;
    min-width: 0;
    padding: 15px 17px;
    border: 1px solid var(--border, rgba(255, 255, 255, .08));
    border-radius: 13px;
    background: linear-gradient(145deg, rgba(26, 35, 71, .78), rgba(16, 22, 48, .72));
  }

  .um-metric span {
    display: block;
    color: var(--muted, #aaa0b2);
    font-size: 12px;
  }

  .um-metric strong {
    display: block;
    margin-top: 7px;
    font-size: 25px;
    line-height: 1.1;
    font-weight: 750;
  }

  /* Search and filters */

  .um-filters {
    display: grid;
    grid-template-columns: minmax(230px, 1.7fr) repeat(3, minmax(145px, 1fr)) auto;
    gap: 10px;
    align-items: end;
    padding: 14px;
    border: 1px solid var(--border, rgba(255, 255, 255, .08));
    border-radius: 13px;
    background: rgba(20, 27, 55, .72);
  }

  .um-filters>div {
    min-width: 0;
  }

  .um-filters label,
  .um-modal label {
    display: block;
    margin: 0 0 6px;
    color: var(--muted, #aaa0b2);
    font-size: 11px;
    font-weight: 650;
  }

  .um-filters input,
  .um-filters select,
  .um-modal input,
  .um-modal select {
    width: 100%;
    min-width: 0;
  }

  .um-filters input,
  .um-filters select {
    height: 39px;
    padding: 8px 11px;
    font-size: 12px;
  }

  .um-filter-actions {
    display: flex;
    gap: 6px;
  }

  .um-filter-actions .button,
  .um-filter-actions .primary-button {
    height: 39px;
    padding: 8px 12px;
    font-size: 12px;
  }

  /* Account table */

  .um-table-wrap {
    width: 100%;
    overflow-x: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
  }

  .um-table-wrap::-webkit-scrollbar {
    display: none;
  }

  .um-table {
    width: 100%;
    min-width: 900px;
    table-layout: fixed;
    border-collapse: collapse;
  }

  .um-table col.col-employee {
    width: 22%;
  }

  .um-table col.col-username {
    width: 12%;
  }

  .um-table col.col-department {
    width: 13%;
  }

  .um-table col.col-role {
    width: 12%;
  }

  .um-table col.col-rank {
    width: 12%;
  }

  .um-table col.col-status {
    width: 13%;
  }

  .um-table col.col-actions {
    width: 16%;
  }

  .um-table th,
  .um-table td {
    padding: 11px 13px;
    text-align: left;
    vertical-align: middle;
    border-bottom: 1px solid var(--border, rgba(255, 255, 255, .07));
  }

  .um-table th {
    color: var(--muted, #aaa0b2);
    background: rgba(255, 255, 255, .018);
    font-size: 10px;
    font-weight: 750;
    letter-spacing: .065em;
    text-transform: uppercase;
    white-space: nowrap;
  }

  .um-table td {
    font-size: 12px;
    line-height: 1.4;
    overflow-wrap: anywhere;
  }

  .um-table tbody tr {
    transition: background .15s ease;
  }

  .um-table tbody tr:hover {
    background: rgba(126, 151, 255, .045);
  }

  .um-table tbody tr:last-child td {
    border-bottom: 0;
  }

  .um-employee-name {
    display: block;
    color: var(--text, #f4f5ff);
    font-size: 12px;
    font-weight: 700;
  }

  .um-subtext {
    display: block;
    margin-top: 3px;
    color: var(--muted, #aaa0b2);
    font-size: 11px;
  }

  .um-pill {
    display: inline-flex;
    align-items: center;
    white-space: nowrap;
    border-radius: 999px;
    padding: 4px 8px;
    font-size: 10px;
    font-weight: 750;
    background: rgba(148, 163, 184, .12);
    color: #cbd5e1;
  }

  .um-pill.active {
    background: rgba(52, 211, 153, .12);
    color: #6ee7b7;
  }

  .um-pill.inactive {
    background: rgba(248, 113, 113, .12);
    color: #fca5a5;
  }

  .um-pill.pending {
    background: rgba(251, 191, 36, .12);
    color: #fcd34d;
  }

  .um-actions {
    display: flex;
    align-items: center;
    gap: 5px;
    flex-wrap: nowrap;
    white-space: nowrap;
  }

  .um-actions form {
    display: inline-flex;
    flex: 0 0 auto;
    margin: 0;
  }

  .um-small {
    min-height: 30px;
    padding: 5px 8px;
    font-size: 10px;
    line-height: 1.2;
    border-radius: 7px;
  }

  /* Table heading and pagination */

  .user-management .table-card {
    min-width: 0;
    overflow: hidden;
    border-radius: 14px;
  }

  .user-management .table-card .panel-head {
    min-height: 54px;
    padding: 14px 17px;
  }

  .user-management .table-card .panel-head h2 {
    margin: 0;
    font-size: 14px;
  }

  .user-management .table-card .panel-head .muted {
    font-size: 11px;
  }

  .um-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border-top: 1px solid var(--border, rgba(255, 255, 255, .08));
  }

  .um-pagination>span {
    color: var(--muted, #aaa0b2);
    font-size: 11px;
  }

  .um-page-links {
    display: flex;
    align-items: center;
    gap: 5px;
  }

  .um-page-links a,
  .um-page-links span {
    display: inline-flex;
    min-width: 31px;
    height: 31px;
    align-items: center;
    justify-content: center;
    padding: 0 9px;
    border: 1px solid var(--border, rgba(255, 255, 255, .1));
    border-radius: 7px;
    color: var(--text, #f4f5ff);
    font-size: 11px;
    text-decoration: none;
  }

  .um-page-links a:hover {
    background: rgba(255, 255, 255, .07);
  }

  .um-page-links .current {
    border-color: transparent;
    background: linear-gradient(110deg, #62e8dc, #438bff);
    color: #101525;
    font-weight: 800;
  }

  .um-page-links .disabled {
    opacity: .4;
  }

  /* Alerts */

  .um-alert {
    padding: 11px 14px;
    border-radius: 10px;
    font-size: 12px;
  }

  .um-alert.success {
    background: rgba(52, 211, 153, .1);
    color: #a7f3d0;
    border: 1px solid rgba(52, 211, 153, .25);
  }

  .um-alert.error {
    background: rgba(248, 113, 113, .1);
    color: #fecaca;
    border: 1px solid rgba(248, 113, 113, .25);
  }

  /* Modal overlay */

  .user-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(8, 7, 12, .76);
    backdrop-filter: blur(5px);
  }

  .user-modal.is-open {
    display: flex;
  }

  /* Modal container */

  .um-modal {
    width: min(720px, 100%);
    max-height: 90vh;
    overflow: hidden;

    display: flex;
    flex-direction: column;

    border: 1px solid var(--border, rgba(255, 255, 255, .12));
    border-radius: 15px;
    background: #171d36;
    color: #f4eff8;
    box-shadow: 0 24px 80px rgba(0, 0, 0, .48);
  }

  /* Modal header */

  .um-modal-head {
    display: flex;
    flex-shrink: 0;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
    padding: 18px 20px;
    border-bottom: 1px solid rgba(255, 255, 255, .08);
  }

  .um-modal-head h2 {
    margin: 0 0 5px;
    font-size: 19px;
  }

  /* Modal body - scrollable */

  .um-modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    padding: 19px 20px;

    scrollbar-width: none;
    -ms-overflow-style: none;
  }

  .um-modal-body::-webkit-scrollbar {
    display: none;
  }

  /* Modal footer */

  .um-modal-foot {
    display: flex;
    flex-shrink: 0;
    justify-content: flex-end;
    gap: 8px;
    padding: 14px 20px;
    border-top: 1px solid rgba(255, 255, 255, .08);
  }

  .um-close {
    border: 0;
    background: transparent;
    color: #c9bfd2;
    font-size: 24px;
    cursor: pointer;
  }

  /* Modal content */

  .um-detail-grid,
  .um-edit-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .um-detail {
    min-width: 0;
    padding: 11px;
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 9px;
  }

  .um-detail small {
    display: block;
    margin-bottom: 5px;
    color: #aaa0b2;
    font-size: 10px;
  }

  .um-detail strong {
    font-size: 12px;
    overflow-wrap: anywhere;
  }

  .um-section-title {
    margin: 18px 0 9px;
    color: #aebeff;
    font-size: 12px;
  }

  /* Responsive layout */

  @media(max-width:1150px) {
    .um-filters {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .um-filter-actions {
      grid-column: 1/-1;
    }
  }

  @media(max-width:850px) {
    .um-metrics {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media(max-width:560px) {
    .user-management {
      gap: 13px;
    }

    .um-heading {
      align-items: flex-start;
    }

    .um-heading-actions {
      width: 100%;
    }

    .um-heading-actions>a {
      flex: 1;
      text-align: center;
    }

    .um-filters,
    .um-edit-grid,
    .um-detail-grid {
      grid-template-columns: 1fr;
    }

    .um-filter-actions {
      grid-column: auto;
    }

    .um-filter-actions>* {
      flex: 1;
      text-align: center;
    }

    .um-metrics {
      gap: 8px;
    }

    .um-metric {
      padding: 12px;
    }

    .um-metric strong {
      font-size: 22px;
    }

    .um-pagination {
      align-items: flex-start;
      flex-direction: column;
    }

    .um-page-links {
      max-width: 100%;
      flex-wrap: wrap;
    }

    .um-modal {
      max-height: 95vh;
    }
  }

  /* Hide page scrollbar without disabling scrolling */

  .user-management {
    scrollbar-width: none;
    -ms-overflow-style: none;
  }

  .user-management::-webkit-scrollbar {
    display: none;
  }

  .um-warning-box {
    padding: 13px;
    margin-bottom: 16px;
    border: 1px solid rgba(248, 113, 113, .25);
    border-radius: 9px;
    background: rgba(248, 113, 113, .08);
  }

  .um-warning-box strong {
    color: #fca5a5;
    font-size: 12px;
  }

  .um-warning-box p {
    margin: 6px 0 0;
    color: #d1c8d8;
    font-size: 12px;
    line-height: 1.5;
  }

  .danger-button {
    background: #dc4545 !important;
    color: white !important;
    border-color: #dc4545 !important;
  }

  .um-modal>form {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;
  }

  .um-modal>form .um-modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
  }
</style>

<div class="user-management">
  <section class="um-heading">
    <div>
      <p class="um-eyebrow">ACCESS CONTROL</p>
      <h1>User Management</h1>
      <p class="muted">View employee accounts and manage access to the HRIS.</p>
    </div>
    <div class="um-heading-actions">
      <?php if ($canEdit): ?>
        <button
          type="button"
          class="primary-button"
          id="openCreateUserModal">
          ＋ Create User
        </button>
      <?php endif; ?>
      <a class="button" href="/admin/permissions.php">View permissions</a>
    </div>
  </section>

  <?php if ($message !== ''): ?><div class="um-alert success"><?= h($message) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="um-alert error"><?= h($error) ?></div><?php endif; ?>

  <section class="um-metrics">
    <article class="um-metric"><span>Total Users</span><strong><?= number_format($totalUsers) ?></strong></article>
    <article class="um-metric"><span>Active</span><strong><?= number_format($activeUsers) ?></strong></article>
    <article class="um-metric"><span>Pending Setup</span><strong><?= number_format($pendingUsers) ?></strong></article>
    <article class="um-metric"><span>Inactive</span><strong><?= number_format($inactiveUsers) ?></strong></article>
  </section>

  <form method="get" class="um-filters">
    <div>
      <label for="um-search">Search users</label>
      <input id="um-search" type="search" name="search" value="<?= h($search) ?>" placeholder="Name, username, employee no...">
    </div>
    <div>
      <label for="um-department">Department</label>
      <select id="um-department" name="department">
        <option value="">All departments</option>
        <?php foreach ($departments as $dept): ?>
          <option value="<?= (int)$dept['id'] ?>" <?= $departmentFilter === (string)$dept['id'] ? 'selected' : '' ?>><?= h($dept['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="um-status">Account status</label>
      <select id="um-status" name="account_status">
        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All accounts</option>
        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending setup</option>
      </select>
    </div>
    <div>
      <label for="um-sort">Sort by</label>
      <select id="um-sort" name="sort">
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A–Z</option>
        <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z–A</option>
        <option value="username_asc" <?= $sort === 'username_asc' ? 'selected' : '' ?>>Username A–Z</option>
        <option value="department_asc" <?= $sort === 'department_asc' ? 'selected' : '' ?>>Department A–Z</option>
        <option value="department_desc" <?= $sort === 'department_desc' ? 'selected' : '' ?>>Department Z–A</option>
      </select>
    </div>
    <div class="um-filter-actions">
      <button type="submit" class="primary-button">Apply</button>
      <a class="button" href="/admin/users.php">Reset</a>
    </div>
  </form>

  <section class="panel table-card">
    <div class="panel-head">
      <h2>Employee Accounts</h2>
      <span class="muted"><?= number_format($totalFiltered) ?> result<?= $totalFiltered === 1 ? '' : 's' ?></span>
    </div>
    <div class="um-table-wrap">
      <table class="um-table">
        <colgroup>
          <col class="col-employee">
          <col class="col-username">
          <col class="col-department">
          <col class="col-role">
          <col class="col-rank">
          <col class="col-status">
          <col class="col-actions">
        </colgroup>
        <thead>
          <tr>
            <th>Employee</th>
            <th>Username</th>
            <th>Department</th>
            <th>System role</th>
            <th>Rank</th>
            <th>Account status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$displayUsers): ?>
            <tr>
              <td colspan="7">
                <div class="empty-state">No users found.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($displayUsers as $u): ?>
              <?php
              $isPending = (int)($u['setup_completed'] ?? 1) !== 1;
              $isActive = strcasecmp((string)($u['status'] ?? ''), 'Active') === 0;
              $isMe = (int)$u['id'] === $currentUserId;
              $fullName = trim(implode(' ', array_filter([
                $u['first_name'] ?? '',
                $u['middle_name'] ?? '',
                $u['last_name'] ?? ''
              ], static fn($part) => trim((string)$part) !== '')));
              $rankLabel = ucwords(str_replace('_', ' ', (string)($u['rank'] ?? 'employee')));
              $details = $userModel->getAccountDetails((int)$u['id']) ?? $u;
              $detailsJson = json_encode($details, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
              ?>
              <tr>
                <td>
                  <span class="um-employee-name"><?= h($fullName !== '' ? $fullName : 'Unlinked account') ?></span>
                  <span class="um-subtext"><?= h($u['employee_no'] ?? 'No employee record') ?></span>
                </td>
                <td><?= h($u['username']) ?></td>
                <td><?= h($u['department_name'] ?? '—') ?></td>
                <td><span class="um-pill"><?= h(ucfirst((string)$u['role'])) ?></span></td>
                <td><?= h($rankLabel) ?></td>
                <td>
                  <?php if ($isPending): ?>
                    <span class="um-pill pending">Pending setup</span>
                  <?php elseif ($isActive): ?>
                    <span class="um-pill active">Active</span>
                  <?php else: ?>
                    <span class="um-pill inactive">Inactive</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="um-actions">
                    <button type="button" class="button um-small" data-view-user data-user="<?= h($detailsJson ?: '{}') ?>">View</button>
                    <?php if ($canEdit && !$isMe && !$isPending): ?>
                      <button type="button" class="primary-button um-small" data-edit-user data-user="<?= h($detailsJson ?: '{}') ?>">Edit</button>
                      <button
                        type="button"
                        class="button um-small"
                        data-status-user
                        data-user-id="<?= (int)$u['id'] ?>"
                        data-username="<?= h($u['username']) ?>"
                        data-status="<?= $isActive ? 'Inactive' : 'Active' ?>">
                        <?= $isActive ? 'Deactivate' : 'Activate' ?>
                      </button>
                    <?php elseif ($canEdit && $isPending && !$isMe): ?>
                      <form method="post" action="/admin/resend_invitation.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" class="button um-small">Resend invite</button>
                      </form>
                      <form method="post" action="/admin/delete_pending_account.php" onsubmit="return confirm('Permanently delete this pending account?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" class="button um-small">Delete pending</button>
                      </form>
                    <?php elseif ($isMe): ?>
                      <span class="um-subtext">Your account</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="um-pagination">
      <span class="muted">Showing <?= $totalFiltered === 0 ? 0 : $offset + 1 ?>–<?= min($offset + $perPage, $totalFiltered) ?> of <?= number_format($totalFiltered) ?></span>
      <div class="um-page-links">
        <?php if ($page > 1): ?>
          <a href="?<?= h(http_build_query(array_merge($paginationParams, ['page' => $page - 1]))) ?>">Previous</a>
        <?php endif; ?>
        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
          <?php if ($p === $page): ?><span class="current"><?= $p ?></span>
          <?php else: ?><a href="?<?= h(http_build_query(array_merge($paginationParams, ['page' => $p]))) ?>"><?= $p ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?<?= h(http_build_query(array_merge($paginationParams, ['page' => $page + 1]))) ?>">Next</a>
        <?php endif; ?>
      </div>
    </div>
  </section>
</div>
<!-- Create User Modal -->
<?php if ($canCreateUser): ?>
  <div class="user-modal" id="createUserModal" aria-hidden="true">
    <section
      class="um-modal"
      role="dialog"
      aria-modal="true"
      aria-labelledby="createUserModalTitle">

      <form id="createUserForm" method="POST" action="/admin/create_user.php">

        <?= csrf_field() ?>

        <header class="um-modal-head">
          <div>
            <p class="um-eyebrow">ACCESS CONTROL</p>
            <h2 id="createUserModalTitle">Create New User</h2>
            <p class="muted">Create an account and send a password setup invitation.</p>
          </div>

          <button
            type="button"
            class="um-close"
            data-close-modal
            aria-label="Close">&times;</button>
        </header>

        <div class="um-modal-body">

          <div id="createUserFeedback" hidden></div>

          <h3 class="um-section-title">Account Information</h3>

          <div class="um-edit-grid">
            <div>
              <label for="create_employee_id">Link Employee</label>

              <select id="create_employee_id" name="employee_id">
                <option value="">-- No employee linked --</option>

                <?php foreach ($employees as $emp): ?>
                  <?php
                  $employeeCode = !empty($emp['employee_id'])
                    ? '[' . $emp['employee_id'] . '] '
                    : '';

                  $employeeName = trim(
                    ($emp['last_name'] ?? '') . ', ' .
                      ($emp['first_name'] ?? '')
                  );
                  ?>

                  <option value="<?= (int)$emp['id'] ?>">
                    <?= h($employeeCode . $employeeName) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="create_username">Username</label>
              <input
                type="text"
                id="create_username"
                name="username"
                minlength="3"
                maxlength="100"
                required
                placeholder="Enter username">
            </div>
          </div>

          <div style="margin-top:15px">
            <label for="create_email">Invitation Email</label>
            <input
              type="email"
              id="create_email"
              name="email"
              placeholder="employee@example.com">

            <p class="um-subtext">
              Linked employees will receive their invitation at their registered email address.
            </p>
          </div>

          <h3 class="um-section-title">Access & Permissions</h3>

          <div class="um-edit-grid">
            <div>
              <label for="create_role">System Role</label>

              <select id="create_role" name="role" required>
                <?php foreach ($roles as $role): ?>
                  <option
                    value="<?= h($role) ?>"
                    <?= $role === 'employee' ? 'selected' : '' ?>>
                    <?= h(ucfirst($role)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="create_status">Account Status</label>

              <select id="create_status" name="status">
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
          </div>

          <div class="um-detail" style="margin-top:18px">
            <strong>Password Setup Invitation</strong>
            <p class="um-subtext">
              The user will receive an email with a one-time link
              to create their own password. No password is required here.
            </p>
          </div>

        </div>

        <footer class="um-modal-foot">
          <button
            type="button"
            class="button"
            data-close-modal>
            Cancel
          </button>

          <button
            type="submit"
            class="primary-button"
            id="createUserSubmit">
            Create User
          </button>
        </footer>

      </form>
    </section>
  </div>
<?php endif; ?>

<!-- View account modal -->
<div class="user-modal" id="viewUserModal" aria-hidden="true">
  <section class="um-modal" role="dialog" aria-modal="true" aria-labelledby="viewModalTitle">
    <header class="um-modal-head">
      <div>
        <h2 id="viewModalTitle">Account details</h2>
        <p class="muted" id="viewModalSubtitle">Employee account information</p>
      </div>
      <button type="button" class="um-close" data-close-modal aria-label="Close">&times;</button>
    </header>
    <div class="um-modal-body">
      <h3 class="um-section-title">Account information</h3>
      <div class="um-detail-grid">
        <div class="um-detail"><small>Username</small><strong data-detail="username">—</strong></div>
        <div class="um-detail"><small>System role</small><strong data-detail="role">—</strong></div>
        <div class="um-detail"><small>Account status</small><strong data-detail="status">—</strong></div>
        <div class="um-detail"><small>Password setup</small><strong data-detail="setup">—</strong></div>
        <div class="um-detail"><small>Created</small><strong data-detail="created_at">—</strong></div>
        <div class="um-detail"><small>Last activity</small><strong data-detail="last_activity">—</strong></div>
      </div>
      <h3 class="um-section-title">Employee information</h3>
      <div class="um-detail-grid">
        <div class="um-detail"><small>Employee number</small><strong data-detail="employee_no">—</strong></div>
        <div class="um-detail"><small>Full name</small><strong data-detail="full_name">—</strong></div>
        <div class="um-detail"><small>Email</small><strong data-detail="email">—</strong></div>
        <div class="um-detail"><small>Phone</small><strong data-detail="phone">—</strong></div>
        <div class="um-detail"><small>Department</small><strong data-detail="department_name">—</strong></div>
        <div class="um-detail"><small>Position</small><strong data-detail="position_title">—</strong></div>
        <div class="um-detail"><small>Rank</small><strong data-detail="rank">—</strong></div>
        <div class="um-detail"><small>Date hired</small><strong data-detail="date_hired">—</strong></div>
        <div class="um-detail"><small>Employment type</small><strong data-detail="employment_type">—</strong></div>
        <div class="um-detail"><small>Employment status</small><strong data-detail="employment_status">—</strong></div>
      </div>
    </div>
    <footer class="um-modal-foot"><button type="button" class="button" data-close-modal>Close</button></footer>
  </section>
</div>

<?php if ($canEdit): ?>
  <div class="user-modal" id="editUserModal" aria-hidden="true">
    <section class="um-modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_account">
        <input type="hidden" name="user_id" id="edit_user_id" value="">
        <header class="um-modal-head">
          <div>
            <h2 id="editModalTitle">Edit account</h2>
            <p class="muted" id="editModalSubtitle">Update account information and system access</p>
          </div>
          <button type="button" class="um-close" data-close-modal aria-label="Close">&times;</button>
        </header>
        <div class="um-modal-body">
          <h3 class="um-section-title">Account information</h3>
          <div class="um-edit-grid">
            <div><label for="edit_username">Username</label><input type="text" id="edit_username" name="username" maxlength="100" required></div>
            <div><label for="edit_status">Account status</label><select id="edit_status" name="status">
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select></div>
          </div>
          <h3 class="um-section-title">System access</h3>
          <div class="um-edit-grid">
            <div><label for="edit_role">System role</label><select id="edit_role" name="role" required>
                <?php foreach ($roles as $r): ?>
                  <?php if ($r !== 'admin' || $isAdmin): ?><option value="<?= h($r) ?>"><?= h(ucfirst($r)) ?></option><?php endif; ?>
                <?php endforeach; ?>
              </select></div>
            <div><label for="edit_rank">Employee rank</label><select id="edit_rank" name="rank" required>
                <?php foreach ($ranks as $rank): ?><option value="<?= h($rank) ?>"><?= h(ucwords(str_replace('_', ' ', $rank))) ?></option><?php endforeach; ?>
              </select></div>
          </div>
          <p class="um-subtext">Employee department, position, and employment information are managed in Employee Management.</p>
        </div>
        <footer class="um-modal-foot">
          <button type="button" class="button" data-close-modal>Cancel</button>
          <button type="submit" class="primary-button">Save changes</button>
        </footer>
      </form>
    </section>
  </div>
<?php endif; ?>
<div class="user-modal" id="statusConfirmModal" aria-hidden="true">
  <section class="um-modal" role="dialog" aria-modal="true">

    <header class="um-modal-head">
      <div>
        <h2 id="statusModalTitle">Confirm Account Status</h2>
        <p id="statusModalDescription"></p>
      </div>

      <button type="button" class="um-close" data-close-modal>
        &times;
      </button>
    </header>

    <div class="um-modal-body">
      <div class="um-warning-box">
        <strong id="statusWarningTitle"></strong>
        <p id="statusWarningMessage"></p>
      </div>

      <p>
        Username:
        <strong id="statusModalUsername"></strong>
      </p>
    </div>

    <footer class="um-modal-foot">
      <button type="button" class="button" data-close-modal>
        Cancel
      </button>

      <form method="post" id="statusConfirmForm">
        <?= csrf_field() ?>

        <input type="hidden" name="action" value="change_status">
        <input type="hidden" name="user_id" id="statusModalUserId">
        <input type="hidden" name="status" id="statusModalValue">

        <button type="submit" class="button" id="statusConfirmButton">
          Confirm
        </button>
      </form>
    </footer>

  </section>
</div>
<script>
  (() => {
    const viewModal = document.getElementById('viewUserModal');
    const editModal = document.getElementById('editUserModal');
    const statusModal = document.getElementById('statusConfirmModal');

    const createModal = document.getElementById('createUserModal');
    const createButton = document.getElementById('openCreateUserModal');


    const createForm = document.getElementById('createUserForm');
    const createFeedback = document.getElementById('createUserFeedback');
    const createSubmit = document.getElementById('createUserSubmit');

    createForm?.addEventListener('submit', async (event) => {
      event.preventDefault();

      createFeedback.hidden = true;
      createFeedback.replaceChildren();

      createSubmit.disabled = true;
      createSubmit.textContent = 'Creating...';

      try {
        const response = await fetch(createForm.action, {
          method: 'POST',
          body: new FormData(createForm),
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
          createFeedback.className = 'um-alert error';
          createFeedback.replaceChildren();

          const message = document.createElement('p');
          message.textContent = result.message || 'Please check the form.';
          createFeedback.appendChild(message);

          if (result.errors?.length) {
            const list = document.createElement('ul');

            result.errors.forEach(error => {
              const item = document.createElement('li');
              item.textContent = error;
              list.appendChild(item);
            });

            createFeedback.appendChild(list);
          }

          createFeedback.hidden = false;
          return;
        }

        createFeedback.className = result.warning
    ? 'um-alert error'
    : 'um-alert success';

        createFeedback.textContent = result.message;
        createFeedback.hidden = false;

        createForm.reset();

        // Refresh the user table after showing the result.
        setTimeout(() => {
          window.location.reload();
        }, 1500);

      } catch (error) {
        console.error('Create user error:', error);

        createFeedback.className = 'um-alert error';
        createFeedback.textContent =
          'Something went wrong. Please try again.';
        createFeedback.hidden = false;

      } finally {
        createSubmit.disabled = false;
        createSubmit.textContent = 'Create User';
      }
    });


    createButton?.addEventListener('click', () => {
      openModal(createModal);
    });
    const safeText = (value) => {
      if (value === null || value === undefined || value === '') {
        return '—';
      }
      return String(value);
    };

    // OPEN MODAL
    const openModal = (modal) => {
      if (!modal) return;

      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    };

    // CLOSE MODAL
    const closeModal = (modal) => {
      if (!modal) return;

      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');

      if (!document.querySelector('.user-modal.is-open')) {
        document.body.style.overflow = '';
      }
    };

    // VIEW ACCOUNT
    document.querySelectorAll('[data-view-user]').forEach((button) => {
      button.addEventListener('click', () => {
        let user;

        try {
          user = JSON.parse(button.dataset.user || '{}');
        } catch (error) {
          console.error('Could not read account details:', error);
          return;
        }

        const fullName = [
          user.first_name,
          user.middle_name,
          user.last_name,
          user.suffix
        ].filter(Boolean).join(' ');

        const values = {
          username: user.username,
          role: user.role,
          status: user.status,
          setup: Number(user.setup_completed) === 1 ?
            'Completed' : 'Pending setup',
          created_at: user.created_at,
          last_activity: user.last_activity,
          employee_no: user.employee_no,
          full_name: fullName,
          email: user.email,
          phone: user.phone,
          department_name: user.department_name,
          position_title: user.position_title,
          rank: user.rank ?
            String(user.rank).replaceAll('_', ' ') : '',
          date_hired: user.date_hired,
          employment_type: user.employment_type,
          employment_status: user.employment_status
        };

        Object.entries(values).forEach(([key, value]) => {
          const node = viewModal?.querySelector(
            `[data-detail="${key}"]`
          );

          if (node) {
            node.textContent = safeText(value);
          }
        });

        const subtitle = document.getElementById('viewModalSubtitle');

        if (subtitle) {
          subtitle.textContent =
            fullName || user.username || 'Employee account information';
        }

        openModal(viewModal);
      });
    });

    // EDIT ACCOUNT
    document.querySelectorAll('[data-edit-user]').forEach((button) => {
      button.addEventListener('click', () => {
        let user;

        try {
          user = JSON.parse(button.dataset.user || '{}');
        } catch (error) {
          console.error('Could not read account details:', error);
          return;
        }

        document.getElementById('edit_user_id').value = user.id || '';
        document.getElementById('edit_username').value = user.username || '';
        document.getElementById('edit_status').value = user.status || 'Active';
        document.getElementById('edit_role').value = user.role || 'employee';
        document.getElementById('edit_rank').value = user.rank || 'employee';

        const subtitle = document.getElementById('editModalSubtitle');

        if (subtitle) {
          subtitle.textContent = `Editing ${user.username || 'account'}`;
        }

        openModal(editModal);
      });
    });

    // ACTIVATE / DEACTIVATE ACCOUNT
    document.querySelectorAll('[data-status-user]').forEach((button) => {
      button.addEventListener('click', () => {
        const userId = button.dataset.userId;
        const username = button.dataset.username;
        const newStatus = button.dataset.status;

        const isDeactivating = newStatus === 'Inactive';

        document.getElementById('statusModalTitle').textContent =
          isDeactivating ? 'Deactivate Account?' : 'Activate Account?';

        document.getElementById('statusModalDescription').textContent =
          isDeactivating ?
          'You are about to deactivate this account.' :
          'You are about to reactivate this account.';

        document.getElementById('statusWarningTitle').textContent =
          isDeactivating ?
          'Access will be disabled' :
          'Access will be restored';

        document.getElementById('statusWarningMessage').textContent =
          isDeactivating ?
          'This user will no longer be able to access the system.' :
          'This user will be able to access the system again.';

        document.getElementById('statusModalUsername').textContent =
          username;

        document.getElementById('statusModalUserId').value = userId;
        document.getElementById('statusModalValue').value = newStatus;

        const confirmButton =
          document.getElementById('statusConfirmButton');

        confirmButton.textContent = isDeactivating ?
          'Deactivate Account' :
          'Activate Account';

        confirmButton.classList.toggle(
          'danger-button',
          isDeactivating
        );

        openModal(statusModal);
      });
    });

    // CLOSE BUTTONS
    document.querySelectorAll('[data-close-modal]').forEach((button) => {
      button.addEventListener('click', () => {
        closeModal(button.closest('.user-modal'));
      });
    });

    // CLOSE WHEN CLICKING OUTSIDE MODAL
    document.querySelectorAll('.user-modal').forEach((modal) => {
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeModal(modal);
        }
      });
    });

    // CLOSE WITH ESCAPE KEY
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        document.querySelectorAll('.user-modal.is-open').forEach(
          closeModal
        );
      }
    });

  })();
</script>