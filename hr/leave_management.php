<?php
// hr/leave_management.php

require_once __DIR__ . '/../authentication/guard.php';
// Only signed-in HR/admin accounts may manage leave types, balances and approvals
require_access('leave_requests', 'edit', 'all');

require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php'; // h(), fullName()
require __DIR__ . '/../classes/LeaveType.php';
require __DIR__ . '/../classes/LeaveBalance.php';
require __DIR__ . '/../classes/LeaveRequest.php';
require_once __DIR__ . '/../classes/DSA/Stack.php';
require_once __DIR__ . '/../classes/DSA/Queue.php';


$lt = new LeaveType($pdo);
$lb = new LeaveBalance($pdo);
$lr = new LeaveRequest($pdo);

$hrUserId = $_SESSION['user_id'] ?? null; // recorded on approve/reject if present

// Which section is active
$tab = $_GET['tab'] ?? 'requests'; // 'types' | 'balances' | 'requests'
if (!in_array($tab, ['types', 'balances', 'requests'], true)) $tab = 'requests';

// Handle POST actions (single-file router)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $form = $_POST['form'] ?? '';

  try {
    if ($form === 'type_create') {
      // Create leave type
      $data = [
        'leave_name'   => trim($_POST['leave_name'] ?? ''),
        'description'  => trim($_POST['description'] ?? ''),
        'default_days' => (int)($_POST['default_days'] ?? 0),
        'status'       => $_POST['status'] ?? 'Active',
      ];
      if ($data['leave_name'] === '' || $data['default_days'] < 0) {
        throw new RuntimeException('Leave name is required and default days must be >= 0.');
      }
      $lt->create($data);
      header('Location: /hr/leave_management.php?tab=types');
      exit;
    }

    if ($form === 'balance_alloc') {
      // Allocate/adjust balance
      $employeeId   = (int)($_POST['employee_id'] ?? 0);  // internal employees.id
      $leaveTypeId  = (int)($_POST['leave_type_id'] ?? 0);
      $year         = (int)($_POST['year'] ?? date('Y'));
      $allocated    = (float)($_POST['allocated_days'] ?? 0);

      if ($employeeId <= 0 || $leaveTypeId <= 0 || $year <= 0 || $allocated < 0) {
        throw new RuntimeException('Please enter valid Employee, Leave Type, Year, and Allocated Days.');
      }
      $lb->upsertAllocated($employeeId, $leaveTypeId, $year, $allocated);
      // Return to balances tab, keep context if you were looking at this employee/year
      header('Location: /hr/leave_management.php?tab=balances&emp=' . $employeeId . '&year=' . $year);
      exit;
    }

    if ($form === 'request_action') {
      // Approve or Reject a request
      $id     = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
      $action = $_POST['action'] ?? '';
      if ($id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        throw new RuntimeException('Invalid request/action.');
      }

      // Reviewer for the transaction history; the name is looked up
      // from users/employees when the log is written.
      $actor = ['role' => $_SESSION['role'] ?? 'hr'];
      if ($action === 'approve') {
        $lr->approve($id, $hrUserId !== null ? (int)$hrUserId : null, $actor);
      } else {
        $lr->reject($id, $hrUserId !== null ? (int)$hrUserId : null, $actor);
      }
      header('Location: /hr/leave_management.php?tab=requests&status=Pending');
      exit;
    }

    // Unknown form -> ignore
    header('Location: /hr/leave_management.php?tab=' . $tab);
    exit;
  } catch (Throwable $e) {
    // Basic fallback: send error back as a query param (or implement flash)
    $msg = urlencode($e->getMessage());
    header('Location: /hr/leave_management.php?tab=' . $tab . '&err=' . $msg);
    exit;
  }
}

// Load data for rendering (GET)
$err = $_GET['err'] ?? '';
// Leave Types (always useful)
$typesAll    = $lt->getAll();
$typesActive = $lt->getActive();

// Balances: allow quick view for a chosen employee + year
$empFilter = isset($_GET['emp']) && ctype_digit($_GET['emp']) ? (int)$_GET['emp'] : null;
$yearFilter = isset($_GET['year']) && ctype_digit($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$balances = [];

// Search by employee number (EMP-001) or by name instead of a dropdown / raw id.
$empSearch  = trim((string)($_GET['q'] ?? ''));
$empMatches = [];
$empSelected = null;

if ($tab === 'balances') {
  try {
    if ($empSearch !== '') {
      $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $empSearch) . '%';
      $st = $pdo->prepare("
        SELECT id, employee_id, first_name, middle_name, last_name
        FROM employees
        WHERE employee_id LIKE :q1
           OR first_name LIKE :q2
           OR last_name LIKE :q3
           OR CONCAT(first_name, ' ', last_name) LIKE :q4
           OR CONCAT(last_name, ', ', first_name) LIKE :q5
        ORDER BY (employee_id = :exact) DESC, last_name, first_name
        LIMIT 25
      ");
      $st->execute([
        'q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like,
        'exact' => $empSearch,
      ]);
      $empMatches = $st->fetchAll(PDO::FETCH_ASSOC);

      // An exact EMP-### match, or a single result, opens straight away.
      if (count($empMatches) === 1 || ($empMatches && strcasecmp((string)$empMatches[0]['employee_id'], $empSearch) === 0)) {
        $empFilter = (int)$empMatches[0]['id'];
      }
    }

    if ($empFilter) {
      $st = $pdo->prepare("SELECT id, employee_id, first_name, middle_name, last_name FROM employees WHERE id = :id LIMIT 1");
      $st->execute(['id' => $empFilter]);
      $empSelected = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if ($empSelected === null) {
        $empFilter = null;
      }
    }
  } catch (Throwable $e) {
    error_log('Leave balance employee search failed: ' . $e->getMessage());
  }
}

if ($empFilter) {
  $balances = $lb->getForEmployee($empFilter, $yearFilter);
}

// Requests: filter by status
$status = $_GET['status'] ?? 'Pending';
$validStatuses = ['Pending', 'Approved', 'Rejected', 'Cancelled', 'All'];
if (!in_array($status, $validStatuses, true)) $status = 'Pending';
$requests = $lr->getForHR($status === 'All' ? null : $status);

$leaveHistoryStacks = []; // keyed by leave_request_id

try {
  $logStmt = $pdo->query("
        SELECT
            lrl.leave_request_id,
            lrl.old_status,
            lrl.new_status,
            lrl.changed_at,
            u.username AS changed_by
        FROM leave_request_logs lrl
        LEFT JOIN users u ON u.id = lrl.changed_by
        ORDER BY lrl.leave_request_id ASC,
                 lrl.changed_at       ASC
    ");
  $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($logs as $log) {
    $rid = $log['leave_request_id'];

    if (!isset($leaveHistoryStacks[$rid])) {
      $leaveHistoryStacks[$rid] = new Stack();
    }

    // Push each status change onto the stack
    $leaveHistoryStacks[$rid]->push([
      'old_status' => $log['old_status'],
      'new_status' => $log['new_status'],
      'changed_at' => $log['changed_at'],
      'changed_by' => $log['changed_by'],
    ]);
  }
} catch (Throwable $e) {
  error_log('Leave stack build failed: ' . $e->getMessage());
}

$pendingQueue = new Queue();

try {
  $queueStmt = $pdo->query("
        SELECT
            lr.id,
            lr.employee_id,
            lr.leave_type_id,
            lr.start_date,
            lr.end_date,
            lr.number_of_days,
            lr.status,
            lr.created_at,
            e.first_name,
            e.last_name
        FROM leave_requests lr
        JOIN employees e ON e.id = lr.employee_id
        WHERE lr.status = 'Pending'
        ORDER BY lr.created_at ASC
    ");

  $pendingRows = $queueStmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($pendingRows as $row) {
    $pendingQueue->enqueue($row); // oldest filed = front of queue
  }
} catch (Throwable $e) {
  error_log('Leave queue build failed: ' . $e->getMessage());
}

require __DIR__ . '/../templates/hrTemplates/header.php';
?>

<h1>Leave Management</h1>
<p>Real-time updates: <strong data-realtime-status>Offline</strong></p>

<?php if ($err): ?>
  <p style="color:#b00;"><?= h($err) ?></p>
<?php endif; ?>

<!-- Simple tabs -->
<p style="margin:8px 0;">
  <a href="/hr/leave_management.php?tab=types">Leave Types</a> |
  <a href="/hr/leave_management.php?tab=balances">Leave Balances</a> |
  <a href="/hr/leave_management.php?tab=requests">Leave Requests</a>
</p>

<?php if ($tab === 'types'): ?>

  <h2>Leave Types</h2>

  <form method="POST" style="margin-bottom:12px;">
    <input type="hidden" name="form" value="type_create">
    <div>
      <label>Leave Name *</label>
      <input type="text" name="leave_name" required>
    </div>
    <div>
      <label>Default Days</label>
      <input type="number" name="default_days" min="0" step="1" value="0">
    </div>
    <div>
      <label>Description</label>
      <textarea name="description"></textarea>
    </div>
    <div>
      <label>Status</label>
      <select name="status">
        <option value="Active">Active</option>
        <option value="Inactive">Inactive</option>
      </select>
    </div>
    <button type="submit">Add Leave Type</button>
  </form>

  <table border="1" cellpadding="6" cellspacing="0">
    <thead>
      <tr>
        <th>ID</th>
        <th>Leave Name</th>
        <th>Default Days</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($typesAll)): ?>
        <tr>
          <td colspan="4">No leave types.</td>
        </tr>
        <?php else: foreach ($typesAll as $t): ?>
          <tr>
            <td><?= h($t['id']) ?></td>
            <td><?= h($t['leave_name']) ?></td>
            <td><?= h($t['default_days']) ?></td>
            <td><?= h($t['status']) ?></td>
          </tr>
      <?php endforeach;
      endif; ?>
    </tbody>
  </table>

<?php elseif ($tab === 'balances'): ?>

  <h2>Leave Balances</h2>

  <!-- Search by employee number (EMP-001) or name -->
  <form method="get" style="margin:8px 0;">
    <input type="hidden" name="tab" value="balances">
    <label for="emp_search">Search employee:</label>
    <input type="search" id="emp_search" name="q" value="<?= h($empSearch) ?>"
      placeholder="EMP-001 or employee name" autocomplete="off" style="min-width:260px">
    <label>Year:</label>
    <input type="number" name="year" min="2000" max="2100" value="<?= (int)$yearFilter ?>">
    <button type="submit">Search</button>
  </form>

  <?php if ($empSearch !== '' && !$empMatches): ?>
    <p class="muted">No employee found for “<?= h($empSearch) ?>”.</p>
  <?php elseif (count($empMatches) > 1 && !$empSelected): ?>
    <p>Select an employee:</p>
    <ul>
      <?php foreach ($empMatches as $m): ?>
        <li>
          <a href="/hr/leave_management.php?tab=balances&emp=<?= (int)$m['id'] ?>&year=<?= (int)$yearFilter ?>">
            <?= h($m['employee_id']) ?> — <?= h(fullName($m)) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($empFilter && $empSelected): ?>
    <h3>Balances for <?= h($empSelected['employee_id']) ?> — <?= h(fullName($empSelected)) ?> (<?= (int)$yearFilter ?>)</h3>
    <table border="1" cellpadding="6" cellspacing="0" style="margin-bottom:12px;">
      <thead>
        <tr>
          <th>Leave Type</th>
          <th>Allocated</th>
          <th>Used</th>
          <th>Remaining</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($balances)): ?>
          <tr>
            <td colspan="4">No balance rows yet.</td>
          </tr>
          <?php else: foreach ($balances as $b): ?>
            <tr>
              <td><?= h($b['leave_name']) ?></td>
              <td><?= h($b['allocated_days']) ?></td>
              <td><?= h($b['used_days']) ?></td>
              <td><?= h($b['remaining_days']) ?></td>
            </tr>
        <?php endforeach;
        endif; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- Allocate / adjust -->
  <h3>Allocate / Adjust Balance</h3>
  <?php if (!$empSelected): ?>
    <p class="muted">Search and select an employee above to allocate leave.</p>
  <?php else: ?>
  <form method="POST">
    <input type="hidden" name="form" value="balance_alloc">
    <input type="hidden" name="employee_id" value="<?= (int)$empSelected['id'] ?>">
    <div>
      <label>Employee</label>
      <strong><?= h($empSelected['employee_id']) ?> — <?= h(fullName($empSelected)) ?></strong>
    </div>
    <div>
      <label>Leave Type *</label>
      <select name="leave_type_id" required>
        <option value="">Select</option>
        <?php foreach ($typesActive as $t): ?>
          <option value="<?= (int)$t['id'] ?>"><?= h($t['leave_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Year *</label>
      <input type="number" name="year" min="2000" max="2100" value="<?= (int)$yearFilter ?>">
    </div>
    <div>
      <label>Allocated Days *</label>
      <input type="number" name="allocated_days" min="0" step="0.5" value="0">
    </div>
    <button type="submit">Save Allocation</button>
  </form>
  <?php endif; ?>

<?php else: // $tab === 'requests' 
?>

  <h2>Leave Requests</h2>

  <form method="get" style="margin:8px 0;">
    <input type="hidden" name="tab" value="requests">
    <label>Status:</label>
    <select name="status">
      <?php foreach (['Pending', 'Approved', 'Rejected', 'Cancelled', 'All'] as $s): ?>
        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
  </form>
  <!-- ── Queue: Pending Requests Panel ───────────────── -->
  <?php if (!$pendingQueue->isEmpty()): ?>
    <div class="panel" style="margin-bottom:20px">
      <div class="panel-head">
        <h2>⏳ Pending Approval Queue</h2>
        <span class="muted">
          <?= $pendingQueue->size() ?> request<?= $pendingQueue->size() === 1 ? '' : 's' ?> waiting — oldest first (FIFO)
        </span>
      </div>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Filed</th>
            <th>Employee</th>
            <th>Dates</th>
            <th>Days</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $position = 1;
          // toArray() gives front→back order (oldest first)
          foreach ($pendingQueue->toArray() as $qItem):
          ?>
            <tr>
              <td>
                <?php if ($position === 1): ?>
                  <span class="pill pill-active">Next</span>
                <?php else: ?>
                  <span class="muted">#<?= $position ?></span>
                <?php endif; ?>
              </td>
              <td class="muted">
                <?= date('M j, Y g:i A', strtotime($qItem['created_at'])) ?>
              </td>
              <td>
                <strong>
                  <?= htmlspecialchars($qItem['first_name'] . ' ' . $qItem['last_name']) ?>
                </strong>
              </td>
              <td>
                <?= htmlspecialchars($qItem['start_date']) ?>
                →
                <?= htmlspecialchars($qItem['end_date']) ?>
              </td>
              <td><?= (float)$qItem['number_of_days'] ?> days</td>
            </tr>
            <?php $position++; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <table border="1" cellpadding="6" cellspacing="0">
    <thead>
      <tr>
        <th>Employee</th>
        <th>Type</th>
        <th>Dates</th>
        <th>Days</th>
        <th>Status</th>
        <th>History</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($requests)): ?>
        <tr>
          <td colspan="7">No requests.</td>
        </tr>
        <?php else: foreach ($requests as $r): ?>
          <?php
          // ── DSA: Stack — status history for this request ──
          $stack   = $leaveHistoryStacks[$r['id']] ?? new Stack();
          $latest  = $stack->peek();
          $history = $stack->toArrayReversed();
          $trail   = $stack->size();
          ?>
          <tr>
            <td><?= h($r['employee_no']) ?> — <?= h(fullName($r)) ?></td>
            <td><?= h($r['leave_name']) ?></td>
            <td><?= h($r['start_date']) ?> → <?= h($r['end_date']) ?></td>
            <td><?= h($r['number_of_days']) ?></td>
            <td><?= h($r['status']) ?></td>

            <!-- ── Stack: Status History column ── -->
            <td>
              <?php if ($trail > 0): ?>
                <details>
                  <summary><?= $trail ?> change<?= $trail === 1 ? '' : 's' ?></summary>
                  <ul style="margin:6px 0 0;padding-left:16px;font-size:12px">
                    <?php foreach ($history as $h_entry): ?>
                      <li>
                        <span class="pill pill-neutral"><?= htmlspecialchars($h_entry['old_status']) ?></span>
                        →
                        <span class="pill pill-active"><?= htmlspecialchars($h_entry['new_status']) ?></span>
                        <span class="muted">
                          by <?= htmlspecialchars($h_entry['changed_by'] ?? 'system') ?>
                          on <?= date('M j, Y g:i A', strtotime($h_entry['changed_at'])) ?>
                        </span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </details>
              <?php else: ?>
                <span class="muted">No history</span>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($r['status'] === 'Pending'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Approve this request?');">
                  <input type="hidden" name="form" value="request_action">
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit">Approve</button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Reject this request?');">
                  <input type="hidden" name="form" value="request_action">
                  <input type="hidden" name="action" value="reject">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit">Reject</button>
                </form>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
      <?php endforeach;
      endif; ?>
    </tbody>
  </table>

<?php endif; ?>

<!-- Real-time: HR hears every leave event. The requests list reloads itself;
     the other tabs show a Refresh button so forms being filled are kept. -->
<script src="../asset/js/realtime.js" data-refresh="<?= $tab === 'requests' ? 'auto' : 'prompt' ?>" defer></script>