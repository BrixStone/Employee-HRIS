<?php

require_once __DIR__ . '/../authentication/guard.php';

$account = require_access(
    null,
    'view',
    null,
    ['employee']
);

$rank = account_rank($account);

if (!in_array($rank, ['team_leader', 'manager'], true)) {
    guard_forbidden(
        $account['role'],
        'Only Team Leaders and Managers can access team leave.'
    );
}

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../classes/LeaveRequest.php';

$teamEmployeeIds = Rbac::teamEmployeeIds(
    $pdo,
    $account['employee_id']
);

$leaveModel = new LeaveRequest($pdo);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($account['role']);

    $requestId = (int)($_POST['request_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');

    $request = $leaveModel->findById($requestId);

    if (
        !$request ||
        !in_array(
            (int)$request['employee_id'],
            $teamEmployeeIds,
            true
        ) ||
        $request['status'] !== 'Pending'
    ) {
        $error = 'That leave request is no longer available for review.';
    } else {
        try {
            $actor = [
                'id' => $account['user_id'],
                'role' => $rank,
            ];

            if ($decision === 'approve') {
                $leaveModel->approve(
                    $requestId,
                    $account['user_id'],
                    $actor
                );

                $message = 'Leave request approved.';
            } elseif ($decision === 'reject') {
                $leaveModel->reject(
                    $requestId,
                    $account['user_id'],
                    $actor
                );

                $message = 'Leave request rejected.';
            } else {
                $error = 'Invalid leave decision.';
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$pendingRequests = $leaveModel->searchForEmployees(
    $teamEmployeeIds,
    'Pending'
);

$pageTitle = $rank === 'manager'
    ? 'Department Leave'
    : 'Team Leave';

$eyebrow = 'LEAVE REVIEW';

$subtitle = $rank === 'manager'
    ? 'Review leave requests from your department.'
    : 'Review leave requests from your team.';

require __DIR__ . '/../templates/employeeTemplates/header.php';
?>

<section class="page-head">
    <div class="ph-text">
        <p class="eyebrow">
            <?= h($eyebrow) ?>
        </p>

        <h1>
            <?= h($pageTitle) ?>
        </h1>

        <p>
            <?= h($subtitle) ?>
        </p>
    </div>
</section>

<?php if ($message !== ''): ?>
    <div
        class="alert"
        style="margin-bottom:20px;border-color:rgba(52,211,153,.4);
        background:rgba(52,211,153,.12);color:#c7f9e5">

        <?= h($message) ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div
        class="alert error"
        style="margin-bottom:20px">

        <?= h($error) ?>
    </div>
<?php endif; ?>

<div class="panel table-card">
    <div class="panel-head">
        <h2>Pending Leave Requests</h2>

        <span class="muted">
            <?= number_format(count($pendingRequests)) ?>
        </span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Leave Type</th>
                <th>Dates</th>
                <th>Days</th>
                <th>Reason</th>
                <th>Action</th>
            </tr>
        </thead>

        <tbody>
            <?php if (empty($pendingRequests)): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            No pending leave requests.
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($pendingRequests as $leave): ?>
                    <tr>
                        <td>
                            <?= h(
                                trim(
                                    ($leave['first_name'] ?? '') . ' ' .
                                    ($leave['last_name'] ?? '')
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= h($leave['leave_name'] ?? 'Leave') ?>
                        </td>

                        <td>
                            <?= h($leave['start_date']) ?>
                            -
                            <?= h($leave['end_date']) ?>
                        </td>

                        <td>
                            <?= h($leave['number_of_days']) ?>
                        </td>

                        <td>
                            <?= h($leave['reason'] ?? '—') ?>
                        </td>

                        <td>
                            <form
                                method="post"
                                style="display:flex;gap:8px;flex-wrap:wrap">

                                <?= csrf_field() ?>

                                <input
                                    type="hidden"
                                    name="request_id"
                                    value="<?= (int)$leave['id'] ?>">

                                <button
                                    class="primary-button"
                                    type="submit"
                                    name="decision"
                                    value="approve">

                                    Approve
                                </button>

                                <button
                                    class="button"
                                    type="submit"
                                    name="decision"
                                    value="reject">

                                    Reject
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../templates/employeeTemplates/footer.php'; ?>
