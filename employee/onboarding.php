<?php
// employee/onboarding.php

require_once __DIR__ . '/../authentication/guard.php';
require_once __DIR__ . '/../config/database.php';

$realtimeHelper = __DIR__ . '/../includes/realtime_push.php';
if (is_file($realtimeHelper)) {
    require_once $realtimeHelper;
}

$gateHelper = __DIR__ . '/../includes/onboarding_gate.php';
if (is_file($gateHelper)) {
    require_once $gateHelper;
}

$empId = (int)($_SESSION['employee_id'] ?? 0);
$role  = strtolower(trim((string)($_SESSION['role'] ?? 'employee')));
$email = trim((string)($_SESSION['email'] ?? ''));
$name  = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
$rank  = '';

if ($empId > 0) {
    $eStmt = $pdo->prepare("
        SELECT
            `email`,
            TRIM(CONCAT_WS(' ', NULLIF(TRIM(COALESCE(`first_name`, '')), ''), NULLIF(TRIM(COALESCE(`middle_name`, '')), ''), NULLIF(TRIM(COALESCE(`last_name`, '')), ''))) AS full_name,
            COALESCE(`rank`, '') AS `rank`
        FROM `employees`
        WHERE `id` = ?
        LIMIT 1
    ");
    $eStmt->execute([$empId]);
    if ($eRow = $eStmt->fetch(PDO::FETCH_ASSOC)) {
        if ($email === '') $email = trim((string)($eRow['email'] ?? ''));
        if ($name === '')  $name  = trim((string)($eRow['full_name'] ?? ''));
        $rank = strtolower(str_replace(['-', ' '], '_', trim((string)($eRow['rank'] ?? ''))));
    }
}

function hris_user_has_facilitator_access(string $role, string $rank): bool
{
    if (in_array($role, ['hr', 'admin', 'manager', 'facilitator'], true)) {
        return true;
    }
    $normRank = strtolower(str_replace(['-', ' '], '_', trim($rank)));
    return $normRank === 'team_leader';
}

if (!function_exists('hris_get_linked_onboarding_record')) {
    function hris_get_linked_onboarding_record(PDO $pdo, int $empId = 0, string $email = '', string $name = ''): ?array
    {
        if ($empId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM `employee_onboarding` WHERE `employee_id` = ? ORDER BY `id` DESC LIMIT 1");
            $stmt->execute([$empId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
        if ($email !== '') {
            $stmt = $pdo->prepare("SELECT * FROM `employee_onboarding` WHERE LOWER(TRIM(`email`)) = LOWER(TRIM(?)) ORDER BY `id` DESC LIMIT 1");
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
        if ($name !== '') {
            $stmt = $pdo->prepare("SELECT * FROM `employee_onboarding` WHERE LOWER(TRIM(`full_name`)) = LOWER(TRIM(?)) ORDER BY `id` DESC LIMIT 1");
            $stmt->execute([$name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
        return null;
    }
}

if (!function_exists('hris_is_orientation_locked_for_employee')) {
    function hris_is_orientation_locked_for_employee(PDO $pdo, int $empId = 0, string $email = '', string $name = ''): array
    {
        $onb = hris_get_linked_onboarding_record($pdo, $empId, $email, $name);
        if (!$onb) {
            return ['locked' => false, 'hasRecord' => false, 'attended' => true, 'onboarding' => null, 'message' => ''];
        }
        $attended = (int)($onb['step_orientation'] ?? 0) === 1;
        $facName  = trim((string)($onb['facilitator_name'] ?? 'your assigned HR / Facilitator'));
        $schedAt  = trim((string)($onb['orientation_at'] ?? 'scheduled time'));
        return [
            'locked'     => !$attended,
            'hasRecord'  => true,
            'attended'   => $attended,
            'onboarding' => $onb,
            'message'    => $attended
                ? ''
                : "Your Company Orientation attendance ({$schedAt}) has not been marked as Present yet by {$facName}. Time In / Time Out and Policy Acknowledgement remain locked until HR or your facilitator marks you present.",
        ];
    }
}

function hris_get_facilitator_assignments(PDO $pdo, int $empId, string $name, string $role = 'employee', string $rank = ''): array
{
    if (!hris_user_has_facilitator_access($role, $rank)) {
        return [];
    }
    if ($empId <= 0 && $name === '') {
        return [];
    }

    if ($empId > 0 && $name !== '') {
        $stmt = $pdo->prepare("
            SELECT *
            FROM `employee_onboarding`
            WHERE `facilitator_employee_id` = ?
               OR ((`facilitator_employee_id` IS NULL OR `facilitator_employee_id` = 0) AND LOWER(TRIM(COALESCE(`facilitator_name`, ''))) = LOWER(TRIM(?)))
            ORDER BY
                `step_orientation` ASC,
                CASE WHEN `orientation_at` IS NULL THEN 1 ELSE 0 END,
                `orientation_at` ASC,
                `id` DESC
        ");
        $stmt->execute([$empId, $name]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($empId > 0) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM `employee_onboarding`
            WHERE `facilitator_employee_id` = ?
            ORDER BY `step_orientation` ASC, `orientation_at` ASC, `id` DESC
        ");
        $stmt->execute([$empId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM `employee_onboarding`
        WHERE LOWER(TRIM(COALESCE(`facilitator_name`, ''))) = LOWER(TRIM(?))
        ORDER BY `step_orientation` ASC, `orientation_at` ASC, `id` DESC
    ");
    $stmt->execute([$name]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hris_notify_onboarding_realtime(array $payload): void
{
    if (function_exists('hris_broadcast_onboarding_event')) {
        hris_broadcast_onboarding_event('onboarding_updated', $payload);
    }
    if (function_exists('hris_realtime_push')) {
        hris_realtime_push('onboarding', 'onboarding_updated', $payload);
    }
}

function hris_get_employee_onboarding_state(PDO $pdo, int $empId, string $email, string $name, string $role = 'employee', string $rank = ''): array
{
    $onb = hris_get_linked_onboarding_record($pdo, $empId, $email, $name);
    $facilitatorQueue = hris_get_facilitator_assignments($pdo, $empId, $name, $role, $rank);
    $gate = hris_is_orientation_locked_for_employee($pdo, $empId, $email, $name);

    return [
        'ok'                => true,
        'signature'         => md5(json_encode(['onb' => $onb, 'fac' => $facilitatorQueue], JSON_UNESCAPED_UNICODE)),
        'serverTime'        => date('h:i:s A'),
        'onb'               => $onb,
        'orientationLocked' => (bool)$gate['locked'],
        'lockMessage'       => (string)$gate['message'],
        'facilitatorQueue'  => $facilitatorQueue,
    ];
}

if (isset($_GET['api']) && $_GET['api'] === 'live_state') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(hris_get_employee_onboarding_state($pdo, $empId, $email, $name, $role, $rank), JSON_UNESCAPED_UNICODE);
    exit;
}

$initial = hris_get_employee_onboarding_state($pdo, $empId, $email, $name, $role, $rank);
$onb     = $initial['onb'];

$flashError   = '';
$flashSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_POST['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    $action = (string)($_POST['emp_onb_action'] ?? '');

    if ($action === 'facilitator_mark_attended' || $action === 'facilitator_unmark_attended') {
        $targetId = (int)($_POST['target_onb_id'] ?? 0);
        if ($targetId > 0) {
            $allowed = hris_get_facilitator_assignments($pdo, $empId, $name, $role, $rank);
            $isAssigned = false;
            foreach ($allowed as $item) {
                if ((int)$item['id'] === $targetId) {
                    $isAssigned = true;
                    break;
                }
            }

            if (!$isAssigned) {
                $msg = 'You are not assigned as the HR / Facilitator for this scheduled employee.';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $flashError = $msg;
            } else {
                $verifier = $name !== '' ? $name : 'HR Facilitator';
                if ($action === 'facilitator_mark_attended') {
                    $pdo->prepare("
                        UPDATE `employee_onboarding`
                        SET `step_orientation` = 1,
                            `orientation_attended_at` = NOW(),
                            `checked_in_by` = ?
                        WHERE `id` = ?
                    ")->execute([$verifier, $targetId]);
                    $msg = 'New employee orientation attendance marked as Present / Done.';
                } else {
                    $pdo->prepare("
                        UPDATE `employee_onboarding`
                        SET `step_orientation` = 0,
                            `orientation_attended_at` = NULL,
                            `checked_in_by` = NULL
                        WHERE `id` = ?
                    ")->execute([$targetId]);
                    $msg = 'New employee orientation attendance reset to Pending.';
                }

                hris_notify_onboarding_realtime([
                    'onb_id'           => $targetId,
                    'action'           => $action,
                    'step_orientation' => $action === 'facilitator_mark_attended' ? 1 : 0,
                ]);

                $updated = hris_get_employee_onboarding_state($pdo, $empId, $email, $name, $role, $rank);
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                    echo json_encode(['ok' => true, 'message' => $msg, 'state' => $updated], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $flashSuccess = $msg;
            }
        }
    } elseif ($onb && $action === 'acknowledge_policies') {
        if ((int)($onb['step_policy'] ?? 0) === 1) {
            $msg = 'Company policies are already acknowledged and permanently locked.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'      => true,
                    'message' => $msg,
                    'state'   => hris_get_employee_onboarding_state($pdo, $empId, $email, $name, $role, $rank),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: onboarding.php');
            exit;
        }

        if ((int)($onb['step_orientation'] ?? 0) !== 1) {
            $msg = 'You cannot acknowledge or submit policies until HR or your assigned facilitator marks your orientation attendance as Present.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'      => false,
                    'message' => $msg,
                    'state'   => hris_get_employee_onboarding_state($pdo, $empId, $email, $name, $role, $rank),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $flashError = $msg;
        } else {
            $p1 = isset($_POST['pol_handbook']);
            $p2 = isset($_POST['pol_conduct']);
            $p3 = isset($_POST['pol_privacy']);

            if ($p1 && $p2 && $p3) {
                $onbId = (int)$onb['id'];
                $pdo->prepare("
                    UPDATE `employee_onboarding`
                    SET `step_policy` = 1, `policy_acknowledged_at` = NOW()
                    WHERE `id` = ?
                ")->execute([$onbId]);

                hris_notify_onboarding_realtime([
                    'onb_id'        => $onbId,
                    'employee_code' => (string)($onb['employee_code'] ?? ''),
                    'full_name'     => (string)($onb['full_name'] ?? ''),
                    'action'        => $action,
                    'step_policy'   => 1,
                ]);

                $updated = hris_get_employee_onboarding_state($pdo, $empId, $email, $name, $role, $rank);
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                    echo json_encode([
                        'ok'      => true,
                        'message' => 'Company policies acknowledged, locked, and synced to HR in real time.',
                        'state'   => $updated,
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                header('Location: onboarding.php');
                exit;
            } else {
                $msg = 'Please check all three company policies before submitting your acknowledgement.';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $flashError = $msg;
            }
        }
    }
}

$hasFacilitatorAccess = hris_user_has_facilitator_access($role, $rank);

require_once __DIR__ . '/../templates/employeeTemplates/header.php';
?>

<style>
  .eo-wrap { max-width: 1140px; margin: 24px auto 48px; padding: 0 20px; position: relative; z-index: 1; }
  .eo-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 16px; }
  .eo-top-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
  .eo-back {
    display: inline-block; padding: 8px 14px; border-radius: 10px; background: rgba(30, 41, 59, 0.85);
    color: #e2e8f0; text-decoration: none; font-size: 13px; font-weight: 600; border: 1px solid rgba(148, 163, 184, 0.25);
  }
  .eo-fac-btn {
    display: inline-flex; align-items: center; gap: 7px; padding: 8px 15px; border-radius: 10px;
    background: #2563eb; color: #ffffff; border: none; font-size: 13px; font-weight: 700; cursor: pointer;
  }
  .eo-fac-btn:hover { background: #1d4ed8; }
  .eo-fac-count {
    background: rgba(255, 255, 255, 0.22); padding: 1px 8px; border-radius: 999px; font-size: 11.5px;
  }
  .eo-sync {
    display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; border-radius: 999px; font-size: 12px; font-weight: 700;
    background: rgba(16, 185, 129, 0.16); color: #6ee7b7; border: 1px solid rgba(52, 211, 153, 0.35);
  }
  .eo-dot {
    width: 8px; height: 8px; border-radius: 50%; background: #34d399;
    box-shadow: 0 0 8px #34d399; animation: eoPulse 1.6s infinite;
  }
  @keyframes eoPulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.45; transform: scale(0.85); }
  }
  .eo-card { background: #ffffff; color: #0f172a; border-radius: 16px; padding: 22px 24px; position: relative; z-index: 2; }
  .eo-hero {
    background: #ffffff; color: #0f172a; border-radius: 16px; padding: 22px 26px;
    display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px;
  }
  .eo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
  @media (max-width: 820px) { .eo-grid { grid-template-columns: 1fr; } }
  .eo-pill-done { background: #dcfce7; color: #166534; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; display: inline-block; }
  .eo-pill-wait { background: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; display: inline-block; }
  .eo-pill-lock { background: #fee2e2; color: #991b1b; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; display: inline-block; }
  .eo-info-box {
    background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 12px;
    padding: 14px 16px; font-size: 13.5px; line-height: 1.65; margin: 14px 0; color: #0f172a;
  }
  .eo-readonly-banner {
    background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a;
    border-radius: 10px; padding: 11px 14px; font-size: 13px; font-weight: 600;
  }
  .eo-lock-banner {
    background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
    border-radius: 10px; padding: 11px 14px; font-size: 12.5px; font-weight: 600; margin-bottom: 12px;
  }
  .eo-attended-banner {
    background: #16a34a; color: #ffffff; text-align: center;
    border-radius: 10px; padding: 12px 14px; font-size: 14px; font-weight: 700;
  }
  .eo-pol-item {
    display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px;
    border: 1px solid #cbd5e1; border-radius: 10px; margin-bottom: 10px;
    cursor: pointer; color: #0f172a; background: #ffffff;
    position: relative; z-index: 3; user-select: none;
  }
  .eo-pol-item:hover:not(.is-locked):not(.is-done) {
    border-color: #2563eb; background: #f8fafc;
  }
  .eo-pol-item.is-locked {
    opacity: 0.6; cursor: not-allowed; background: #f8fafc;
  }
  .eo-pol-item.is-done {
    cursor: default; background: #f0fdf4; border-color: #86efac;
  }
  .eo-pol-checkbox {
    appearance: auto !important;
    -webkit-appearance: checkbox !important;
    opacity: 1 !important;
    position: static !important;
    pointer-events: auto !important;
    width: 18px !important;
    height: 18px !important;
    min-width: 18px !important;
    margin-top: 2px !important;
    accent-color: #16a34a;
    cursor: pointer;
    flex-shrink: 0;
  }
  .eo-pol-checkbox:disabled {
    opacity: 1 !important;
    cursor: default;
  }
  .eo-btn-submit {
    width: 100%; border: none; border-radius: 10px; padding: 12px 16px;
    background: #2563eb; color: #ffffff; font-size: 14px; font-weight: 700; cursor: pointer;
    position: relative; z-index: 3;
  }
  .eo-btn-submit.is-done {
    background: #16a34a; color: #ffffff; opacity: 1; cursor: default;
  }
  .eo-btn-submit:disabled:not(.is-done) { background: #94a3b8; cursor: not-allowed; }
  .eo-pol-note {
    margin-top: 8px; font-size: 12px; color: #166534; font-weight: 600; text-align: center;
  }

  .eo-fac-panel {
    background: #ffffff; color: #0f172a; border-radius: 16px; padding: 22px 24px;
    margin-bottom: 18px; border: 2px solid #2563eb; display: none;
  }
  .eo-fac-panel.is-open { display: block; }
  .eo-dt-controls {
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center;
    gap: 12px; margin: 14px 0 12px; padding: 12px 14px; background: #f8fafc;
    border: 1px solid #e2e8f0; border-radius: 12px;
  }
  .eo-dt-left, .eo-dt-right { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
  .eo-dt-label { font-size: 12.5px; color: #334155; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
  .eo-dt-select {
    padding: 7px 10px; border-radius: 8px; border: 1px solid #cbd5e1;
    background: #ffffff; color: #0f172a; font-size: 13px; font-weight: 600; cursor: pointer;
  }
  .eo-search {
    padding: 8px 12px; border-radius: 8px; border: 1px solid #94a3b8;
    background: #ffffff !important; color: #0f172a !important; font-size: 13px; min-width: 250px;
  }
  .eo-search::placeholder { color: #64748b; }
  .eo-table-wrap {
    overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;
  }
  .eo-table {
    width: 100%; border-collapse: collapse; font-size: 13.5px;
    color: #0f172a !important; background: #ffffff !important;
  }
  .eo-table th {
    text-align: left; padding: 11px 12px; font-size: 11.5px; text-transform: uppercase;
    color: #334155 !important; border-bottom: 1px solid #cbd5e1;
    background: #f1f5f9 !important; font-weight: 700; user-select: none; white-space: nowrap;
  }
  .eo-table th.eo-sortable { cursor: pointer; }
  .eo-table th.eo-sortable:hover { background: #e2e8f0 !important; color: #0f172a !important; }
  .eo-sort-ind { margin-left: 4px; font-size: 11px; color: #64748b; }
  .eo-table td {
    padding: 12px; border-bottom: 1px solid #e2e8f0;
    color: #0f172a !important; background: #ffffff !important; vertical-align: middle;
  }
  .eo-table tbody tr:hover td { background: #f8fafc !important; }
  .eo-table td strong { color: #0f172a !important; font-weight: 700; }
  .eo-dt-footer {
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center;
    gap: 10px; margin-top: 12px; font-size: 12.5px; color: #475569;
  }
  .eo-dt-pages { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
  .eo-page-btn {
    border: 1px solid #cbd5e1; background: #ffffff; color: #0f172a;
    padding: 5px 11px; border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer;
  }
  .eo-page-btn:hover:not(:disabled) { background: #f1f5f9; border-color: #94a3b8; }
  .eo-page-btn.is-active { background: #2563eb; border-color: #2563eb; color: #ffffff; }
  .eo-page-btn:disabled { opacity: 0.45; cursor: not-allowed; }
  .eo-btn-mark {
    border: none; background: #16a34a; color: #ffffff; padding: 7px 13px;
    border-radius: 8px; font-size: 12.5px; font-weight: 700; cursor: pointer; white-space: nowrap;
  }
  .eo-btn-mark:hover { background: #15803d; }
  .eo-btn-undo {
    border: 1px solid #cbd5e1; background: #ffffff; color: #0f172a; padding: 6px 11px;
    border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap;
  }
  .eo-btn-undo:hover { background: #f1f5f9; }
</style>

<div class="eo-wrap">
  <div class="eo-top">
    <div class="eo-top-actions">
      <a href="dashboard.php" class="eo-back">← Back to Employee Dashboard</a>
      <?php if ($hasFacilitatorAccess): ?>
        <button type="button" class="eo-fac-btn" id="toggleFacilitatorPanelBtn">
          <span>Facilitator Check-In</span>
          <span class="eo-fac-count" id="eoFacCountBadge"><?= count($initial['facilitatorQueue']) ?></span>
        </button>
      <?php endif; ?>
    </div>
    <div class="eo-sync">
      <span class="eo-dot"></span>
      <span>Live Sync with HR • <strong id="eoLastSync"><?= htmlspecialchars($initial['serverTime'], ENT_QUOTES, 'UTF-8') ?></strong></span>
    </div>
  </div>

  <div id="eoBanner">
    <?php if ($flashError !== ''): ?>
      <div style="background:#fee2e2; color:#991b1b; padding:11px 14px; border-radius:10px; margin-bottom:14px; font-size:13.5px;"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($flashSuccess !== ''): ?>
      <div style="background:#dcfce7; color:#166534; padding:11px 14px; border-radius:10px; margin-bottom:14px; font-size:13.5px;"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
  </div>

  <?php if ($hasFacilitatorAccess): ?>
    <div class="eo-fac-panel" id="eoFacilitatorPanel">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
        <div>
          <h2 style="margin:0 0 3px; font-size:18px; color:#0f172a;">My Assigned Orientation Schedule (Team Leader / Facilitator)</h2>
          <p style="margin:0; font-size:13px; color:#475569;">
            New employees scheduled for orientation under <strong><?= htmlspecialchars($name !== '' ? $name : 'your account', ENT_QUOTES, 'UTF-8') ?></strong>. Verify their Name or Employee ID at the orientation room and mark attendance as Done.
          </p>
        </div>
        <button type="button" id="closeFacilitatorPanelBtn" class="eo-btn-undo">Close</button>
      </div>

      <div class="eo-dt-controls">
        <div class="eo-dt-left">
          <label class="eo-dt-label">
            Show
            <select id="eoDtPageSize" class="eo-dt-select">
              <option value="5" selected>5</option>
              <option value="10">10</option>
              <option value="25">25</option>
              <option value="50">50</option>
            </select>
            entries
          </label>
          <label class="eo-dt-label">
            Status:
            <select id="eoDtStatusFilter" class="eo-dt-select">
              <option value="all" selected>All</option>
              <option value="pending">Scheduled / Pending</option>
              <option value="attended">Attended</option>
            </select>
          </label>
        </div>
        <div class="eo-dt-right">
          <input type="text" id="eoFacSearchInput" class="eo-search" placeholder="Search ID, Name, Dept, Room...">
        </div>
      </div>

      <div class="eo-table-wrap">
        <table class="eo-table" id="eoFacilitatorDataTable">
          <thead>
            <tr>
              <th class="eo-sortable" data-sort-col="employee_code">Employee ID <span class="eo-sort-ind" id="sort-employee_code">⇅</span></th>
              <th class="eo-sortable" data-sort-col="full_name">New Employee <span class="eo-sort-ind" id="sort-full_name">⇅</span></th>
              <th class="eo-sortable" data-sort-col="department_role">Department / Role <span class="eo-sort-ind" id="sort-department_role">⇅</span></th>
              <th class="eo-sortable" data-sort-col="orientation_at">Scheduled Time <span class="eo-sort-ind" id="sort-orientation_at">⇅</span></th>
              <th class="eo-sortable" data-sort-col="orientation_room">Room <span class="eo-sort-ind" id="sort-orientation_room">⇅</span></th>
              <th class="eo-sortable" data-sort-col="attendance">Attendance <span class="eo-sort-ind" id="sort-attendance">⇅</span></th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="eoFacilitatorTableBody"></tbody>
        </table>
      </div>

      <div class="eo-dt-footer">
        <div id="eoDtInfo">Showing 0 entries</div>
        <div class="eo-dt-pages" id="eoDtPagination"></div>
      </div>
    </div>
  <?php endif; ?>

  <div id="eoContainer"></div>
</div>

<script>
  (function () {
    const hasFacilitatorAccess = <?= $hasFacilitatorAccess ? 'true' : 'false' ?>;
    let empState = <?= json_encode($initial, JSON_UNESCAPED_UNICODE) ?>;
    let facPanelOpen = hasFacilitatorAccess && <?= !empty($initial['facilitatorQueue']) ? 'true' : 'false' ?>;

    let dtSortCol = 'orientation_at';
    let dtSortDir = 'asc';
    let dtPage = 1;

    function esc(v) {
      return String(v ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function showBanner(ok, msg) {
      const el = document.getElementById('eoBanner');
      if (!el || !msg) return;
      el.innerHTML = ok
        ? `<div style="background:#dcfce7; color:#166534; padding:11px 14px; border-radius:10px; margin-bottom:14px; font-size:13.5px;">${esc(msg)}</div>`
        : `<div style="background:#fee2e2; color:#991b1b; padding:11px 14px; border-radius:10px; margin-bottom:14px; font-size:13.5px;">${esc(msg)}</div>`;
    }

    const panel = document.getElementById('eoFacilitatorPanel');
    const toggleBtn = document.getElementById('toggleFacilitatorPanelBtn');
    const closePanelBtn = document.getElementById('closeFacilitatorPanelBtn');

    function syncPanelVisibility() {
      if (panel) panel.classList.toggle('is-open', hasFacilitatorAccess && facPanelOpen);
    }

    if (toggleBtn) {
      toggleBtn.addEventListener('click', () => {
        facPanelOpen = !facPanelOpen;
        syncPanelVisibility();
      });
    }
    if (closePanelBtn) {
      closePanelBtn.addEventListener('click', () => {
        facPanelOpen = false;
        syncPanelVisibility();
      });
    }

    function getSortValue(item, col) {
      switch (col) {
        case 'employee_code': return String(item.employee_code || '').toLowerCase();
        case 'full_name': return String(item.full_name || '').toLowerCase();
        case 'department_role': return `${item.department || ''} ${item.job_title || ''}`.toLowerCase();
        case 'orientation_at': return String(item.orientation_at || '9999-12-31 23:59:59').toLowerCase();
        case 'orientation_room': return String(item.orientation_room || 'zzz').toLowerCase();
        case 'attendance': return Number(item.step_orientation || 0);
        default: return '';
      }
    }

    function updateSortIndicators() {
      document.querySelectorAll('#eoFacilitatorDataTable thead th.eo-sortable').forEach(th => {
        const col = th.getAttribute('data-sort-col');
        const ind = document.getElementById('sort-' + col);
        if (!ind) return;
        if (col === dtSortCol) {
          ind.textContent = dtSortDir === 'asc' ? '▲' : '▼';
          ind.style.color = '#2563eb';
        } else {
          ind.textContent = '⇅';
          ind.style.color = '#64748b';
        }
      });
    }

    document.querySelectorAll('#eoFacilitatorDataTable thead th.eo-sortable').forEach(th => {
      th.addEventListener('click', () => {
        const col = th.getAttribute('data-sort-col');
        if (!col) return;
        if (dtSortCol === col) {
          dtSortDir = dtSortDir === 'asc' ? 'desc' : 'asc';
        } else {
          dtSortCol = col;
          dtSortDir = 'asc';
        }
        dtPage = 1;
        renderFacilitatorDataTable(empState.facilitatorQueue);
      });
    });

    const searchInput = document.getElementById('eoFacSearchInput');
    const pageSizeSelect = document.getElementById('eoDtPageSize');
    const statusFilterSelect = document.getElementById('eoDtStatusFilter');

    if (searchInput) {
      searchInput.addEventListener('input', () => { dtPage = 1; renderFacilitatorDataTable(empState.facilitatorQueue); });
    }
    if (pageSizeSelect) {
      pageSizeSelect.addEventListener('change', () => { dtPage = 1; renderFacilitatorDataTable(empState.facilitatorQueue); });
    }
    if (statusFilterSelect) {
      statusFilterSelect.addEventListener('change', () => { dtPage = 1; renderFacilitatorDataTable(empState.facilitatorQueue); });
    }

    function renderFacilitatorDataTable(queue) {
      if (!hasFacilitatorAccess) return;
      const list = Array.isArray(queue) ? queue : [];
      const badge = document.getElementById('eoFacCountBadge');
      if (badge) badge.textContent = String(list.length);

      updateSortIndicators();

      const q = (searchInput?.value || '').trim().toLowerCase();
      const statusVal = statusFilterSelect?.value || 'all';
      const pageSize = Math.max(1, Number(pageSizeSelect?.value || 5));

      let filtered = list.filter(item => {
        const attended = Number(item.step_orientation || 0) === 1;
        if (statusVal === 'pending' && attended) return false;
        if (statusVal === 'attended' && !attended) return false;
        if (!q) return true;
        const hay = `${item.employee_code || ''} ${item.full_name || ''} ${item.department || ''} ${item.job_title || ''} ${item.orientation_at || ''} ${item.orientation_room || ''} ${attended ? 'attended' : 'scheduled pending'}`.toLowerCase();
        return hay.includes(q);
      });

      filtered.sort((a, b) => {
        const va = getSortValue(a, dtSortCol);
        const vb = getSortValue(b, dtSortCol);
        let cmp = (typeof va === 'number' && typeof vb === 'number')
          ? va - vb
          : String(va).localeCompare(String(vb), undefined, { numeric: true, sensitivity: 'base' });
        return dtSortDir === 'asc' ? cmp : -cmp;
      });

      const totalFiltered = filtered.length;
      const totalPages = Math.max(1, Math.ceil(totalFiltered / pageSize));
      if (dtPage > totalPages) dtPage = totalPages;
      const startIdx = (dtPage - 1) * pageSize;
      const pageRows = filtered.slice(startIdx, startIdx + pageSize);

      const tbody = document.getElementById('eoFacilitatorTableBody');
      if (tbody) {
        if (!pageRows.length) {
          tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:#475569; padding:20px;">No matching scheduled new employees found.</td></tr>`;
        } else {
          tbody.innerHTML = pageRows.map(item => {
            const attended = Number(item.step_orientation || 0) === 1;
            return `
              <tr>
                <td><strong>${esc(item.employee_code)}</strong></td>
                <td><strong>${esc(item.full_name)}</strong></td>
                <td>${esc(item.department)} • ${esc(item.job_title)}</td>
                <td>${esc(item.orientation_at || 'Not scheduled')}</td>
                <td>${esc(item.orientation_room || '—')}</td>
                <td>
                  <span class="${attended ? 'eo-pill-done' : 'eo-pill-wait'}">
                    ${attended ? 'Attended' : 'Scheduled / Pending'}
                  </span>
                </td>
                <td>
                  ${!attended ? `
                    <form method="post" class="eo-fac-action-form" style="display:inline;">
                      <input type="hidden" name="emp_onb_action" value="facilitator_mark_attended">
                      <input type="hidden" name="target_onb_id" value="${Number(item.id)}">
                      <button type="submit" class="eo-btn-mark">Mark Attended</button>
                    </form>
                  ` : `
                    <form method="post" class="eo-fac-action-form" style="display:inline;">
                      <input type="hidden" name="emp_onb_action" value="facilitator_unmark_attended">
                      <input type="hidden" name="target_onb_id" value="${Number(item.id)}">
                      <button type="submit" class="eo-btn-undo">Undo</button>
                    </form>
                  `}
                </td>
              </tr>
            `;
          }).join('');

          tbody.querySelectorAll('form.eo-fac-action-form').forEach(form => {
            if (form.dataset.bound) return;
            form.dataset.bound = '1';
            form.addEventListener('submit', async e => {
              e.preventDefault();
              const fd = new FormData(form);
              fd.append('ajax', '1');
              try {
                const res = await fetch(window.location.pathname + window.location.search, {
                  method: 'POST', body: fd, cache: 'no-store'
                });
                const payload = await res.json();
                showBanner(Boolean(payload.ok), payload.message || '');
                if (payload.ok && payload.state) renderAll(payload.state);
              } catch (_) {
                showBanner(false, 'Network error while updating orientation attendance.');
              }
            });
          });
        }
      }

      const infoEl = document.getElementById('eoDtInfo');
      if (infoEl) {
        if (totalFiltered === 0) {
          infoEl.textContent = `Showing 0 of ${list.length} assigned entries`;
        } else {
          infoEl.textContent = `Showing ${startIdx + 1} to ${Math.min(startIdx + pageSize, totalFiltered)} of ${totalFiltered} filtered (${list.length} total assigned)`;
        }
      }

      const pagesEl = document.getElementById('eoDtPagination');
      if (pagesEl) {
        let html = `<button type="button" class="eo-page-btn" data-page="${dtPage - 1}" ${dtPage <= 1 ? 'disabled' : ''}>Prev</button>`;
        for (let p = 1; p <= totalPages; p++) {
          html += `<button type="button" class="eo-page-btn ${p === dtPage ? 'is-active' : ''}" data-page="${p}">${p}</button>`;
        }
        html += `<button type="button" class="eo-page-btn" data-page="${dtPage + 1}" ${dtPage >= totalPages ? 'disabled' : ''}>Next</button>`;
        pagesEl.innerHTML = html;

        pagesEl.querySelectorAll('button.eo-page-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            const targetP = Number(btn.getAttribute('data-page') || 1);
            if (targetP >= 1 && targetP <= totalPages && targetP !== dtPage) {
              dtPage = targetP;
              renderFacilitatorDataTable(empState.facilitatorQueue);
            }
          });
        });
      }
    }

    function renderAll(data) {
      if (!data || !data.ok) return;
      empState = data;

      if (data.serverTime) {
        const t = document.getElementById('eoLastSync');
        if (t) t.textContent = data.serverTime;
      }

      const queue = Array.isArray(data.facilitatorQueue) ? data.facilitatorQueue : [];
      renderFacilitatorDataTable(queue);

      const container = document.getElementById('eoContainer');
      const onb = data.onb;
      if (!container) return;

      if (!onb) {
        container.innerHTML = queue.length > 0
          ? ''
          : `<div class="eo-card">No personal onboarding record is linked to your account yet. Use <strong>Facilitator Check-In</strong> above if you are assigned to facilitate orientation for new employees.</div>`;
        return;
      }

      const doneCount = Number(onb.step_info || 0)
        + Number(onb.step_documents || 0)
        + Number(onb.step_orientation || 0)
        + Number(onb.step_policy || 0);
      const oriDone = Number(onb.step_orientation || 0) === 1;
      const polDone = Number(onb.step_policy || 0) === 1;
      const policyLocked = !oriDone && !polDone;

      container.innerHTML = `
        <div class="eo-hero">
          <div>
            <h1 style="margin:0 0 4px; font-size:24px; color:#0f172a;">Welcome, ${esc(onb.full_name)}!</h1>
            <div style="font-size:13.5px; color:#475569;">
              ${esc(onb.employee_code)} • ${esc(onb.job_title)} (${esc(onb.department)})
            </div>
          </div>
          <span class="${doneCount === 4 ? 'eo-pill-done' : 'eo-pill-wait'}">
            ${esc(onb.status)} (${doneCount}/4 Steps)
          </span>
        </div>

        <div class="eo-grid">
          <div class="eo-card">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
              <h2 style="margin:0; font-size:18px; color:#0f172a;">1. Company Orientation</h2>
              <span class="${oriDone ? 'eo-pill-done' : 'eo-pill-wait'}">
                ${oriDone ? 'Completed' : 'Scheduled / Pending'}
              </span>
            </div>
            <p style="color:#475569; font-size:13.5px; margin:8px 0 0;">
              Proceed to the orientation room at your scheduled time. Your assigned HR / Facilitator will verify your Name or Employee ID (<strong>${esc(onb.employee_code)}</strong>) at the entrance and mark your attendance.
            </p>

            <div class="eo-info-box">
              <div><strong>Schedule:</strong> ${esc(onb.orientation_at || 'To be scheduled by HR')}</div>
              <div><strong>Room / Venue:</strong> ${esc(onb.orientation_room || 'To be announced by HR')}</div>
              <div><strong>HR / Facilitator:</strong> ${esc(onb.facilitator_name || 'To be assigned')}</div>
              <div><strong>Team Buddy:</strong> ${esc(onb.buddy_name || 'To be assigned')}</div>
            </div>

            ${oriDone ? `
              <div class="eo-attended-banner">
                Orientation Verified &amp; Attended ✓
                ${onb.checked_in_by ? `
                  <div style="font-size:11.5px; font-weight:500; margin-top:2px;">
                    Checked in by ${esc(onb.checked_in_by)}${onb.orientation_attended_at ? ` on ${esc(onb.orientation_attended_at)}` : ''}
                  </div>
                ` : ''}
              </div>
            ` : `
              <div class="eo-readonly-banner">
                Attendance check-in is locked to your HR / Facilitator. Present your Employee ID (<strong>${esc(onb.employee_code)}</strong>) at the orientation room. Time In / Time Out and Policy Acknowledgement unlock once marked Present.
              </div>
            `}
          </div>

          <div class="eo-card">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
              <h2 style="margin:0; font-size:18px; color:#0f172a;">2. Policy Acknowledgement</h2>
              <span class="${polDone ? 'eo-pill-done' : (policyLocked ? 'eo-pill-lock' : 'eo-pill-wait')}">
                ${polDone ? 'Acknowledged' : (policyLocked ? 'Locked Until Present' : 'Action Required')}
              </span>
            </div>
            <p style="color:#475569; font-size:13.5px; margin:8px 0 14px;">
              ${polDone
                ? 'Your policy acknowledgement has been submitted and permanently locked.'
                : 'Please review and check each company policy below to submit your digital acknowledgement to HR.'}
            </p>

            ${policyLocked ? `
              <div class="eo-lock-banner">
                Locked: HR or your assigned facilitator (<strong>${esc(onb.facilitator_name || 'HR Facilitator')}</strong>) has not marked your orientation attendance as Present yet. This unlocks automatically in real time once checked in.
              </div>
            ` : ''}

            <form method="post" id="empPolicyForm">
              <input type="hidden" name="emp_onb_action" value="acknowledge_policies">

              <label class="eo-pol-item ${polDone ? 'is-done' : (policyLocked ? 'is-locked' : '')}">
                <input type="checkbox" class="eo-pol-checkbox" name="pol_handbook" value="1" ${polDone ? 'checked disabled' : (policyLocked ? 'disabled' : 'required')}>
                <div>
                  <strong>Employee Handbook</strong>
                  <div style="font-size:12px; color:#475569;">Core workplace guidelines, benefits, and company values.</div>
                </div>
              </label>

              <label class="eo-pol-item ${polDone ? 'is-done' : (policyLocked ? 'is-locked' : '')}">
                <input type="checkbox" class="eo-pol-checkbox" name="pol_conduct" value="1" ${polDone ? 'checked disabled' : (policyLocked ? 'disabled' : 'required')}>
                <div>
                  <strong>Code of Conduct &amp; Attendance Policy</strong>
                  <div style="font-size:12px; color:#475569;">Work hours, leave filing rules, and professional ethics.</div>
                </div>
              </label>

              <label class="eo-pol-item ${polDone ? 'is-done' : (policyLocked ? 'is-locked' : '')}">
                <input type="checkbox" class="eo-pol-checkbox" name="pol_privacy" value="1" ${polDone ? 'checked disabled' : (policyLocked ? 'disabled' : 'required')}>
                <div>
                  <strong>Data Privacy &amp; Confidentiality Agreement</strong>
                  <div style="font-size:12px; color:#475569;">Protection of company, client, and employee information.</div>
                </div>
              </label>

              <button type="submit" class="eo-btn-submit ${polDone ? 'is-done' : ''}" ${(polDone || policyLocked) ? 'disabled' : ''}>
                ${polDone ? 'Policies Acknowledged ✓' : (policyLocked ? 'Locked — Awaiting Orientation Check-In' : 'Acknowledge & Submit Policies')}
              </button>

              ${polDone ? `
                <div class="eo-pol-note">
                  Permanent Sign-Off Recorded${onb.policy_acknowledged_at ? ` on ${esc(onb.policy_acknowledged_at)}` : ''} • Cannot be undone
                </div>
              ` : ''}
            </form>
          </div>
        </div>
      `;

      const form = document.getElementById('empPolicyForm');
      if (form && !polDone && !policyLocked) {
        form.addEventListener('submit', async e => {
          e.preventDefault();
          const fd = new FormData(form);
          fd.append('ajax', '1');
          try {
            const res = await fetch(window.location.pathname + window.location.search, {
              method: 'POST', body: fd, cache: 'no-store'
            });
            const payload = await res.json();
            showBanner(Boolean(payload.ok), payload.message || '');
            if (payload.state) renderAll(payload.state);
          } catch (_) {
            showBanner(false, 'Network error while submitting policy acknowledgement.');
          }
        });
      }
    }

    syncPanelVisibility();
    renderAll(empState);

    async function pollEmployeeLive() {
      try {
        const res = await fetch(`?api=live_state&_=${Date.now()}`, { cache: 'no-store' });
        if (!res.ok) return;
        const next = await res.json();
        if (next && next.ok) {
          if (next.signature && next.signature !== empState.signature) {
            renderAll(next);
          } else if (next.serverTime) {
            const t = document.getElementById('eoLastSync');
            if (t) t.textContent = next.serverTime;
          }
        }
      } catch (_) {}
    }

    (function connectEmployeeRealtimeWS() {
      const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      const wsUrl = `${proto}//${window.location.hostname}:8090/ws`;
      let ws;
      try {
        ws = new WebSocket(wsUrl);
        ws.onmessage = (ev) => {
          try {
            const msg = JSON.parse(ev.data);
            if (!msg.channel || msg.channel === 'onboarding' || msg.event === 'onboarding_updated') {
              pollEmployeeLive();
            }
          } catch (_) {
            pollEmployeeLive();
          }
        };
        ws.onclose = () => setTimeout(connectEmployeeRealtimeWS, 3000);
      } catch (_) {}
    })();

    setInterval(() => { if (!document.hidden) pollEmployeeLive(); }, 1500);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) pollEmployeeLive();
    });
  })();
</script>