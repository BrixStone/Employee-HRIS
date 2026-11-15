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
        'Only Team Leaders and Managers can access promotions.'
    );
}

require_once __DIR__ . '/../config/helpers.php';

$message = '';
$error = '';

$departmentId = $account['department_id'] ?? null;
$selfEmployeeId = $account['employee_id'] ?? null;

/*
|--------------------------------------------------------------------------
| Handle Team Leader recommendation
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $rank === 'team_leader' &&
    isset($_POST['submit_recommendation'])
) {
    csrf_check($account['role']);

    $employeeId = (int)($_POST['employee_id'] ?? 0);
    $recommendedRank = (string)($_POST['recommended_rank'] ?? '');
    $reason = trim((string)($_POST['reason'] ?? ''));
    $effectiveDate = trim((string)($_POST['effective_date'] ?? ''));

    if (
        !in_array(
            $recommendedRank,
            ['team_leader', 'manager'],
            true
        )
    ) {
        $error = 'Please select a valid recommended rank.';
    } elseif ($reason === '') {
        $error = 'A reason is required.';
    } elseif ($departmentId === null || $selfEmployeeId === null) {
        $error = 'Your employee department information is incomplete.';
    } else {
        $employeeCheck = $pdo->prepare(
            "SELECT
                id,
                `rank`
             FROM employees
             WHERE id = :employee_id
               AND department_id = :department_id
               AND id <> :self_employee_id
               AND employment_status = 'Active'
             LIMIT 1"
        );

        $employeeCheck->execute([
            'employee_id' => $employeeId,
            'department_id' => $departmentId,
            'self_employee_id' => $selfEmployeeId,
        ]);

        $employee = $employeeCheck->fetch(PDO::FETCH_ASSOC);

        if (!$employee) {
            $error = 'You can only recommend an active employee in your department.';
        } else {
            $duplicateCheck = $pdo->prepare(
                "SELECT id
                 FROM promotion_requests
                 WHERE employee_id = :employee_id
                   AND status IN (
                       'pending_manager_review',
                       'pending_hr_review'
                   )
                 LIMIT 1"
            );

            $duplicateCheck->execute([
                'employee_id' => $employeeId,
            ]);

            if ($duplicateCheck->fetch()) {
                $error = 'This employee already has a promotion request in progress.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $insert = $pdo->prepare(
                        'INSERT INTO promotion_requests (
                            employee_id,
                            recommended_by,
                            recommended_rank,
                            reason,
                            effective_date,
                            status
                        ) VALUES (
                            :employee_id,
                            :recommended_by,
                            :recommended_rank,
                            :reason,
                            :effective_date,
                            :status
                        )'
                    );

                    $insert->execute([
                        'employee_id' => $employeeId,
                        'recommended_by' => $account['user_id'],
                        'recommended_rank' => $recommendedRank,
                        'reason' => $reason,
                        'effective_date' => $effectiveDate !== ''
                            ? $effectiveDate
                            : null,
                        'status' => 'pending_manager_review',
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
                            'action' => 'promotion_recommended',
                            'entity_type' => 'promotion_request',
                            'entity_id' => (int)$pdo->lastInsertId(),
                            'details' => json_encode([
                                'employee_id' => $employeeId,
                                'recommended_rank' => $recommendedRank,
                            ]),
                        ]);
                    } catch (Throwable $exception) {
                        error_log(
                            'Promotion recommendation audit failed: ' .
                                $exception->getMessage()
                        );
                    }


                    $requestId = (int)$pdo->lastInsertId();

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
                        'action' => 'promotion_recommended',
                        'entity_type' => 'promotion_request',
                        'entity_id' => $requestId,
                        'details' => json_encode([
                            'employee_id' => $employeeId,
                            'recommended_rank' => $recommendedRank,
                            'reason' => $reason,
                        ]),
                    ]);

                    $pdo->commit();

                    $message = 'Promotion recommendation sent to the manager.';
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = $exception->getMessage();
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Handle Manager review
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $rank === 'manager' &&
    isset($_POST['manager_decision'])
) {
    csrf_check($account['role']);

    $requestId = (int)($_POST['request_id'] ?? 0);
    $decision = (string)($_POST['manager_decision'] ?? '');
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if (!in_array($decision, ['approve', 'reject'], true)) {
        $error = 'Invalid manager decision.';
    } elseif (
        $decision === 'reject' &&
        $remarks === ''
    ) {
        $error = 'A rejection reason is required.';
    } elseif ($departmentId === null) {
        $error = 'Your department information is incomplete.';
    } else {
        try {
            $pdo->beginTransaction();

            $check = $pdo->prepare(
                "SELECT
                    pr.id,
                    pr.employee_id,
                    e.department_id
                 FROM promotion_requests pr
                 INNER JOIN employees e
                     ON e.id = pr.employee_id
                 WHERE pr.id = :request_id
                   AND e.department_id = :department_id
                   AND pr.status = 'pending_manager_review'
                 LIMIT 1
                 FOR UPDATE"
            );

            $check->execute([
                'request_id' => $requestId,
                'department_id' => $departmentId,
            ]);

            $request = $check->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new RuntimeException(
                    'That promotion request is not waiting for your department.'
                );
            }

            $newStatus = $decision === 'approve'
                ? 'pending_hr_review'
                : 'rejected_by_manager';

            $update = $pdo->prepare(
                'UPDATE promotion_requests
                 SET status = :status,
                     manager_id = :manager_id,
                     manager_remarks = :remarks,
                     manager_reviewed_at = NOW()
                 WHERE id = :request_id'
            );

            $update->execute([
                'status' => $newStatus,
                'manager_id' => $account['user_id'],
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
                        ? 'promotion_forwarded_to_hr'
                        : 'promotion_rejected_by_manager',
                    'entity_type' => 'promotion_request',
                    'entity_id' => $requestId,
                    'details' => json_encode([
                        'remarks' => $remarks,
                    ]),
                ]);
            } catch (Throwable $exception) {
                error_log(
                    'Promotion manager audit failed: ' .
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
                    ? 'promotion_forwarded_to_hr'
                    : 'promotion_rejected_by_manager',
                'entity_type' => 'promotion_request',
                'entity_id' => $requestId,
                'details' => json_encode([
                    'remarks' => $remarks,
                    'status' => $newStatus,
                ]),
            ]);

            $pdo->commit();

            $message = $decision === 'approve'
                ? 'Promotion recommendation forwarded to HR.'
                : 'Promotion recommendation rejected.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $exception->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Team Leader employee options
|--------------------------------------------------------------------------
*/
$employees = [];

if (
    $rank === 'team_leader' &&
    $departmentId !== null &&
    $selfEmployeeId !== null
) {
    $employeeQuery = $pdo->prepare(
        "SELECT
            id,
            employee_id,
            first_name,
            last_name,
            `rank`
         FROM employees
         WHERE department_id = :department_id
           AND id <> :self_employee_id
           AND employment_status = 'Active'
         ORDER BY last_name ASC, first_name ASC"
    );

    $employeeQuery->execute([
        'department_id' => $departmentId,
        'self_employee_id' => $selfEmployeeId,
    ]);

    $employees = $employeeQuery->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Load requests for this department
|--------------------------------------------------------------------------
*/
$requests = [];

if ($departmentId !== null) {
    $requestQuery = $pdo->prepare(
        "SELECT
            pr.id,
            pr.employee_id,
            pr.recommended_rank,
            pr.reason,
            pr.effective_date,
            pr.status,
            pr.manager_remarks,
            pr.created_at,
            e.employee_id AS employee_number,
            e.first_name,
            e.last_name
         FROM promotion_requests pr
         INNER JOIN employees e
             ON e.id = pr.employee_id
         WHERE e.department_id = :department_id
           AND pr.status = 'pending_manager_review'
         ORDER BY pr.created_at DESC"
    );

    $requestQuery->execute([
        'department_id' => $departmentId,
    ]);

    $requests = $requestQuery->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = $rank === 'manager'
    ? 'Promotion Reviews'
    : 'Promotion Recommendations';

$subtitle = $rank === 'manager'
    ? 'Review recommendations submitted by Team Leaders.'
    : 'Recommend an employee for promotion.';
$pageCss = 'promotion_requests';
require __DIR__ . '/../templates/employeeTemplates/header.php';
?>

<section class="page-head">
    <div class="ph-text">
        <p class="eyebrow">PROMOTION WORKFLOW</p>

        <h1><?= h($pageTitle) ?></h1>

        <p><?= h($subtitle) ?></p>
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

<?php if ($rank === 'team_leader'): ?>
    <div
        class="panel"
        style="padding:20px;margin-bottom:20px">

        <div class="panel-head">
            <h2>Submit Recommendation</h2>
        </div>

        <form method="post">
            <?= csrf_field() ?>

            <input
                type="hidden"
                name="submit_recommendation"
                value="1">

            <div>
                <label for="employee_id">Employee</label>

                <select
                    id="employee_id"
                    name="employee_id"
                    required>

                    <option value="">
                        Select employee
                    </option>

                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int)$employee['id'] ?>">
                            <?= h(
                                $employee['employee_id'] . ' - ' .
                                    $employee['first_name'] . ' ' .
                                    $employee['last_name']
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="recommended_rank">
                    Recommended Rank
                </label>

                <select
                    id="recommended_rank"
                    name="recommended_rank"
                    required>

                    <option value="team_leader">
                        Team Leader
                    </option>

                    <option value="manager">
                        Department Manager
                    </option>
                </select>
            </div>

            <div>
                <label for="reason">Reason</label>

                <textarea
                    id="reason"
                    name="reason"
                    rows="4"
                    required></textarea>
            </div>

            <div>
                <label for="effective_date">
                    Suggested Effective Date
                </label>

                <input
                    type="date"
                    id="effective_date"
                    name="effective_date">
            </div>

            <button
                class="primary-button"
                type="submit">

                Send to Manager
            </button>
        </form>
    </div>
<?php endif; ?>

<div class="panel table-card promotion-panel">
    <div class="table-responsive promotion-table-wrapper">
        <div class="panel-head">
            <h2>
                <?= $rank === 'manager'
                    ? 'Requests for Review'
                    : 'Requests in Progress' ?>
            </h2>

            <span class="muted">
                <?= number_format(count($requests)) ?>
            </span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Recommended Rank</th>
                    <th>Reason</th>
                    <th>Status</th>

                    <?php if ($rank === 'manager'): ?>
                        <th>Action</th>
                    <?php endif; ?>
                </tr>
            </thead>

            <tbody>
                <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="<?= $rank === 'manager' ? 5 : 4 ?>">
                            <div class="empty-state">
                                No promotion requests found.
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

                            <td>
                                <?= h(
                                    ucwords(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $request['status']
                                        )
                                    )
                                ) ?>
                            </td>

                            <?php if ($rank === 'manager'): ?>
                                <td class="promotion-actions">
                                    <form method="post">
                                        <?= csrf_field() ?>

                                        <input
                                            type="hidden"
                                            name="request_id"
                                            value="<?= (int)$request['id'] ?>">

                                        <input
                                            type="text"
                                            name="remarks"
                                            placeholder="Remarks"
                                            class="promotion-remarks">

                                        <button
                                            class="primary-button"
                                            type="submit"
                                            name="manager_decision"
                                            value="approve">

                                            Forward to HR
                                        </button>

                                        <button
                                            class="button"
                                            type="submit"
                                            name="manager_decision"
                                            value="reject">

                                            Reject
                                        </button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../templates/employeeTemplates/footer.php'; ?>