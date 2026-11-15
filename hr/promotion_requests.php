<?php

require_once __DIR__ . '/../authentication/guard.php';

$account = require_access(
    'employee_records',
    'view',
    'all',
    ['hr', 'admin']
);

require_once __DIR__ . '/../config/helpers.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($account['role']);

    $requestId = (int)($_POST['request_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if (!in_array($decision, ['approve', 'reject'], true)) {
        $error = 'Invalid HR decision.';
    } elseif (
        $decision === 'reject' &&
        $remarks === ''
    ) {
        $error = 'A rejection reason is required.';
    } else {
        try {
            $pdo->beginTransaction();

            $query = $pdo->prepare(
                "SELECT
                    pr.*,
                    e.`rank` AS old_rank
                 FROM promotion_requests pr
                 INNER JOIN employees e
                     ON e.id = pr.employee_id
                 WHERE pr.id = :request_id
                   AND pr.status = 'pending_hr_review'
                 LIMIT 1
                 FOR UPDATE"
            );

            $query->execute([
                'request_id' => $requestId,
            ]);

            $request = $query->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new RuntimeException(
                    'This request is no longer pending HR review.'
                );
            }

            if ($decision === 'approve') {
                $allowedRanks = [
                    'team_leader',
                    'manager',
                ];

                if (!in_array(
                    $request['recommended_rank'],
                    $allowedRanks,
                    true
                )) {
                    throw new RuntimeException(
                        'The recommended rank is invalid.'
                    );
                }

                $employeeUpdate = $pdo->prepare(
                    'UPDATE employees
                     SET `rank` = :new_rank,
                         updated_at = NOW()
                     WHERE id = :employee_id'
                );

                $employeeUpdate->execute([
                    'new_rank' => $request['recommended_rank'],
                    'employee_id' => $request['employee_id'],
                ]);

                $log = $pdo->prepare(
                    'INSERT INTO promotion_logs (
                        employee_id,
                        old_rank,
                        new_rank,
                        promoted_by,
                        promotion_request_id,
                        effective_date
                    ) VALUES (
                        :employee_id,
                        :old_rank,
                        :new_rank,
                        :promoted_by,
                        :request_id,
                        :effective_date
                    )'
                );

                $log->execute([
                    'employee_id' => $request['employee_id'],
                    'old_rank' => $request['old_rank'] ?: 'employee',
                    'new_rank' => $request['recommended_rank'],
                    'promoted_by' => $account['user_id'],
                    'request_id' => $requestId,
                    'effective_date' => $request['effective_date'] ?: null,
                ]);

                $newStatus = 'approved';
                $message = 'Promotion approved and applied.';
            } else {
                $newStatus = 'rejected_by_hr';
                $message = 'Promotion request rejected.';
            }

            $requestUpdate = $pdo->prepare(
                'UPDATE promotion_requests
                 SET status = :status,
                     hr_id = :hr_id,
                     hr_remarks = :remarks,
                     hr_reviewed_at = NOW()
                 WHERE id = :request_id'
            );

            $requestUpdate->execute([
                'status' => $newStatus,
                'hr_id' => $account['user_id'],
                'remarks' => $remarks !== ''
                    ? $remarks
                    : null,
                'request_id' => $requestId,
            ]);

            try {
                $audit = $pdo->prepare(
                    'INSERT INTO audit_logs (
            actor_user_id,
            action,
            entity_type,
            entity_id,
            details
        ) VALUES (
            :actor_user_id,
            :action,
            :entity_type,
            :entity_id,
            :details
        )'
                );

                $audit->execute([
                    'actor_user_id' => $account['user_id'],
                    'action' => $decision === 'approve'
                        ? 'promotion_approved_by_hr'
                        : 'promotion_rejected_by_hr',
                    'entity_type' => 'promotion_request',
                    'entity_id' => $requestId,
                    'details' => json_encode([
                        'remarks' => $remarks,
                        'new_rank' => $request['recommended_rank'],
                    ]),
                ]);
            } catch (Throwable $exception) {
                error_log(
                    'Promotion HR audit failed: ' .
                        $exception->getMessage()
                );
            }


            $audit = $pdo->prepare(
                'INSERT INTO audit_logs (
                    actor_user_id,
                    action,
                    entity_type,
                    entity_id,
                    details
                ) VALUES (
                    :actor_user_id,
                    :action,
                    :entity_type,
                    :entity_id,
                    :details
                )'
            );

            $audit->execute([
                'actor_user_id' => $account['user_id'],
                'action' => $decision === 'approve'
                    ? 'promotion_approved_by_hr'
                    : 'promotion_rejected_by_hr',
                'entity_type' => 'promotion_request',
                'entity_id' => $requestId,
                'details' => json_encode([
                    'remarks' => $remarks,
                    'new_rank' => $request['recommended_rank'],
                    'status' => $newStatus,
                ]),
            ]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $exception->getMessage();
        }
    }
}

$query = $pdo->query(
    "SELECT
        pr.id,
        pr.recommended_rank,
        pr.reason,
        pr.effective_date,
        pr.created_at,
        e.employee_id AS employee_number,
        e.first_name,
        e.last_name,
        e.`rank` AS current_rank,
        d.name AS department_name
     FROM promotion_requests pr
     INNER JOIN employees e
         ON e.id = pr.employee_id
     LEFT JOIN departments d
         ON d.id = e.department_id
     WHERE pr.status = 'pending_hr_review'
     ORDER BY pr.created_at ASC"
);

$requests = $query->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/../templates/hrTemplates/header.php';
?>

<section class="page-head">
    <div class="ph-text">
        <p class="eyebrow">HR WORKFLOW</p>

        <h1>Promotion Approvals</h1>

        <p>
            Final approval updates the employee rank and records the promotion.
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
        <h2>Waiting for HR</h2>

        <span class="muted">
            <?= number_format(count($requests)) ?>
        </span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Department</th>
                <th>Current Rank</th>
                <th>New Rank</th>
                <th>Reason</th>
                <th>Decision</th>
            </tr>
        </thead>

        <tbody>
            <?php if (empty($requests)): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            No promotion requests are waiting for HR.
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td>
                            <?= h(
                                $request['employee_number'] . ' - ' .
                                    $request['first_name'] . ' ' .
                                    $request['last_name']
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                $request['department_name'] ?? '—'
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $request['current_rank']
                                    )
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $request['recommended_rank']
                                    )
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= h($request['reason']) ?>
                        </td>

                        <td class="promotion-actions-cell">
                            <form
                                method="post"
                                class="promotion-action-form">

                                <?= csrf_field() ?>

                                <input
                                    type="hidden"
                                    name="request_id"
                                    value="<?= (int)$request['id'] ?>">

                                <input
                                    class="promotion-remarks"
                                    type="text"
                                    name="remarks"
                                    placeholder="Remarks">

                                <div class="promotion-action-buttons">
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
                                </div>
                            </form>
                        </td>

                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>