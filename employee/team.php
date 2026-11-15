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
        'Only Team Leaders and Managers can access this page.'
    );
}

require_once __DIR__ . '/../config/helpers.php';

$departmentId = $account['department_id'] ?? null;
$selfEmployeeId = $account['employee_id'] ?? null;

$members = [];

if ($departmentId !== null && $selfEmployeeId !== null) {
    $statement = $pdo->prepare(
        'SELECT
            e.id,
            e.employee_id,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.email,
            e.phone,
            e.employment_status,
            e.rank,
            p.title AS position_title,
            d.name AS department_name
         FROM employees e
         LEFT JOIN positions p
            ON p.id = e.position_id
         LEFT JOIN departments d
            ON d.id = e.department_id
         WHERE e.department_id = :department_id
           AND e.id <> :self_employee_id
         ORDER BY e.last_name ASC, e.first_name ASC'
    );

    $statement->execute([
        'department_id' => $departmentId,
        'self_employee_id' => $selfEmployeeId,
    ]);

    $members = $statement->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = $rank === 'manager'
    ? 'Department Overview'
    : 'My Team';

$eyebrow = $rank === 'manager'
    ? 'DEPARTMENT PORTAL'
    : 'TEAM LEADER PORTAL';

$subtitle = $rank === 'manager'
    ? 'View employees in your department.'
    : 'View employees assigned to your department.';

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
            Salary, payslip, bank, medical, and sensitive HR information
            are not displayed here.
        </p>
    </div>

    <div class="ph-actions">
        <a
            class="button"
            href="/employee/team_leave.php">
            Team Leave
        </a>

        <a
            class="button"
            href="/employee/promotion_requests.php">
            Promotions
        </a>
    </div>
</section>

<div class="metric-strip">
    <article class="metric-card metric-teal">
        <span class="metric-title">
            <?= $rank === 'manager'
                ? 'Department Members'
                : 'Team Members' ?>
        </span>

        <strong class="metric-number teal">
            <?= number_format(count($members)) ?>
        </strong>

        <span class="metric-description">
            Active department employees
        </span>
    </article>
</div>

<div class="panel table-card">
    <div class="panel-head">
        <h2>
            <?= $rank === 'manager'
                ? 'Department Employees'
                : 'Team Employees' ?>
        </h2>

        <span class="muted">
            <?= number_format(count($members)) ?>
            record<?= count($members) === 1 ? '' : 's' ?>
        </span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Employee ID</th>
                <th>Name</th>
                <th>Position</th>
                <th>Department</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Status</th>
            </tr>
        </thead>

        <tbody>
            <?php if (empty($members)): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            No employees were found in your department.
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($members as $member): ?>
                    <?php
                    $fullName = trim(
                        ($member['first_name'] ?? '') . ' ' .
                        ($member['middle_name'] ?? '') . ' ' .
                        ($member['last_name'] ?? '')
                    );

                    $isActive = strcasecmp(
                        (string)($member['employment_status'] ?? ''),
                        'Active'
                    ) === 0;
                    ?>

                    <tr>
                        <td>
                            <?= h($member['employee_id'] ?? '—') ?>
                        </td>

                        <td>
                            <strong>
                                <?= h($fullName) ?>
                            </strong>
                        </td>

                        <td>
                            <?= h($member['position_title'] ?? '—') ?>
                        </td>

                        <td>
                            <?= h($member['department_name'] ?? '—') ?>
                        </td>

                        <td>
                            <?= h($member['email'] ?? '—') ?>
                        </td>

                        <td>
                            <?= h($member['phone'] ?? '—') ?>
                        </td>

                        <td>
                            <span class="pill <?= $isActive
                                ? 'pill-active'
                                : 'pill-inactive' ?>">
                                <?= h($member['employment_status'] ?? '—') ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../templates/employeeTemplates/footer.php'; ?>
