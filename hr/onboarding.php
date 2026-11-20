<?php
// hr/onboarding.php

require_once __DIR__ . '/../authentication/guard.php';
require_once __DIR__ . '/../config/database.php';

$realtimeHelper = __DIR__ . '/../includes/realtime_push.php';
if (is_file($realtimeHelper)) {
    require_once $realtimeHelper;
}

$hrActorName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'HR'));
if ($hrActorName === '') {
    $hrActorName = 'HR';
}

function hris_ensure_onboarding_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `employee_onboarding` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` INT UNSIGNED NULL,
            `employee_code` VARCHAR(40) NOT NULL,
            `full_name` VARCHAR(160) NOT NULL,
            `email` VARCHAR(160) NOT NULL,
            `department` VARCHAR(120) NOT NULL,
            `job_title` VARCHAR(140) NOT NULL,
            `status` VARCHAR(40) NOT NULL DEFAULT 'In Progress',
            `step_info` TINYINT(1) NOT NULL DEFAULT 1,
            `step_documents` TINYINT(1) NOT NULL DEFAULT 0,
            `step_orientation` TINYINT(1) NOT NULL DEFAULT 0,
            `step_policy` TINYINT(1) NOT NULL DEFAULT 0,
            `orientation_at` DATETIME NULL,
            `orientation_room` VARCHAR(120) NULL,
            `facilitator_employee_id` INT UNSIGNED NULL,
            `facilitator_name` VARCHAR(160) NULL,
            `buddy_name` VARCHAR(160) NULL,
            `orientation_attended_at` DATETIME NULL,
            `checked_in_by` VARCHAR(160) NULL,
            `policy_acknowledged_at` DATETIME NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_onb_emp_code` (`employee_code`),
            KEY `idx_onb_email` (`email`),
            KEY `idx_onb_facilitator_emp` (`facilitator_employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columnsToAdd = [
        'facilitator_employee_id' => "ALTER TABLE `employee_onboarding` ADD COLUMN `facilitator_employee_id` INT UNSIGNED NULL AFTER `orientation_room`",
        'orientation_attended_at' => "ALTER TABLE `employee_onboarding` ADD COLUMN `orientation_attended_at` DATETIME NULL AFTER `buddy_name`",
        'checked_in_by'           => "ALTER TABLE `employee_onboarding` ADD COLUMN `checked_in_by` VARCHAR(160) NULL AFTER `orientation_attended_at`",
        'policy_acknowledged_at'  => "ALTER TABLE `employee_onboarding` ADD COLUMN `policy_acknowledged_at` DATETIME NULL AFTER `checked_in_by`",
    ];

    foreach ($columnsToAdd as $col => $sql) {
        $chk = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'employee_onboarding'
              AND COLUMN_NAME = ?
        ");
        $chk->execute([$col]);
        if ((int)$chk->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

function hris_recompute_onboarding_status(PDO $pdo, int $onbId): string
{
    $stmt = $pdo->prepare("
        SELECT `step_info`, `step_documents`, `step_orientation`, `step_policy`
        FROM `employee_onboarding`
        WHERE `id` = ?
        LIMIT 1
    ");
    $stmt->execute([$onbId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'In Progress';
    }

    $stepsDone = (int)($row['step_info'] ?? 0)
        + (int)($row['step_documents'] ?? 0)
        + (int)($row['step_orientation'] ?? 0)
        + (int)($row['step_policy'] ?? 0);

    $nextStatus = $stepsDone >= 4 ? 'Completed' : 'In Progress';
    $pdo->prepare("UPDATE `employee_onboarding` SET `status` = ? WHERE `id` = ?")
        ->execute([$nextStatus, $onbId]);

    return $nextStatus;
}

function hris_sync_and_fetch_onboarding(PDO $pdo): array
{
    hris_ensure_onboarding_schema($pdo);

    try {
        $empStmt = $pdo->query("
            SELECT
                `id`,
                `employee_code`,
                TRIM(CONCAT(COALESCE(`first_name`, ''), ' ', COALESCE(`last_name`, ''))) AS full_name,
                COALESCE(`email`, '') AS email,
                COALESCE(`department`, 'General') AS department,
                COALESCE(`job_title`, COALESCE(`position`, 'New Hire')) AS job_title
            FROM `employees`
            ORDER BY `id` ASC
        ");
        $employees = $empStmt ? $empStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $insStmt = $pdo->prepare("
            INSERT IGNORE INTO `employee_onboarding`
                (`employee_id`, `employee_code`, `full_name`, `email`, `department`, `job_title`, `status`, `step_info`)
            VALUES
                (?, ?, ?, ?, ?, ?, 'In Progress', 1)
        ");

        foreach ($employees as $idx => $emp) {
            $code = trim((string)($emp['employee_code'] ?? ''));
            if ($code === '') {
                $code = 'EMP-OB-' . str_pad((string)((int)($emp['id'] ?? ($idx + 1))), 3, '0', STR_PAD_LEFT);
            }
            $fullName = trim((string)($emp['full_name'] ?? ''));
            if ($fullName === '') {
                $fullName = 'Employee #' . (int)($emp['id'] ?? ($idx + 1));
            }
            $insStmt->execute([
                !empty($emp['id']) ? (int)$emp['id'] : null,
                $code,
                $fullName,
                trim((string)($emp['email'] ?? '')),
                trim((string)($emp['department'] ?? 'General')) ?: 'General',
                trim((string)($emp['job_title'] ?? 'New Hire')) ?: 'New Hire',
            ]);
        }
    } catch (Throwable $e) {
        error_log('Onboarding employee sync error: ' . $e->getMessage());
    }

    $rows = $pdo->query("SELECT * FROM `employee_onboarding` ORDER BY `id` ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $steps = (int)($r['step_info'] ?? 0)
            + (int)($r['step_documents'] ?? 0)
            + (int)($r['step_orientation'] ?? 0)
            + (int)($r['step_policy'] ?? 0);
        $r['completed_steps'] = $steps;
        $computedStatus = $steps >= 4 ? 'Completed' : 'In Progress';
        if (($r['status'] ?? '') !== $computedStatus) {
            $pdo->prepare("UPDATE `employee_onboarding` SET `status` = ? WHERE `id` = ?")
                ->execute([$computedStatus, (int)$r['id']]);
            $r['status'] = $computedStatus;
        }
    }
    unset($r);

    return $rows;
}

function hris_fetch_staff_options(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT
                `id`,
                TRIM(CONCAT(COALESCE(`first_name`, ''), ' ', COALESCE(`last_name`, ''))) AS full_name,
                COALESCE(`department`, '') AS department,
                COALESCE(`job_title`, COALESCE(`position`, '')) AS job_title
            FROM `employees`
            ORDER BY `full_name` ASC
        ");
        $list = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return array_values(array_filter($list, fn($x) => trim((string)($x['full_name'] ?? '')) !== ''));
    } catch (Throwable $e) {
        return [];
    }
}

function hris_build_hr_onboarding_payload(PDO $pdo, string $hrActorName): array
{
    $records = hris_sync_and_fetch_onboarding($pdo);
    $staff   = hris_fetch_staff_options($pdo);

    $facilitators = [];
    $inProgressCount = 0;
    $completedCount  = 0;

    foreach ($records as $rec) {
        $fac = trim((string)($rec['facilitator_name'] ?? ''));
        if ($fac !== '' && !in_array($fac, $facilitators, true)) {
            $facilitators[] = $fac;
        }
        if (($rec['status'] ?? '') === 'Completed') {
            $completedCount++;
        } else {
            $inProgressCount++;
        }
    }
    sort($facilitators, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'ok'              => true,
        'signature'       => md5(json_encode($records, JSON_UNESCAPED_UNICODE)),
        'serverTime'      => date('h:i:s A'),
        'hrActorName'     => $hrActorName,
        'records'         => $records,
        'staff'           => $staff,
        'facilitators'    => $facilitators,
        'inProgressCount' => $inProgressCount,
        'completedCount'  => $completedCount,
    ];
}

if (isset($_GET['api']) && $_GET['api'] === 'live_state') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(hris_build_hr_onboarding_payload($pdo, $hrActorName), JSON_UNESCAPED_UNICODE);
    exit;
}

$initialPayload = hris_build_hr_onboarding_payload($pdo, $hrActorName);
$flashMsg = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_POST['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    $action = (string)($_POST['onb_action'] ?? '');
    $targetId = (int)($_POST['onb_id'] ?? 0);

    if ($targetId > 0) {
        if ($action === 'toggle_step') {
            $stepCol = (string)($_POST['step_col'] ?? '');
            $allowedCols = ['step_info', 'step_documents', 'step_orientation', 'step_policy'];
            if (in_array($stepCol, $allowedCols, true)) {
                $curStmt = $pdo->prepare("SELECT `$stepCol` FROM `employee_onboarding` WHERE `id` = ? LIMIT 1");
                $curStmt->execute([$targetId]);
                $curVal = (int)$curStmt->fetchColumn();
                $nextVal = $curVal === 1 ? 0 : 1;

                if ($stepCol === 'step_orientation') {
                    if ($nextVal === 1) {
                        $pdo->prepare("
                            UPDATE `employee_onboarding`
                            SET `step_orientation` = 1,
                                `orientation_attended_at` = NOW(),
                                `checked_in_by` = ?
                            WHERE `id` = ?
                        ")->execute([$hrActorName, $targetId]);
                        $flashMsg = 'Orientation marked as Attended / Present.';
                    } else {
                        $pdo->prepare("
                            UPDATE `employee_onboarding`
                            SET `step_orientation` = 0,
                                `orientation_attended_at` = NULL,
                                `checked_in_by` = NULL
                            WHERE `id` = ?
                        ")->execute([$targetId]);
                        $flashMsg = 'Orientation check-in reset to Pending.';
                    }
                } elseif ($stepCol === 'step_policy') {
                    $pdo->prepare("
                        UPDATE `employee_onboarding`
                        SET `step_policy` = ?,
                            `policy_acknowledged_at` = CASE WHEN ? = 1 THEN NOW() ELSE NULL END
                        WHERE `id` = ?
                    ")->execute([$nextVal, $nextVal, $targetId]);
                    $flashMsg = 'Policy acknowledgement updated.';
                } else {
                    $pdo->prepare("UPDATE `employee_onboarding` SET `$stepCol` = ? WHERE `id` = ?")
                        ->execute([$nextVal, $targetId]);
                    $flashMsg = 'Onboarding step updated.';
                }

                $newStatus = hris_recompute_onboarding_status($pdo, $targetId);
                if ($newStatus === 'Completed') {
                    $flashMsg .= ' All 4 steps complete — moved to Completed.';
                }

                if (function_exists('hris_broadcast_onboarding_event')) {
                    hris_broadcast_onboarding_event('onboarding_updated', [
                        'onb_id' => $targetId,
                        'step'   => $stepCol,
                        'value'  => $nextVal,
                        'status' => $newStatus,
                    ]);
                }
            }
        } elseif ($action === 'save_schedule') {
            $schedRaw = trim((string)($_POST['orientation_at'] ?? ''));
            $room     = trim((string)($_POST['orientation_room'] ?? ''));
            $facEmpId = (int)($_POST['facilitator_employee_id'] ?? 0);
            $facName  = trim((string)($_POST['facilitator_name'] ?? ''));
            $buddy    = trim((string)($_POST['buddy_name'] ?? ''));

            if ($facEmpId > 0 && $facName === '') {
                foreach ($initialPayload['staff'] as $st) {
                    if ((int)$st['id'] === $facEmpId) {
                        $facName = (string)$st['full_name'];
                        break;
                    }
                }
            }

            $schedSql = null;
            if ($schedRaw !== '') {
                $normalized = str_replace('T', ' ', $schedRaw);
                if (strlen($normalized) === 16) {
                    $normalized .= ':00';
                }
                $schedSql = $normalized;
            }

            $pdo->prepare("
                UPDATE `employee_onboarding`
                SET `orientation_at` = ?,
                    `orientation_room` = ?,
                    `facilitator_employee_id` = ?,
                    `facilitator_name` = ?,
                    `buddy_name` = ?
                WHERE `id` = ?
            ")->execute([
                $schedSql,
                $room !== '' ? $room : null,
                $facEmpId > 0 ? $facEmpId : null,
                $facName !== '' ? $facName : null,
                $buddy !== '' ? $buddy : null,
                $targetId,
            ]);

            hris_recompute_onboarding_status($pdo, $targetId);
            $flashMsg = 'Orientation schedule & facilitator assignment saved.';

            if (function_exists('hris_broadcast_onboarding_event')) {
                hris_broadcast_onboarding_event('onboarding_updated', [
                    'onb_id' => $targetId,
                    'action' => 'save_schedule',
                ]);
            }
        } elseif ($action === 'door_mark_present') {
            $pdo->prepare("
                UPDATE `employee_onboarding`
                SET `step_orientation` = 1,
                    `orientation_attended_at` = NOW(),
                    `checked_in_by` = ?
                WHERE `id` = ?
            ")->execute([$hrActorName, $targetId]);
            $newStatus = hris_recompute_onboarding_status($pdo, $targetId);
            $flashMsg = $newStatus === 'Completed'
                ? 'Marked Present — all 4 steps complete, moved to Completed!'
                : 'New employee marked Present / Checked In at orientation door.';

            if (function_exists('hris_broadcast_onboarding_event')) {
                hris_broadcast_onboarding_event('onboarding_updated', [
                    'onb_id' => $targetId,
                    'action' => 'door_mark_present',
                    'status' => $newStatus,
                ]);
            }
        } elseif ($action === 'door_undo_present') {
            $pdo->prepare("
                UPDATE `employee_onboarding`
                SET `step_orientation` = 0,
                    `orientation_attended_at` = NULL,
                    `checked_in_by` = NULL
                WHERE `id` = ?
            ")->execute([$targetId]);
            hris_recompute_onboarding_status($pdo, $targetId);
            $flashMsg = 'Door check-in reverted to Pending (In Progress).';

            if (function_exists('hris_broadcast_onboarding_event')) {
                hris_broadcast_onboarding_event('onboarding_updated', [
                    'onb_id' => $targetId,
                    'action' => 'door_undo_present',
                    'status' => 'In Progress',
                ]);
            }
        }
    }

    $nextPayload = hris_build_hr_onboarding_payload($pdo, $hrActorName);
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode([
            'ok'      => $flashErr === '',
            'message' => $flashErr !== '' ? $flashErr : $flashMsg,
            'state'   => $nextPayload,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $initialPayload = $nextPayload;
}

$pageTitle = 'Onboarding';
require_once __DIR__ . '/../templates/hrTemplates/header.php';
?>

<style>
  .hr-onb-wrap { max-width: 1360px; margin: 22px auto 48px; padding: 0 20px; color: #e2e8f0; }
  .hr-onb-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
  .hr-onb-head-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
  .hr-sync-pill {
    display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; border-radius: 999px;
    background: rgba(16, 185, 129, 0.16); color: #6ee7b7; border: 1px solid rgba(52, 211, 153, 0.35);
    font-size: 12px; font-weight: 700;
  }
  .hr-sync-dot {
    width: 8px; height: 8px; border-radius: 50%; background: #34d399;
    box-shadow: 0 0 8px #34d399; animation: hrPulse 1.6s infinite;
  }
  @keyframes hrPulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.45; transform: scale(0.85); }
  }
  .hr-back-btn {
    padding: 8px 14px; border-radius: 10px; background: #1e293b; color: #f8fafc;
    text-decoration: none; font-size: 13px; font-weight: 600; border: 1px solid rgba(148, 163, 184, 0.25);
  }
  .hr-onb-main-grid { display: grid; grid-template-columns: 440px 1fr; gap: 18px; align-items: start; margin-bottom: 20px; }
  @media (max-width: 1080px) { .hr-onb-main-grid { grid-template-columns: 1fr; } }

  /* Queue DataTable Card */
  .hr-queue-card {
    background: #111827; border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 16px; padding: 16px;
  }
  .hr-queue-tabs {
    display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0;
  }
  .hr-qtab {
    padding: 5px 10px; border-radius: 8px; border: 1px solid rgba(148, 163, 184, 0.25);
    background: #0b1120; color: #cbd5e1; font-size: 11.5px; font-weight: 700; cursor: pointer;
  }
  .hr-qtab.is-active { background: #2563eb; border-color: #2563eb; color: #ffffff; }
  .hr-qtab.is-done.is-active { background: #16a34a; border-color: #16a34a; color: #ffffff; }
  .hr-qtab.is-prog.is-active { background: #1d4ed8; border-color: #3b82f6; color: #ffffff; }

  .hr-queue-toolbar {
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px;
    margin-bottom: 8px; font-size: 12px; color: #94a3b8;
  }
  .hr-qtable-wrap {
    max-height: 360px; overflow: auto; border: 1px solid rgba(148, 163, 184, 0.16);
    border-radius: 10px;
  }
  .hr-qtable { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  .hr-qtable th {
    position: sticky; top: 0; z-index: 1; text-align: left; padding: 9px 10px;
    font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8;
    background: #0b1120; border-bottom: 1px solid rgba(148, 163, 184, 0.22); cursor: pointer; user-select: none;
  }
  .hr-qtable th:hover { color: #ffffff; background: #131d33; }
  .hr-qtable td {
    padding: 9px 10px; border-bottom: 1px solid rgba(148, 163, 184, 0.12); color: #e2e8f0;
  }
  .hr-qtable tbody tr { cursor: pointer; transition: background 0.12s ease; }
  .hr-qtable tbody tr:hover td { background: rgba(30, 41, 59, 0.7); }
  .hr-qtable tbody tr.is-selected td {
    background: rgba(37, 99, 235, 0.24); border-top: 1px solid #3b82f6; border-bottom: 1px solid #3b82f6;
  }

  /* Detail panel */
  .hr-detail-card {
    background: #111827; border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 16px; padding: 22px 24px;
  }
  .hr-step-box {
    background: #161f33; border: 1px solid rgba(148, 163, 184, 0.15);
    border-radius: 12px; padding: 15px 17px; margin-top: 12px;
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px;
  }
  .hr-badge-prog { background: rgba(59, 130, 246, 0.22); color: #93c5fd; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
  .hr-badge-done { background: rgba(16, 185, 129, 0.22); color: #6ee7b7; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
  .hr-badge-pend { background: rgba(245, 158, 11, 0.22); color: #fcd34d; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }

  .hr-btn {
    padding: 7px 13px; border-radius: 8px; border: 1px solid rgba(148, 163, 184, 0.3);
    background: #1e293b; color: #f8fafc; font-size: 12.5px; font-weight: 600; cursor: pointer;
  }
  .hr-btn:hover { background: #334155; }
  .hr-btn-primary { background: #2563eb; border-color: #2563eb; color: #ffffff; }
  .hr-btn-primary:hover { background: #1d4ed8; }
  .hr-btn-success { background: #16a34a; border-color: #16a34a; color: #ffffff; }
  .hr-btn-success:hover { background: #15803d; }

  .hr-sched-drawer {
    background: #0b1120; border: 1px solid rgba(59, 130, 246, 0.4);
    border-radius: 12px; padding: 16px; margin-top: 12px; display: none;
  }
  .hr-sched-drawer.is-open { display: block; }
  .hr-sched-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 12px; }
  @media (max-width: 720px) { .hr-sched-grid { grid-template-columns: 1fr; } }
  .hr-input {
    width: 100%; box-sizing: border-box; padding: 8px 11px; border-radius: 8px;
    border: 1px solid rgba(148, 163, 184, 0.35); background: #0b1120; color: #f8fafc; font-size: 13px;
  }
  .hr-input::placeholder { color: #94a3b8; }

  /* Door Check-In Roster DataTable */
  .hr-roster-card {
    background: #111827; border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 16px; padding: 22px 24px;
  }
  .hr-roster-top {
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 14px; margin-bottom: 14px;
  }
  .hr-roster-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
  .hr-dt-bar {
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px;
    background: #0b1120; border: 1px solid rgba(148, 163, 184, 0.16);
    border-radius: 10px; padding: 10px 14px; margin-bottom: 12px; font-size: 12.5px; color: #cbd5e1;
  }
  .hr-dt-select {
    padding: 6px 10px; border-radius: 7px; border: 1px solid rgba(148, 163, 184, 0.35);
    background: #111827; color: #f8fafc; font-size: 12.5px; font-weight: 600;
  }
  .hr-table-wrap { overflow-x: auto; border: 1px solid rgba(148, 163, 184, 0.16); border-radius: 12px; }
  .hr-dt { width: 100%; border-collapse: collapse; font-size: 13px; color: #f8fafc; }
  .hr-dt th {
    text-align: left; padding: 11px 12px; font-size: 11px; text-transform: uppercase;
    letter-spacing: 0.04em; color: #94a3b8; background: #0b1120;
    border-bottom: 1px solid rgba(148, 163, 184, 0.2); white-space: nowrap;
  }
  .hr-dt th.hr-sort { cursor: pointer; user-select: none; }
  .hr-dt th.hr-sort:hover { color: #ffffff; background: #131d33; }
  .hr-dt td {
    padding: 12px; border-bottom: 1px solid rgba(148, 163, 184, 0.12);
    color: #e2e8f0; vertical-align: middle;
  }
  .hr-dt tbody tr:hover td { background: rgba(30, 41, 59, 0.55); }
  .hr-dt-foot {
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center;
    gap: 10px; margin-top: 12px; font-size: 12.5px; color: #94a3b8;
  }
  .hr-pages { display: flex; flex-wrap: wrap; gap: 6px; }
  .hr-page-btn {
    padding: 5px 10px; border-radius: 7px; border: 1px solid rgba(148, 163, 184, 0.3);
    background: #161f33; color: #e2e8f0; font-size: 12px; font-weight: 600; cursor: pointer;
  }
  .hr-page-btn.is-active { background: #2563eb; border-color: #2563eb; color: #ffffff; }
  .hr-page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
</style>

<div class="hr-onb-wrap">
  <div class="hr-onb-head">
    <div>
      <h1 style="margin:0 0 3px; font-size:26px; font-weight:800; color:#ffffff;">Onboarding</h1>
      <div style="font-size:13.5px; color:#94a3b8;">Real-time HR onboarding workflow for documents, facilitator room check-in, and employee policy sign-off.</div>
    </div>
    <div class="hr-onb-head-actions">
      <div class="hr-sync-pill">
        <span class="hr-sync-dot"></span>
        <span>Live Sync • <strong id="hrOnbSyncTime"><?= htmlspecialchars($initialPayload['serverTime'], ENT_QUOTES, 'UTF-8') ?></strong></span>
      </div>
      <a href="recruitment.php" class="hr-back-btn">← Recruitment &amp; Hiring</a>
    </div>
  </div>

  <div id="hrOnbBanner">
    <?php if ($flashMsg !== ''): ?>
      <div style="background:rgba(16,185,129,0.16); border:1px solid rgba(52,211,153,0.45); color:#6ee7b7; padding:11px 15px; border-radius:10px; margin-bottom:14px; font-size:13px; font-weight:600;">
        <?= htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
      <div style="background:rgba(239,68,68,0.16); border:1px solid rgba(248,113,113,0.45); color:#fca5a5; padding:11px 15px; border-radius:10px; margin-bottom:14px; font-size:13px; font-weight:600;">
        <?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Top Section: Queue DataTable + Selected Onboarding Detail -->
  <div class="hr-onb-main-grid">
    <div class="hr-queue-card">
      <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
        <div style="font-size:11.5px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#94a3b8;">
          ONBOARDING QUEUE (<span id="hrQueueTotalCount"><?= count($initialPayload['records']) ?></span>)
        </div>
        <div style="font-size:11.5px; color:#94a3b8;">
          <span id="hrQueueProgCount"><?= (int)$initialPayload['inProgressCount'] ?></span> In Progress •
          <span id="hrQueueDoneCount" style="color:#6ee7b7;"><?= (int)$initialPayload['completedCount'] ?></span> Completed
        </div>
      </div>

      <input
        type="text"
        id="hrQueueSearchInput"
        class="hr-input"
        style="margin-top:10px;"
        placeholder="Search name, ID, or department / role..."
      >

      <!-- Default active tab is In Progress -->
      <div class="hr-queue-tabs" role="tablist" aria-label="Filter onboarding queue by status">
        <button type="button" class="hr-qtab is-prog is-active" data-qstatus="In Progress">In Progress (<span id="hrQTabProg"><?= (int)$initialPayload['inProgressCount'] ?></span>)</button>
        <button type="button" class="hr-qtab is-done" data-qstatus="Completed">Completed (<span id="hrQTabDone"><?= (int)$initialPayload['completedCount'] ?></span>)</button>
        <button type="button" class="hr-qtab" data-qstatus="all">All (<span id="hrQTabAll"><?= count($initialPayload['records']) ?></span>)</button>
      </div>

      <div class="hr-queue-toolbar">
        <div style="display:flex; align-items:center; gap:6px;">
          <span>Rows:</span>
          <select id="hrQueuePageSize" class="hr-dt-select" style="padding:4px 8px; font-size:11.5px;">
            <option value="5">5</option>
            <option value="10" selected>10</option>
            <option value="25">25</option>
          </select>
        </div>
        <div id="hrQueueDtInfo">0 shown</div>
      </div>

      <div class="hr-qtable-wrap">
        <table class="hr-qtable" id="hrQueueDataTable">
          <thead>
            <tr>
              <th data-qcol="full_name">Employee <span id="hrQSort-full_name">⇅</span></th>
              <th data-qcol="department_role">Dept / Role <span id="hrQSort-department_role">⇅</span></th>
              <th data-qcol="status">Status <span id="hrQSort-status">⇅</span></th>
            </tr>
          </thead>
          <tbody id="hrQueueTableBody"></tbody>
        </table>
      </div>

      <div class="hr-dt-foot" style="margin-top:10px;">
        <div id="hrQueuePageLabel">Page 1 of 1</div>
        <div class="hr-pages" id="hrQueuePagination"></div>
      </div>
    </div>

    <div class="hr-detail-card" id="hrDetailPanel"></div>
  </div>

  <!-- Bottom Section: HR / Facilitator Orientation Door Check-In Roster DataTable -->
  <div class="hr-roster-card">
    <div class="hr-roster-top">
      <div>
        <h2 style="margin:0 0 4px; font-size:18px; font-weight:700; color:#ffffff;">HR / Facilitator Orientation Door Check-In Roster</h2>
        <div style="font-size:13px; color:#94a3b8;">Verify scheduled new employees by Name or Employee ID at the orientation room entrance before marking them Present.</div>
      </div>
      <div class="hr-roster-filters">
        <select id="hrRosterFacilitatorFilter" class="hr-input" style="width:auto; min-width:170px;">
          <option value="all">All Facilitators</option>
        </select>
        <select id="hrRosterAttendanceFilter" class="hr-input" style="width:auto; min-width:155px;">
          <option value="all">All Attendance</option>
          <option value="present">Present / Checked In</option>
          <option value="pending">Pending Check-In</option>
        </select>
        <input
          type="text"
          id="hrRosterSearchInput"
          class="hr-input"
          style="width:auto; min-width:250px;"
          placeholder="Search Employee Name or ID (e.g. EMP-OB-005)..."
        >
      </div>
    </div>

    <div class="hr-dt-bar">
      <div style="display:flex; align-items:center; gap:8px;">
        <span>Show</span>
        <select id="hrRosterPageSize" class="hr-dt-select">
          <option value="5" selected>5</option>
          <option value="10">10</option>
          <option value="25">25</option>
          <option value="50">50</option>
        </select>
        <span>entries</span>
      </div>
      <div id="hrRosterFilteredBadge">Showing all entries</div>
    </div>

    <div class="hr-table-wrap">
      <table class="hr-dt" id="hrRosterDataTable">
        <thead>
          <tr>
            <th class="hr-sort" data-col="employee_code">Employee ID <span id="hrSort-employee_code">⇅</span></th>
            <th class="hr-sort" data-col="full_name">New Employee <span id="hrSort-full_name">⇅</span></th>
            <th class="hr-sort" data-col="department_role">Department / Role <span id="hrSort-department_role">⇅</span></th>
            <th class="hr-sort" data-col="orientation_at">Scheduled Date &amp; Time <span id="hrSort-orientation_at">⇅</span></th>
            <th class="hr-sort" data-col="orientation_room">Room <span id="hrSort-orientation_room">⇅</span></th>
            <th class="hr-sort" data-col="facilitator_name">Assigned Facilitator <span id="hrSort-facilitator_name">⇅</span></th>
            <th class="hr-sort" data-col="step_orientation">Attendance <span id="hrSort-step_orientation">⇅</span></th>
            <th>Door Check-In</th>
          </tr>
        </thead>
        <tbody id="hrRosterTableBody"></tbody>
      </table>
    </div>

    <div class="hr-dt-foot">
      <div id="hrRosterDtInfo">Showing 0 entries</div>
      <div class="hr-pages" id="hrRosterPagination"></div>
    </div>
  </div>
</div>

<script>
  (function () {
    let hrState = <?= json_encode($initialPayload, JSON_UNESCAPED_UNICODE) ?>;

    // Default to showing "In Progress" in the Onboarding Queue until another tab is clicked
    let queueSearchQuery = '';
    let queueStatusFilter = 'In Progress'; // 'In Progress' | 'Completed' | 'all'
    let queueSortCol = 'id';
    let queueSortDir = 'asc';
    let queuePage = 1;

    const initialRecords = Array.isArray(hrState.records) ? hrState.records : [];
    const firstInProgress = initialRecords.find(r => String(r.status || 'In Progress') !== 'Completed');
    let selectedId = firstInProgress
      ? Number(firstInProgress.id)
      : (initialRecords.length ? Number(initialRecords[0].id) : 0);
    let scheduleDrawerOpen = false;

    // Door Check-In Roster DataTable state
    let rosterSortCol = 'id';
    let rosterSortDir = 'asc';
    let rosterPage = 1;

    function esc(v) {
      return String(v ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function showBanner(ok, msg) {
      const host = document.getElementById('hrOnbBanner');
      if (!host || !msg) return;
      host.innerHTML = ok
        ? `<div style="background:rgba(16,185,129,0.16); border:1px solid rgba(52,211,153,0.45); color:#6ee7b7; padding:11px 15px; border-radius:10px; margin-bottom:14px; font-size:13px; font-weight:600;">${esc(msg)}</div>`
        : `<div style="background:rgba(239,68,68,0.16); border:1px solid rgba(248,113,113,0.45); color:#fca5a5; padding:11px 15px; border-radius:10px; margin-bottom:14px; font-size:13px; font-weight:600;">${esc(msg)}</div>`;
    }

    async function postHrAction(formData) {
      formData.append('ajax', '1');
      try {
        const res = await fetch(window.location.pathname + window.location.search, {
          method: 'POST',
          body: formData,
          cache: 'no-store'
        });
        const payload = await res.json();
        showBanner(Boolean(payload.ok), payload.message || '');
        if (payload.state) {
          renderHrOnboarding(payload.state);
        }
      } catch (_) {
        showBanner(false, 'Network error while saving onboarding update.');
      }
    }

    // ── Queue DataTable Controls ─────────────────────────────────────
    const queueSearchInput = document.getElementById('hrQueueSearchInput');
    const queuePageSizeEl  = document.getElementById('hrQueuePageSize');

    if (queueSearchInput) {
      queueSearchInput.addEventListener('input', () => {
        queueSearchQuery = queueSearchInput.value.trim().toLowerCase();
        queuePage = 1;
        renderQueueDataTable();
      });
    }

    if (queuePageSizeEl) {
      queuePageSizeEl.addEventListener('change', () => {
        queuePage = 1;
        renderQueueDataTable();
      });
    }

    document.querySelectorAll('.hr-qtab').forEach(tab => {
      tab.addEventListener('click', () => {
        queueStatusFilter = tab.getAttribute('data-qstatus') || 'In Progress';
        queuePage = 1;
        document.querySelectorAll('.hr-qtab').forEach(t => t.classList.remove('is-active'));
        tab.classList.add('is-active');
        renderQueueDataTable();
      });
    });

    function getQueueSortVal(rec, col) {
      switch (col) {
        case 'full_name': return String(rec.full_name || '').toLowerCase();
        case 'department_role': return `${rec.department || ''} ${rec.job_title || ''}`.toLowerCase();
        case 'status': return String(rec.status || 'In Progress').toLowerCase();
        default: return Number(rec.id || 0);
      }
    }

    function updateQueueSortIcons() {
      document.querySelectorAll('#hrQueueDataTable thead th[data-qcol]').forEach(th => {
        const col = th.getAttribute('data-qcol');
        const ind = document.getElementById('hrQSort-' + col);
        if (!ind) return;
        if (col === queueSortCol) {
          ind.textContent = queueSortDir === 'asc' ? '▲' : '▼';
          ind.style.color = '#60a5fa';
        } else {
          ind.textContent = '⇅';
          ind.style.color = '#64748b';
        }
      });
    }

    document.querySelectorAll('#hrQueueDataTable thead th[data-qcol]').forEach(th => {
      th.addEventListener('click', () => {
        const col = th.getAttribute('data-qcol');
        if (!col) return;
        if (queueSortCol === col) {
          queueSortDir = queueSortDir === 'asc' ? 'desc' : 'asc';
        } else {
          queueSortCol = col;
          queueSortDir = 'asc';
        }
        queuePage = 1;
        renderQueueDataTable();
      });
    });

    function renderQueueDataTable() {
      const list = Array.isArray(hrState.records) ? hrState.records : [];
      const progCount = list.filter(r => String(r.status || 'In Progress') !== 'Completed').length;
      const doneCount = list.filter(r => String(r.status || '') === 'Completed').length;

      const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = String(val); };
      setText('hrQueueTotalCount', list.length);
      setText('hrQueueProgCount', progCount);
      setText('hrQueueDoneCount', doneCount);
      setText('hrQTabAll', list.length);
      setText('hrQTabProg', progCount);
      setText('hrQTabDone', doneCount);

      updateQueueSortIcons();

      let filtered = list.filter(rec => {
        const st = String(rec.status || 'In Progress');
        if (queueStatusFilter !== 'all' && st !== queueStatusFilter) return false;
        if (!queueSearchQuery) return true;
        const hay = `${rec.full_name || ''} ${rec.employee_code || ''} ${rec.department || ''} ${rec.job_title || ''} ${st}`.toLowerCase();
        return hay.includes(queueSearchQuery);
      });

      filtered.sort((a, b) => {
        const va = getQueueSortVal(a, queueSortCol);
        const vb = getQueueSortVal(b, queueSortCol);
        const cmp = String(va).localeCompare(String(vb), undefined, { numeric: true, sensitivity: 'base' });
        return queueSortDir === 'asc' ? cmp : -cmp;
      });

      const pageSize = Math.max(1, Number(queuePageSizeEl?.value || 10));
      const totalFiltered = filtered.length;
      const totalPages = Math.max(1, Math.ceil(totalFiltered / pageSize));
      if (queuePage > totalPages) queuePage = totalPages;
      const startIdx = (queuePage - 1) * pageSize;
      const rows = filtered.slice(startIdx, startIdx + pageSize);

      setText('hrQueueDtInfo', `${totalFiltered} of ${list.length}`);
      setText('hrQueuePageLabel', `Page ${queuePage} of ${totalPages}`);

      const tbody = document.getElementById('hrQueueTableBody');
      if (tbody) {
        if (!rows.length) {
          const emptyMsg = queueStatusFilter === 'In Progress' && !queueSearchQuery
            ? 'All employees have completed onboarding! Click "Completed" or "All" above to view them.'
            : 'No matching employees in queue.';
          tbody.innerHTML = `<tr><td colspan="3" style="text-align:center; color:#94a3b8; padding:18px;">${emptyMsg}</td></tr>`;
        } else {
          tbody.innerHTML = rows.map(rec => {
            const id = Number(rec.id);
            const isSel = id === selectedId;
            const isDone = String(rec.status || '') === 'Completed';
            return `
              <tr class="${isSel ? 'is-selected' : ''}" data-select-onb="${id}">
                <td>
                  <strong style="color:#ffffff; display:block;">${esc(rec.full_name)}</strong>
                  <span style="font-size:11px; color:#94a3b8;">${esc(rec.employee_code)}</span>
                </td>
                <td>
                  <span style="display:block; color:#e2e8f0;">${esc(rec.department)}</span>
                  <span style="font-size:11px; color:#94a3b8;">${esc(rec.job_title)}</span>
                </td>
                <td>
                  <span class="${isDone ? 'hr-badge-done' : 'hr-badge-prog'}">
                    ${isDone ? 'Completed' : 'In Progress'}
                  </span>
                </td>
              </tr>
            `;
          }).join('');

          tbody.querySelectorAll('tr[data-select-onb]').forEach(tr => {
            tr.addEventListener('click', () => {
              selectedId = Number(tr.getAttribute('data-select-onb') || 0);
              scheduleDrawerOpen = false;
              renderQueueDataTable();
              renderDetailPanel();
            });
          });
        }
      }

      const pagerEl = document.getElementById('hrQueuePagination');
      if (pagerEl) {
        let html = `<button type="button" class="hr-page-btn" data-qpage="${queuePage - 1}" ${queuePage <= 1 ? 'disabled' : ''}>Prev</button>`;
        for (let p = 1; p <= totalPages; p++) {
          html += `<button type="button" class="hr-page-btn ${p === queuePage ? 'is-active' : ''}" data-qpage="${p}">${p}</button>`;
        }
        html += `<button type="button" class="hr-page-btn" data-qpage="${queuePage + 1}" ${queuePage >= totalPages ? 'disabled' : ''}>Next</button>`;
        pagerEl.innerHTML = html;

        pagerEl.querySelectorAll('button.hr-page-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            const p = Number(btn.getAttribute('data-qpage') || 1);
            if (p >= 1 && p <= totalPages && p !== queuePage) {
              queuePage = p;
              renderQueueDataTable();
            }
          });
        });
      }
    }

    function toDatetimeLocalVal(sqlDt) {
      if (!sqlDt) return '';
      const clean = String(sqlDt).trim().replace(' ', 'T');
      return clean.length >= 16 ? clean.slice(0, 16) : clean;
    }

    function renderDetailPanel() {
      const panel = document.getElementById('hrDetailPanel');
      if (!panel) return;
      const list = Array.isArray(hrState.records) ? hrState.records : [];
      if (!list.length) {
        panel.innerHTML = `<div style="color:#94a3b8; font-size:13.5px;">No employee onboarding records found.</div>`;
        return;
      }

      let rec = list.find(r => Number(r.id) === selectedId);
      if (!rec) {
        rec = list.find(r => String(r.status || 'In Progress') !== 'Completed') || list[0];
        selectedId = Number(rec.id);
      }

      const stepsDone = Number(rec.step_info || 0)
        + Number(rec.step_documents || 0)
        + Number(rec.step_orientation || 0)
        + Number(rec.step_policy || 0);
      const isCompleted = stepsDone >= 4;
      const infoDone = Number(rec.step_info || 0) === 1;
      const docsDone = Number(rec.step_documents || 0) === 1;
      const oriDone  = Number(rec.step_orientation || 0) === 1;
      const polDone  = Number(rec.step_policy || 0) === 1;

      const staffList = Array.isArray(hrState.staff) ? hrState.staff : [];
      const staffOptionsHtml = staffList.map(st => {
        const stId = Number(st.id);
        const sel = Number(rec.facilitator_employee_id || 0) === stId ? 'selected' : '';
        return `<option value="${stId}" data-name="${esc(st.full_name)}" ${sel}>${esc(st.full_name)}${st.department ? ` (${esc(st.department)})` : ''}</option>`;
      }).join('');

      panel.innerHTML = `
        <div style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:14px;">
          <div>
            <h2 style="margin:0 0 3px; font-size:21px; font-weight:800; color:#ffffff;">${esc(rec.full_name)}</h2>
            <div style="font-size:13px; color:#94a3b8;">
              ${esc(rec.employee_code)} • ${esc(rec.department)} • ${esc(rec.job_title)}
            </div>
          </div>
          <span class="${isCompleted ? 'hr-badge-done' : 'hr-badge-prog'}">
            ${isCompleted ? `Completed (${stepsDone}/4)` : `In Progress (${stepsDone}/4)`}
          </span>
        </div>

        <!-- Step 1 -->
        <div class="hr-step-box">
          <div>
            <div style="font-size:14px; font-weight:700; color:#ffffff;">1. Personal Information &amp; Employee Record</div>
            <div style="font-size:12.5px; color:#94a3b8; margin-top:2px;">Verified by HR during employee creation.</div>
          </div>
          <div style="display:flex; align-items:center; gap:8px;">
            <form method="post" class="hr-ajax-step-form" style="display:inline;">
              <input type="hidden" name="onb_action" value="toggle_step">
              <input type="hidden" name="onb_id" value="${Number(rec.id)}">
              <input type="hidden" name="step_col" value="step_info">
              <button type="submit" class="hr-btn">${infoDone ? 'Undo' : 'Mark Done'}</button>
            </form>
            <span class="${infoDone ? 'hr-badge-done' : 'hr-badge-pend'}">${infoDone ? 'Done' : 'Pending'}</span>
          </div>
        </div>

        <!-- Step 2 -->
        <div class="hr-step-box">
          <div>
            <div style="font-size:14px; font-weight:700; color:#ffffff;">2. Pre-Employment Documents</div>
            <div style="font-size:12.5px; color:#94a3b8; margin-top:2px;">Government IDs, NBI/Police clearance, and signed job offer checked by HR.</div>
          </div>
          <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <a href="/hr/onboarding_documents.php?onb_id=${Number(rec.id)}" class="hr-btn hr-btn-primary" style="text-decoration:none;">
              Fill Up Documents
            </a>
            <form method="post" class="hr-ajax-step-form" style="display:inline;">
              <input type="hidden" name="onb_action" value="toggle_step">
              <input type="hidden" name="onb_id" value="${Number(rec.id)}">
              <input type="hidden" name="step_col" value="step_documents">
              <button type="submit" class="hr-btn">${docsDone ? 'Undo' : 'Mark Done'}</button>
            </form>
            <span class="${docsDone ? 'hr-badge-done' : 'hr-badge-pend'}">${docsDone ? 'Done' : 'Pending'}</span>
          </div>
        </div>

        <!-- Step 3 -->
        <div class="hr-step-box">
          <div>
            <div style="font-size:14px; font-weight:700; color:#ffffff;">3. Company Orientation (HR / Facilitator Check-In)</div>
            <div style="font-size:12.5px; color:#94a3b8; margin-top:2px;">
              Schedule: <strong>${esc(rec.orientation_at || 'Not scheduled')}</strong> • Facilitator: <strong>${esc(rec.facilitator_name || 'Unassigned')}</strong>${rec.orientation_room ? ` • Room: <strong>${esc(rec.orientation_room)}</strong>` : ''}
            </div>
          </div>
          <div style="display:flex; flex-wrap:wrap; align-items:center; gap:8px;">
            <button type="button" class="hr-btn" id="hrToggleScheduleBtn">Schedule / Assign Facilitator</button>
            <form method="post" class="hr-ajax-step-form" style="display:inline;">
              <input type="hidden" name="onb_action" value="toggle_step">
              <input type="hidden" name="onb_id" value="${Number(rec.id)}">
              <input type="hidden" name="step_col" value="step_orientation">
              <button type="submit" class="hr-btn ${oriDone ? '' : 'hr-btn-success'}">
                ${oriDone ? 'Undo Check-In' : 'Mark Attended'}
              </button>
            </form>
            <span class="${oriDone ? 'hr-badge-done' : 'hr-badge-pend'}">${oriDone ? 'Attended' : 'Pending'}</span>
          </div>
        </div>

        <div class="hr-sched-drawer ${scheduleDrawerOpen ? 'is-open' : ''}" id="hrScheduleDrawer">
          <form method="post" id="hrScheduleForm">
            <input type="hidden" name="onb_action" value="save_schedule">
            <input type="hidden" name="onb_id" value="${Number(rec.id)}">
            <input type="hidden" name="facilitator_name" id="hrFacNameHidden" value="${esc(rec.facilitator_name || '')}">
            <div class="hr-sched-grid">
              <div>
                <label style="display:block; font-size:11.5px; color:#94a3b8; margin-bottom:4px;">Orientation Date &amp; Time</label>
                <input type="datetime-local" name="orientation_at" class="hr-input" value="${esc(toDatetimeLocalVal(rec.orientation_at))}">
              </div>
              <div>
                <label style="display:block; font-size:11.5px; color:#94a3b8; margin-bottom:4px;">Room / Venue</label>
                <input type="text" name="orientation_room" class="hr-input" placeholder="e.g. Room 3 floor 31" value="${esc(rec.orientation_room || '')}">
              </div>
              <div>
                <label style="display:block; font-size:11.5px; color:#94a3b8; margin-bottom:4px;">Assigned HR / Facilitator</label>
                <select name="facilitator_employee_id" id="hrFacSelect" class="hr-input">
                  <option value="">-- Select Facilitator --</option>
                  ${staffOptionsHtml}
                </select>
              </div>
              <div>
                <label style="display:block; font-size:11.5px; color:#94a3b8; margin-bottom:4px;">Team Buddy</label>
                <input type="text" name="buddy_name" class="hr-input" placeholder="Optional buddy name" value="${esc(rec.buddy_name || '')}">
              </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
              <button type="button" class="hr-btn" id="hrCancelScheduleBtn">Cancel</button>
              <button type="submit" class="hr-btn hr-btn-primary">Save Schedule</button>
            </div>
          </form>
        </div>

        <!-- Step 4 -->
        <div class="hr-step-box">
          <div>
            <div style="font-size:14px; font-weight:700; color:#ffffff;">4. Policy Acknowledgement (Employee Sign-Off)</div>
            <div style="font-size:12.5px; color:#94a3b8; margin-top:2px;">Completed by the new employee inside <strong>My Onboarding</strong>.</div>
          </div>
          <span class="${polDone ? 'hr-badge-done' : 'hr-badge-pend'}">
            ${polDone ? 'Acknowledged by Employee' : 'Pending Employee'}
          </span>
        </div>

        <div style="margin-top:16px; border-radius:10px; padding:12px 16px; text-align:center; font-size:13.5px; font-weight:700; ${isCompleted ? 'background:rgba(124,58,237,0.45); color:#ddd6fe; border:1px solid rgba(167,139,250,0.4);' : 'background:rgba(30,41,59,0.75); color:#94a3b8; border:1px solid rgba(148,163,184,0.2);'}">
          ${isCompleted ? 'Onboarding Completed ✓' : `Onboarding In Progress (${stepsDone}/4 Steps)`}
        </div>
      `;

      const toggleSchedBtn = document.getElementById('hrToggleScheduleBtn');
      const cancelSchedBtn = document.getElementById('hrCancelScheduleBtn');
      if (toggleSchedBtn) {
        toggleSchedBtn.addEventListener('click', () => {
          scheduleDrawerOpen = !scheduleDrawerOpen;
          renderDetailPanel();
        });
      }
      if (cancelSchedBtn) {
        cancelSchedBtn.addEventListener('click', () => {
          scheduleDrawerOpen = false;
          renderDetailPanel();
        });
      }

      const facSelect = document.getElementById('hrFacSelect');
      const facHidden = document.getElementById('hrFacNameHidden');
      if (facSelect && facHidden) {
        facSelect.addEventListener('change', () => {
          const opt = facSelect.options[facSelect.selectedIndex];
          facHidden.value = opt ? (opt.getAttribute('data-name') || '') : '';
        });
      }

      const schedForm = document.getElementById('hrScheduleForm');
      if (schedForm) {
        schedForm.addEventListener('submit', async e => {
          e.preventDefault();
          scheduleDrawerOpen = false;
          await postHrAction(new FormData(schedForm));
        });
      }

      panel.querySelectorAll('form.hr-ajax-step-form').forEach(form => {
        form.addEventListener('submit', async e => {
          e.preventDefault();
          await postHrAction(new FormData(form));
        });
      });
    }

    // ── Door Check-In Roster DataTable ────────────────────────────────
    const facFilterEl  = document.getElementById('hrRosterFacilitatorFilter');
    const attFilterEl  = document.getElementById('hrRosterAttendanceFilter');
    const rosSearchEl  = document.getElementById('hrRosterSearchInput');
    const rosSizeEl    = document.getElementById('hrRosterPageSize');

    function syncFacilitatorDropdown() {
      if (!facFilterEl) return;
      const curVal = facFilterEl.value || 'all';
      const facs = Array.isArray(hrState.facilitators) ? hrState.facilitators : [];
      facFilterEl.innerHTML = `<option value="all">All Facilitators</option>` + facs.map(f => {
        const sel = curVal === f ? 'selected' : '';
        return `<option value="${esc(f)}" ${sel}>${esc(f)}</option>`;
      }).join('');
    }

    function getRosterSortVal(rec, col) {
      switch (col) {
        case 'employee_code': return String(rec.employee_code || '').toLowerCase();
        case 'full_name': return String(rec.full_name || '').toLowerCase();
        case 'department_role': return `${rec.department || ''} ${rec.job_title || ''}`.toLowerCase();
        case 'orientation_at': return String(rec.orientation_at || '9999-12-31 23:59:59').toLowerCase();
        case 'orientation_room': return String(rec.orientation_room || 'zzz').toLowerCase();
        case 'facilitator_name': return String(rec.facilitator_name || 'zzz').toLowerCase();
        case 'step_orientation': return Number(rec.step_orientation || 0);
        default: return Number(rec.id || 0);
      }
    }

    function updateRosterSortIcons() {
      document.querySelectorAll('#hrRosterDataTable thead th.hr-sort').forEach(th => {
        const col = th.getAttribute('data-col');
        const ind = document.getElementById('hrSort-' + col);
        if (!ind) return;
        if (col === rosterSortCol) {
          ind.textContent = rosterSortDir === 'asc' ? '▲' : '▼';
          ind.style.color = '#60a5fa';
        } else {
          ind.textContent = '⇅';
          ind.style.color = '#64748b';
        }
      });
    }

    document.querySelectorAll('#hrRosterDataTable thead th.hr-sort').forEach(th => {
      th.addEventListener('click', () => {
        const col = th.getAttribute('data-col');
        if (!col) return;
        if (rosterSortCol === col) {
          rosterSortDir = rosterSortDir === 'asc' ? 'desc' : 'asc';
        } else {
          rosterSortCol = col;
          rosterSortDir = 'asc';
        }
        rosterPage = 1;
        renderRosterDataTable();
      });
    });

    [facFilterEl, attFilterEl, rosSearchEl, rosSizeEl].forEach(el => {
      if (!el) return;
      const evt = el.tagName === 'INPUT' ? 'input' : 'change';
      el.addEventListener(evt, () => {
        rosterPage = 1;
        renderRosterDataTable();
      });
    });

    function renderRosterDataTable() {
      syncFacilitatorDropdown();
      updateRosterSortIcons();

      const list = Array.isArray(hrState.records) ? hrState.records : [];
      const facVal = facFilterEl?.value || 'all';
      const attVal = attFilterEl?.value || 'all';
      const q = (rosSearchEl?.value || '').trim().toLowerCase();
      const pageSize = Math.max(1, Number(rosSizeEl?.value || 5));

      let filtered = list.filter(rec => {
        const attended = Number(rec.step_orientation || 0) === 1;
        if (facVal !== 'all' && String(rec.facilitator_name || '') !== facVal) return false;
        if (attVal === 'present' && !attended) return false;
        if (attVal === 'pending' && attended) return false;

        if (!q) return true;
        const hay = `${rec.employee_code || ''} ${rec.full_name || ''} ${rec.department || ''} ${rec.job_title || ''} ${rec.orientation_at || ''} ${rec.orientation_room || ''} ${rec.facilitator_name || ''} ${rec.checked_in_by || ''}`.toLowerCase();
        return hay.includes(q);
      });

      filtered.sort((a, b) => {
        const va = getRosterSortVal(a, rosterSortCol);
        const vb = getRosterSortVal(b, rosterSortCol);
        const cmp = String(va).localeCompare(String(vb), undefined, { numeric: true, sensitivity: 'base' });
        return rosterSortDir === 'asc' ? cmp : -cmp;
      });

      const totalFiltered = filtered.length;
      const totalPages = Math.max(1, Math.ceil(totalFiltered / pageSize));
      if (rosterPage > totalPages) rosterPage = totalPages;
      const startIdx = (rosterPage - 1) * pageSize;
      const rows = filtered.slice(startIdx, startIdx + pageSize);

      const badgeEl = document.getElementById('hrRosterFilteredBadge');
      if (badgeEl) {
        badgeEl.textContent = `${totalFiltered} matching of ${list.length} total`;
      }

      const tbody = document.getElementById('hrRosterTableBody');
      if (tbody) {
        if (!rows.length) {
          tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:20px;">No orientation roster records match your filter.</td></tr>`;
        } else {
          tbody.innerHTML = rows.map(rec => {
            const attended = Number(rec.step_orientation || 0) === 1;
            const checkedByText = rec.checked_in_by ? `Checked in by ${esc(rec.checked_in_by)}` : '';
            return `
              <tr>
                <td><strong style="color:#ffffff;">${esc(rec.employee_code)}</strong></td>
                <td><strong style="color:#ffffff;">${esc(rec.full_name)}</strong></td>
                <td>${esc(rec.department)} • ${esc(rec.job_title)}</td>
                <td>${esc(rec.orientation_at || 'Not scheduled')}</td>
                <td>${esc(rec.orientation_room || '—')}</td>
                <td>${esc(rec.facilitator_name || 'Unassigned')}</td>
                <td>
                  <span class="${attended ? 'hr-badge-done' : 'hr-badge-pend'}">
                    ${attended ? 'Present / Checked In' : 'Pending Check-In'}
                  </span>
                </td>
                <td>
                  ${!attended ? `
                    <form method="post" class="hr-roster-action-form" style="display:inline;">
                      <input type="hidden" name="onb_action" value="door_mark_present">
                      <input type="hidden" name="onb_id" value="${Number(rec.id)}">
                      <button type="submit" class="hr-btn hr-btn-success">Mark Present</button>
                    </form>
                  ` : `
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                      <span style="font-size:12px; color:#94a3b8;">${checkedByText}</span>
                      <form method="post" class="hr-roster-action-form" style="display:inline;">
                        <input type="hidden" name="onb_action" value="door_undo_present">
                        <input type="hidden" name="onb_id" value="${Number(rec.id)}">
                        <button type="submit" class="hr-btn">Undo</button>
                      </form>
                    </div>
                  `}
                </td>
              </tr>
            `;
          }).join('');

          tbody.querySelectorAll('form.hr-roster-action-form').forEach(form => {
            form.addEventListener('submit', async e => {
              e.preventDefault();
              await postHrAction(new FormData(form));
            });
          });
        }
      }

      const infoEl = document.getElementById('hrRosterDtInfo');
      if (infoEl) {
        infoEl.textContent = totalFiltered === 0
          ? `Showing 0 of ${list.length} entries`
          : `Showing ${startIdx + 1} to ${Math.min(startIdx + pageSize, totalFiltered)} of ${totalFiltered} filtered (${list.length} total)`;
      }

      const pagerEl = document.getElementById('hrRosterPagination');
      if (pagerEl) {
        let html = `<button type="button" class="hr-page-btn" data-rpage="${rosterPage - 1}" ${rosterPage <= 1 ? 'disabled' : ''}>Prev</button>`;
        for (let p = 1; p <= totalPages; p++) {
          html += `<button type="button" class="hr-page-btn ${p === rosterPage ? 'is-active' : ''}" data-rpage="${p}">${p}</button>`;
        }
        html += `<button type="button" class="hr-page-btn" data-rpage="${rosterPage + 1}" ${rosterPage >= totalPages ? 'disabled' : ''}>Next</button>`;
        pagerEl.innerHTML = html;

        pagerEl.querySelectorAll('button.hr-page-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            const p = Number(btn.getAttribute('data-rpage') || 1);
            if (p >= 1 && p <= totalPages && p !== rosterPage) {
              rosterPage = p;
              renderRosterDataTable();
            }
          });
        });
      }
    }

    function renderHrOnboarding(payload) {
      if (!payload || !payload.ok) return;
      hrState = payload;

      const syncEl = document.getElementById('hrOnbSyncTime');
      if (syncEl && payload.serverTime) syncEl.textContent = payload.serverTime;

      renderQueueDataTable();
      renderDetailPanel();
      renderRosterDataTable();
    }

    renderHrOnboarding(hrState);

    async function pollHrOnboarding() {
      try {
        const res = await fetch(`?api=live_state&_=${Date.now()}`, { cache: 'no-store' });
        if (!res.ok) return;
        const next = await res.json();
        if (next && next.ok) {
          if (next.signature && next.signature !== hrState.signature) {
            renderHrOnboarding(next);
          } else {
            const syncEl = document.getElementById('hrOnbSyncTime');
            if (syncEl && next.serverTime) syncEl.textContent = next.serverTime;
          }
        }
      } catch (_) {}
    }

    (function connectHrWS() {
      const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      let ws;
      try {
        ws = new WebSocket(`${proto}//${window.location.hostname}:8090/ws`);
        ws.onmessage = () => pollHrOnboarding();
        ws.onclose = () => setTimeout(connectHrWS, 3000);
      } catch (_) {}
    })();

    setInterval(() => { if (!document.hidden) pollHrOnboarding(); }, 1500);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) pollHrOnboarding();
    });
  })();
</script>

    </section>
  </main>
</body>

</html>