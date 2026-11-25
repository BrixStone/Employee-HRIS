<?php
// admin/permissions.php
// System role and employee rank permission management.

require_once __DIR__ . '/../authentication/guard.php';

$account = require_access('system_settings', 'view', 'all', ['admin']);

require_once __DIR__ . '/../config/helpers.php';

$actions = [
    'view'    => 'View',
    'create'  => 'Create',
    'edit'    => 'Edit',
    'delete'  => 'Delete',
    'approve' => 'Approve',
    'submit'  => 'Submit',
];

// These scopes are fixed by employee rank.
$rankScopes = [
    'manager'     => 'department',
    'team_leader' => 'team',
    'employee'    => 'own',
];

$rankScopeLabels = [
    'manager'     => 'Assigned department',
    'team_leader' => 'Assigned team',
    'employee'    => 'Own records',
];

$editableRoles = ['hr', 'employee'];

$message = '';
$error = '';

if (isset($_SESSION['permission_success'])) {
    $message = (string) $_SESSION['permission_success'];
    unset($_SESSION['permission_success']);
}

if (isset($_SESSION['permission_error'])) {
    $error = (string) $_SESSION['permission_error'];
    unset($_SESSION['permission_error']);
}

/*
|--------------------------------------------------------------------------
| Load configuration data
|--------------------------------------------------------------------------
*/

try {
    $roles = $pdo->query("
        SELECT id, role_key, role_name, is_protected
        FROM roles
        WHERE role_key IN ('admin', 'hr', 'employee')
        ORDER BY FIELD(role_key, 'admin', 'hr', 'employee')
    ")->fetchAll(PDO::FETCH_ASSOC);

    $modules = $pdo->query("
        SELECT id, module_key, module_name, is_active
        FROM modules
        ORDER BY sort_order, module_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $permissions = $pdo->query("
        SELECT id, permission_key
        FROM permissions
    ")->fetchAll(PDO::FETCH_ASSOC);

    $roleIds = [];
    foreach ($roles as $role) {
        $roleIds[$role['role_key']] = (int) $role['id'];
    }

    $moduleIds = [];
    foreach ($modules as $module) {
        $moduleIds[$module['module_key']] = (int) $module['id'];
    }

    $permissionIds = [];
    foreach ($permissions as $permission) {
        $permissionIds[$permission['permission_key']] = (int) $permission['id'];
    }

    // System role permissions.
    $roleExisting = [];

    $roleRows = $pdo->query("
        SELECT
            r.role_key,
            m.module_key,
            p.permission_key,
            rp.data_scope
        FROM role_permissions rp
        INNER JOIN roles r ON r.id = rp.role_id
        INNER JOIN modules m ON m.id = rp.module_id
        INNER JOIN permissions p ON p.id = rp.permission_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($roleRows as $row) {
        $roleExisting[$row['role_key']][$row['module_key']][$row['permission_key']]
            = $row['data_scope'];
    }

    // Employee rank permissions.
    $rankExisting = [];

    $rankRows = $pdo->query("
        SELECT
            rank_key,
            module_id,
            permission_id,
            effect,
            data_scope
        FROM rank_permissions
    ")->fetchAll(PDO::FETCH_ASSOC);

    $moduleKeysById = array_flip($moduleIds);
    $permissionKeysById = array_flip($permissionIds);

    foreach ($rankRows as $row) {
        $moduleKey = $moduleKeysById[(int) $row['module_id']] ?? null;
        $actionKey = $permissionKeysById[(int) $row['permission_id']] ?? null;

        if ($moduleKey === null || $actionKey === null) {
            continue;
        }

        $rankExisting[$row['rank_key']][$moduleKey][$actionKey] = [
            'effect' => $row['effect'],
            'scope'  => $row['data_scope'],
        ];
    }

} catch (Throwable $e) {
    error_log('Permission page loading failed: ' . $e->getMessage());

    $roles = [];
    $modules = [];
    $roleIds = [];
    $moduleIds = [];
    $permissionIds = [];
    $roleExisting = [];
    $rankExisting = [];

    $error = 'Unable to load permission settings. Please check the database.';
}

/*
|--------------------------------------------------------------------------
| Save configuration
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($account['role']);

    try {
        $configType = (string) ($_POST['config_type'] ?? '');
        $configKey = (string) ($_POST['config_key'] ?? '');

        $enabledModules = $_POST['module_enabled'] ?? [];
        $submittedActions = $_POST['actions'] ?? [];

        if (!is_array($enabledModules) || !is_array($submittedActions)) {
            throw new RuntimeException('Invalid permission data.');
        }

        if (!in_array($configType, ['role', 'rank'], true)) {
            throw new RuntimeException('Invalid configuration type.');
        }

        // Determine the fixed data scope for this configuration.
        if ($configType === 'role') {
            if (!in_array($configKey, $editableRoles, true)) {
                throw new RuntimeException('This system role cannot be edited.');
            }

            if (!isset($roleIds[$configKey])) {
                throw new RuntimeException('Invalid system role.');
            }

            $configId = $roleIds[$configKey];
            $fixedScope = 'all';

        } else {
            if (!array_key_exists($configKey, $rankScopes)) {
                throw new RuntimeException('Invalid employee rank.');
            }

            $fixedScope = $rankScopes[$configKey];
        }

        // Only active modules can be configured.
        $activeModuleKeys = [];

        foreach ($modules as $module) {
            if ((bool) $module['is_active']) {
                $activeModuleKeys[] = $module['module_key'];
            }
        }

        $selectedModuleKeys = [];

        foreach ($enabledModules as $moduleKey => $value) {
            if (
                !is_string($moduleKey) ||
                !in_array($moduleKey, $activeModuleKeys, true) ||
                (string) $value !== '1'
            ) {
                throw new RuntimeException('Invalid module selection.');
            }

            $selectedModuleKeys[] = $moduleKey;
        }

        // Validate all submitted action permissions.
        $validatedActions = [];

        foreach ($selectedModuleKeys as $moduleKey) {
            if (!isset($moduleIds[$moduleKey])) {
                throw new RuntimeException('Invalid module.');
            }

            $moduleActions = $submittedActions[$moduleKey] ?? [];

            if (!is_array($moduleActions)) {
                throw new RuntimeException('Invalid action permissions.');
            }

            foreach ($actions as $actionKey => $actionLabel) {
                if (!isset($permissionIds[$actionKey])) {
                    throw new RuntimeException("Missing permission: {$actionKey}");
                }

                $effect = $moduleActions[$actionKey] ?? 'deny';

                if (!in_array($effect, ['allow', 'deny'], true)) {
                    throw new RuntimeException("Invalid {$actionLabel} permission.");
                }

                $validatedActions[$moduleKey][$actionKey] = $effect;
            }
        }

        $pdo->beginTransaction();

        if ($configType === 'role') {
            // Replace this role's complete configuration.
            $delete = $pdo->prepare("
                DELETE FROM role_permissions
                WHERE role_id = :role_id
            ");

            $delete->execute(['role_id' => $configId]);

            $insert = $pdo->prepare("
                INSERT INTO role_permissions
                    (role_id, module_id, permission_id, data_scope)
                VALUES
                    (:role_id, :module_id, :permission_id, :data_scope)
            ");

            foreach ($selectedModuleKeys as $moduleKey) {
                foreach ($actions as $actionKey => $actionLabel) {
                    $effect = $validatedActions[$moduleKey][$actionKey];

                    $insert->execute([
                        'role_id'       => $configId,
                        'module_id'     => $moduleIds[$moduleKey],
                        'permission_id' => $permissionIds[$actionKey],
                        'data_scope'    => $effect === 'allow' ? 'all' : 'none',
                    ]);
                }
            }

        } else {
            // Replace this rank's complete configuration.
            $delete = $pdo->prepare("
                DELETE FROM rank_permissions
                WHERE rank_key = :rank_key
            ");

            $delete->execute(['rank_key' => $configKey]);

            $insert = $pdo->prepare("
                INSERT INTO rank_permissions
                    (rank_key, module_id, permission_id, effect, data_scope)
                VALUES
                    (:rank_key, :module_id, :permission_id, :effect, :data_scope)
            ");

            foreach ($selectedModuleKeys as $moduleKey) {
                foreach ($actions as $actionKey => $actionLabel) {
                    $effect = $validatedActions[$moduleKey][$actionKey];

                    $insert->execute([
                        'rank_key'      => $configKey,
                        'module_id'     => $moduleIds[$moduleKey],
                        'permission_id' => $permissionIds[$actionKey],
                        'effect'        => $effect,
                        'data_scope'    => $effect === 'allow' ? $fixedScope : 'none',
                    ]);
                }
            }
        }

        $pdo->commit();

        Rbac::reset();

        $_SESSION['permission_success'] = 'Permissions successfully updated.';

        header('Location: /admin/permissions.php');
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Permission update failed: ' . $e->getMessage());

        $error = 'Unable to save permissions. No changes were applied.';
    }
}

/*
|--------------------------------------------------------------------------
| Build configuration cards and modals
|--------------------------------------------------------------------------
*/

$configurations = [];

foreach ($roles as $role) {
    if (!in_array($role['role_key'], $editableRoles, true)) {
        continue;
    }

    $configurations[] = [
        'type'        => 'role',
        'key'         => $role['role_key'],
        'title'       => $role['role_name'],
        'description' => $role['role_key'] === 'hr'
            ? 'Manage HR module access and actions.'
            : 'Manage employee system-role module access and actions.',
        'scope_label' => 'General access',
    ];
}

foreach ($rankScopes as $rankKey => $scope) {
    $rankTitles = [
        'manager'     => 'Manager',
        'team_leader' => 'Team Leader',
        'employee'    => 'Employee',
    ];

    $rankDescriptions = [
        'manager'     => 'Manages the entire assigned department.',
        'team_leader' => 'Manages the assigned team.',
        'employee'    => 'Performs assigned work and accesses own records.',
    ];

    $configurations[] = [
        'type'        => 'rank',
        'key'         => $rankKey,
        'title'       => $rankTitles[$rankKey],
        'description' => $rankDescriptions[$rankKey],
        'scope_label' => $rankScopeLabels[$rankKey],
    ];
}

$activeModules = count(array_filter(
    $modules,
    static fn($module) => (bool) $module['is_active']
));

$enabledPermissionCount = 0;

foreach ($roleExisting as $roleData) {
    foreach ($roleData as $moduleData) {
        foreach ($moduleData as $scope) {
            if ($scope !== 'none') {
                $enabledPermissionCount++;
            }
        }
    }
}

foreach ($rankExisting as $rankData) {
    foreach ($rankData as $moduleData) {
        foreach ($moduleData as $permissionData) {
            if (($permissionData['effect'] ?? 'deny') === 'allow') {
                $enabledPermissionCount++;
            }
        }
    }
}

$cssPageOverride = 'users';

require __DIR__ . '/../templates/adminTemplates/header.php';
?>

<style>
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
.user-management * {
    box-sizing: border-box;
}

.um-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    flex-wrap: wrap;
}

.um-heading h1 {
    margin: 5px 0 6px;
    font-size: clamp(25px, 3vw, 32px);
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

.um-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}

.um-metric,
.um-role-card,
.um-info-panel {
    border: 1px solid var(--border, rgba(255,255,255,.08));
    border-radius: 13px;
    background: rgba(20, 27, 55, .72);
}

.um-metric {
    padding: 15px 17px;
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
}

.um-alert {
    padding: 11px 14px;
    border-radius: 10px;
    font-size: 12px;
}

.um-alert.success {
    background: rgba(52,211,153,.1);
    color: #a7f3d0;
    border: 1px solid rgba(52,211,153,.25);
}

.um-alert.error {
    background: rgba(248,113,113,.1);
    color: #fecaca;
    border: 1px solid rgba(248,113,113,.25);
}

.um-section-heading {
    margin: 8px 0 0;
    font-size: 15px;
}

.um-section-description {
    margin: -9px 0 0;
    color: var(--muted, #aaa0b2);
    font-size: 12px;
}

.um-role-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.um-role-card {
    min-width: 0;
    padding: 19px;
    display: grid;
    gap: 14px;
}

.um-role-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.um-role-card h2 {
    margin: 0 0 5px;
    font-size: 16px;
}

.um-role-card p {
    margin: 0;
    color: var(--muted, #aaa0b2);
    font-size: 12px;
    line-height: 1.5;
}

.um-role-count {
    color: #a5b4fc;
    background: rgba(99,102,241,.14);
    border-radius: 999px;
    padding: 5px 9px;
    font-size: 10px;
    font-weight: 750;
    white-space: nowrap;
}

.um-role-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--border, rgba(255,255,255,.08));
}

.um-role-bottom span {
    color: var(--muted, #aaa0b2);
    font-size: 11px;
}

.um-role-bottom button {
    min-height: 35px;
    padding: 7px 12px;
}

.um-info-panel {
    padding: 17px 19px;
}

.um-info-panel h2 {
    margin: 0 0 13px;
    font-size: 14px;
}

.um-info-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.um-info-item strong {
    display: block;
    margin-bottom: 4px;
    font-size: 12px;
}

.um-info-item span {
    color: var(--muted, #aaa0b2);
    font-size: 11px;
    line-height: 1.5;
}

/* Modal */

.permission-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(8,7,12,.76);
    backdrop-filter: blur(5px);
}

.permission-modal.is-open {
    display: flex;
}

.pm-modal {
    width: min(760px, 100%);
    max-height: 90vh;
    overflow: auto;
    border: 1px solid var(--border, rgba(255,255,255,.12));
    border-radius: 15px;
    background: #171d36;
    color: #f4eff8;
    box-shadow: 0 24px 80px rgba(0,0,0,.48);
}

.pm-modal-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    padding: 19px 21px;
    border-bottom: 1px solid rgba(255,255,255,.08);
}

.pm-modal-head h2 {
    margin: 0 0 5px;
    font-size: 19px;
}

.pm-modal-head p {
    margin: 0;
    color: var(--muted, #aaa0b2);
    font-size: 12px;
}

.pm-close {
    border: 0;
    background: transparent;
    color: #c9bfd2;
    font-size: 25px;
    cursor: pointer;
}

.pm-modal-body {
    padding: 20px;
}

.pm-section-title {
    margin-bottom: 5px;
    font-size: 13px;
    font-weight: 750;
}

.pm-section-description {
    margin: 0 0 14px;
    color: var(--muted, #aaa0b2);
    font-size: 11px;
}

.pm-module-list {
    display: grid;
    gap: 9px;
}

.pm-module-card {
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 10px;
    overflow: hidden;
}

.pm-module-row {
    min-height: 52px;
    padding: 10px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.pm-module-check {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
    color: #f4eff8;
    font-size: 12px;
    font-weight: 650;
}

.pm-module-check input {
    width: 16px;
    height: 16px;
    accent-color: #818cf8;
    cursor: pointer;
}

.pm-module-actions {
    display: flex;
    align-items: center;
    gap: 9px;
}

.pm-module-status {
    color: var(--muted, #aaa0b2);
    font-size: 10px;
}

.pm-permission-toggle {
    min-height: 30px;
    padding: 5px 9px;
    border: 1px solid rgba(141,168,255,.24);
    border-radius: 7px;
    background: rgba(126,151,255,.07);
    color: #b7c5ff;
    font-size: 10px;
    font-weight: 750;
    cursor: pointer;
}

.pm-permission-toggle:disabled {
    opacity: .35;
    cursor: not-allowed;
}

.pm-module-editor {
    display: none;
    padding: 14px;
    border-top: 1px solid rgba(255,255,255,.08);
    background: rgba(8,13,32,.45);
}

.pm-module-editor.is-visible {
    display: block;
}

.pm-editor-heading {
    margin: 0 0 12px;
    font-size: 11px;
    font-weight: 750;
}

.pm-fixed-scope {
    margin: 0 0 13px;
    padding: 9px 11px;
    border: 1px solid rgba(141,168,255,.18);
    border-radius: 8px;
    background: rgba(99,102,241,.08);
    color: #c7d2fe;
    font-size: 11px;
}

.pm-permission-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.pm-action-option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-height: 40px;
    padding: 8px 10px;
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 8px;
    background: rgba(255,255,255,.025);
    font-size: 11px;
}

.pm-action-option label {
    cursor: pointer;
}

.pm-action-option input {
    width: 16px;
    height: 16px;
    accent-color: #34d399;
    cursor: pointer;
}

.pm-modal-foot {
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    padding: 14px 20px;
    border-top: 1px solid rgba(255,255,255,.08);
}

.pm-modal-foot button {
    min-height: 38px;
    padding: 8px 15px;
}

@media(max-width:850px) {
    .um-info-grid {
        grid-template-columns: repeat(2, minmax(0,1fr));
    }
}

@media(max-width:600px) {
    .user-management {
        padding-inline: 13px;
    }

    .um-role-grid {
        grid-template-columns: 1fr;
    }

    .um-metrics {
        grid-template-columns: repeat(2, minmax(0,1fr));
        gap: 8px;
    }

    .um-metric {
        padding: 12px;
    }

    .um-info-grid,
    .pm-permission-grid {
        grid-template-columns: 1fr;
    }

    .pm-modal {
        max-height: 95vh;
    }

    .pm-modal-foot {
        flex-direction: column-reverse;
    }

    .pm-modal-foot button {
        width: 100%;
    }

    .pm-module-row {
        align-items: flex-start;
    }

    .pm-module-status {
        display: none;
    }
}
</style>

<div class="user-management">

    <section class="um-heading">
        <div>
            <p class="um-eyebrow">ACCESS CONTROL</p>
            <h1>System Permissions</h1>
            <p>Manage module access and actions for system roles and employee ranks.</p>
        </div>

        <div>
            <a class="button" href="/admin/users.php">User Management</a>
        </div>
    </section>

    <?php if ($message !== ''): ?>
        <div class="um-alert success"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="um-alert error"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="um-metrics">
        <article class="um-metric">
            <span>Active Modules</span>
            <strong><?= number_format($activeModules) ?></strong>
        </article>

        <article class="um-metric">
            <span>Configurations</span>
            <strong><?= number_format(count($configurations)) ?></strong>
        </article>

        <article class="um-metric">
            <span>Allowed Actions</span>
            <strong><?= number_format($enabledPermissionCount) ?></strong>
        </article>
    </section>

    <h2 class="um-section-heading">System Role Permissions</h2>
    <p class="um-section-description">
        Configure which modules HR and Employee can access and what actions they can perform.
    </p>

    <section class="um-role-grid">

        <?php foreach ($configurations as $config): ?>
            <?php if ($config['type'] !== 'role') continue; ?>

            <?php
            $enabledCount = 0;

            foreach ($modules as $module) {
                if (
                    (bool) $module['is_active'] &&
                    isset($roleExisting[$config['key']][$module['module_key']])
                ) {
                    $enabledCount++;
                }
            }
            ?>

            <article class="um-role-card">
                <div class="um-role-top">
                    <div>
                        <h2><?= h($config['title']) ?></h2>
                        <p><?= h($config['description']) ?></p>
                    </div>

                    <span class="um-role-count">
                        <?= number_format($enabledCount) ?> modules
                    </span>
                </div>

                <div class="um-role-bottom">
                    <span>General access</span>

                    <button
                        type="button"
                        class="primary-button pm-open"
                        data-modal="permissionModal_<?= h($config['type'] . '_' . $config['key']) ?>">
                        Configure
                    </button>
                </div>
            </article>
        <?php endforeach; ?>

    </section>

    <h2 class="um-section-heading">Employee Rank Permissions</h2>
    <p class="um-section-description">
        Configure what each rank can do. Its data scope is automatically determined by its assigned responsibility.
    </p>

    <section class="um-role-grid">

        <?php foreach ($configurations as $config): ?>
            <?php if ($config['type'] !== 'rank') continue; ?>

            <?php
            $enabledCount = 0;

            foreach ($modules as $module) {
                if (
                    (bool) $module['is_active'] &&
                    isset($rankExisting[$config['key']][$module['module_key']])
                ) {
                    $enabledCount++;
                }
            }
            ?>

            <article class="um-role-card">
                <div class="um-role-top">
                    <div>
                        <h2><?= h($config['title']) ?></h2>
                        <p><?= h($config['description']) ?></p>
                    </div>

                    <span class="um-role-count">
                        <?= number_format($enabledCount) ?> modules
                    </span>
                </div>

                <div class="um-role-bottom">
                    <span><?= h($config['scope_label']) ?></span>

                    <button
                        type="button"
                        class="primary-button pm-open"
                        data-modal="permissionModal_<?= h($config['type'] . '_' . $config['key']) ?>">
                        Configure
                    </button>
                </div>
            </article>
        <?php endforeach; ?>

    </section>

    <section class="um-info-panel">
        <h2>How permission settings work</h2>

        <div class="um-info-grid">
            <div class="um-info-item">
                <strong>System roles</strong>
                <span>HR and Employee receive general module and action permissions.</span>
            </div>

            <div class="um-info-item">
                <strong>Manager</strong>
                <span>Allowed actions apply to the Manager's assigned department.</span>
            </div>

            <div class="um-info-item">
                <strong>Team Leader</strong>
                <span>Allowed actions apply to the Team Leader's assigned team.</span>
            </div>

            <div class="um-info-item">
                <strong>Employee rank</strong>
                <span>Allowed actions apply to the employee's own records.</span>
            </div>

            <div class="um-info-item">
                <strong>Allow / Deny</strong>
                <span>Allow grants the selected action. Deny prevents that action.</span>
            </div>

            <div class="um-info-item">
                <strong>Admin</strong>
                <span>Administrator access remains protected and is not configured here.</span>
            </div>
        </div>
    </section>

</div>

<!-- CONFIGURATION MODALS -->

<?php foreach ($configurations as $config): ?>

    <?php
    $configId = 'permissionModal_' . $config['type'] . '_' . $config['key'];

    $currentData = $config['type'] === 'role'
        ? ($roleExisting[$config['key']] ?? [])
        : ($rankExisting[$config['key']] ?? []);
    ?>

    <div
        class="permission-modal"
        id="<?= h($configId) ?>"
        aria-hidden="true">

        <div
            class="pm-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="<?= h($configId) ?>_title">

            <div class="pm-modal-head">
                <div>
                    <h2 id="<?= h($configId) ?>_title">
                        Configure <?= h($config['title']) ?>
                    </h2>

                    <p>
                        <?= $config['type'] === 'rank'
                            ? 'Configure allowed actions within the assigned data scope.'
                            : 'Choose modules and configure allowed actions.' ?>
                    </p>
                </div>

                <button
                    type="button"
                    class="pm-close"
                    data-close-modal="<?= h($configId) ?>"
                    aria-label="Close modal">
                    &times;
                </button>
            </div>

            <form method="POST" class="pm-role-form">

                <?= csrf_field() ?>

                <input type="hidden" name="config_type" value="<?= h($config['type']) ?>">
                <input type="hidden" name="config_key" value="<?= h($config['key']) ?>">

                <div class="pm-modal-body">

                    <div class="pm-section-title">Module access</div>

                    <p class="pm-section-description">
                        Enable the modules this configuration can access.
                        Open Permissions to allow or deny individual actions.
                    </p>

                    <div class="pm-module-list">

                        <?php foreach ($modules as $module): ?>

                            <?php
                            $moduleKey = $module['module_key'];
                            $isActive = (bool) $module['is_active'];

                            $hasModuleAccess = isset($currentData[$moduleKey]);
                            ?>

                            <article class="pm-module-card">

                                <div class="pm-module-row">

                                    <label class="pm-module-check">
                                        <input
                                            type="checkbox"
                                            name="module_enabled[<?= h($moduleKey) ?>]"
                                            value="1"
                                            class="pm-module-checkbox"
                                            <?= $hasModuleAccess && $isActive ? 'checked' : '' ?>
                                            <?= !$isActive ? 'disabled' : '' ?>>

                                        <span><?= h($module['module_name']) ?></span>
                                    </label>

                                    <div class="pm-module-actions">

                                        <?php if (!$isActive): ?>
                                            <span class="pm-module-status">Inactive module</span>
                                        <?php else: ?>
                                            <span class="pm-module-status">
                                                <?= $hasModuleAccess ? 'Enabled' : 'Disabled' ?>
                                            </span>

                                            <button
                                                type="button"
                                                class="pm-permission-toggle"
                                                disabled>
                                                Permissions
                                            </button>
                                        <?php endif; ?>

                                    </div>

                                </div>

                                <?php if ($isActive): ?>

                                    <div class="pm-module-editor">

                                        <p class="pm-editor-heading">
                                            Allowed actions — <?= h($module['module_name']) ?>
                                        </p>

                                        <?php if ($config['type'] === 'rank'): ?>
                                            <p class="pm-fixed-scope">
                                                Data scope: <strong><?= h($config['scope_label']) ?></strong>
                                            </p>
                                        <?php else: ?>
                                            <p class="pm-fixed-scope">
                                                Data scope: <strong>General access</strong>
                                            </p>
                                        <?php endif; ?>

                                        <div class="pm-permission-grid">

                                            <?php foreach ($actions as $actionKey => $actionLabel): ?>

                                                <?php
                                                if ($config['type'] === 'role') {
                                                    $currentValue =
                                                        ($currentData[$moduleKey][$actionKey] ?? 'none') !== 'none'
                                                            ? 'allow'
                                                            : 'deny';
                                                } else {
                                                    $currentValue =
                                                        $currentData[$moduleKey][$actionKey]['effect'] ?? 'deny';
                                                }
                                                ?>

                                                <div class="pm-action-option">

                                                    <label for="<?= h($configId . '_' . $moduleKey . '_' . $actionKey) ?>">
                                                        <?= h($actionLabel) ?>
                                                    </label>

                                                    <input
                                                        type="checkbox"
                                                        id="<?= h($configId . '_' . $moduleKey . '_' . $actionKey) ?>"
                                                        name="actions[<?= h($moduleKey) ?>][<?= h($actionKey) ?>]"
                                                        value="allow"
                                                        <?= $currentValue === 'allow' ? 'checked' : '' ?>
                                                        aria-label="<?= h($actionLabel) ?> permission">

                                                </div>

                                            <?php endforeach; ?>

                                        </div>

                                        <p class="pm-section-description" style="margin:12px 0 0;">
                                            Checked means Allowed. Unchecked means Denied.
                                        </p>

                                    </div>

                                <?php endif; ?>

                            </article>

                        <?php endforeach; ?>

                    </div>
                </div>

                <div class="pm-modal-foot">

                    <button
                        type="button"
                        class="button"
                        data-close-modal="<?= h($configId) ?>">
                        Cancel
                    </button>

                    <button type="submit" class="primary-button">
                        Save Changes
                    </button>

                </div>

            </form>
        </div>
    </div>

<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    function openModal(modalId) {
        const modal = document.getElementById(modalId);

        if (!modal) return;

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);

        if (!modal) return;

        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    // Open modal.
    document.querySelectorAll('.pm-open').forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(button.dataset.modal);
        });
    });

    // Close buttons and Cancel buttons.
    document.querySelectorAll('[data-close-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(button.dataset.closeModal);
        });
    });

    // Close when clicking the backdrop.
    document.querySelectorAll('.permission-modal').forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal(modal.id);
            }
        });
    });

    // Close with Escape.
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            const openModal = document.querySelector('.permission-modal.is-open');

            if (openModal) {
                closeModal(openModal.id);
            }
        }
    });

    // Enable or disable each module's permission controls.
    document.querySelectorAll('.pm-module-card').forEach(function (card) {

        const checkbox = card.querySelector('.pm-module-checkbox');
        const toggle = card.querySelector('.pm-permission-toggle');
        const editor = card.querySelector('.pm-module-editor');
        const status = card.querySelector('.pm-module-status');

        if (!checkbox || !toggle || !editor || !status) return;

        function updateModuleState() {
            const enabled = checkbox.checked;

            toggle.disabled = !enabled;
            status.textContent = enabled ? 'Enabled' : 'Disabled';

            if (!enabled) {
                editor.classList.remove('is-visible');
                toggle.textContent = 'Permissions';
            }
        }

        checkbox.addEventListener('change', updateModuleState);

        toggle.addEventListener('click', function () {
            if (toggle.disabled) return;

            const visible = editor.classList.toggle('is-visible');

            toggle.textContent = visible
                ? 'Hide permissions'
                : 'Permissions';
        });

        updateModuleState();
    });

});
</script>

</main>
</body>
</html>
