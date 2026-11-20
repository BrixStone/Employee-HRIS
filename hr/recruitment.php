<?php
// hr/recruitment.php

require_once __DIR__ . '/../authentication/guard.php';
require_access('employee_records', 'view', 'all');

require_once __DIR__ . '/../config/database.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `recruitment_jobs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `job_title` VARCHAR(150) NOT NULL,
            `department` VARCHAR(120) NOT NULL,
            `location` VARCHAR(120) NOT NULL DEFAULT 'On-site',
            `employment_type` VARCHAR(60) NOT NULL DEFAULT 'Full-time',
            `slots_needed` INT UNSIGNED NOT NULL DEFAULT 1,
            `status` ENUM('Open', 'Closed') NOT NULL DEFAULT 'Open',
            `description` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $colCheck = $pdo->query("SHOW COLUMNS FROM `recruitment_jobs` LIKE 'slots_needed'")->fetch();
    if (!$colCheck) {
        $pdo->exec("ALTER TABLE `recruitment_jobs` ADD COLUMN `slots_needed` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `employment_type`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `recruitment_applications` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `job_id` INT UNSIGNED NOT NULL,
            `applicant_name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(150) NOT NULL,
            `phone` VARCHAR(50) NULL,
            `stage` ENUM('Applicant', 'Interview', 'Hired', 'Rejected') NOT NULL DEFAULT 'Applicant',
            `interview_date` DATETIME NULL,
            `interviewer` VARCHAR(120) NULL,
            `notes` TEXT NULL,
            `onboarded_to_employee` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_job_id` (`job_id`),
            CONSTRAINT `fk_rec_app_job` FOREIGN KEY (`job_id`) REFERENCES `recruitment_jobs` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $appColCheck = $pdo->query("SHOW COLUMNS FROM `recruitment_applications` LIKE 'onboarded_to_employee'")->fetch();
    if (!$appColCheck) {
        $pdo->exec("ALTER TABLE `recruitment_applications` ADD COLUMN `onboarded_to_employee` TINYINT(1) NOT NULL DEFAULT 0 AFTER `notes`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `employee_onboarding` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `application_id` INT UNSIGNED NULL,
            `employee_code` VARCHAR(40) NOT NULL,
            `full_name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(150) NULL,
            `job_title` VARCHAR(150) NOT NULL,
            `department` VARCHAR(120) NOT NULL,
            `step_info` TINYINT(1) NOT NULL DEFAULT 0,
            `step_documents` TINYINT(1) NOT NULL DEFAULT 0,
            `step_orientation` TINYINT(1) NOT NULL DEFAULT 0,
            `step_policy` TINYINT(1) NOT NULL DEFAULT 0,
            `status` ENUM('In Progress', 'Completed') NOT NULL DEFAULT 'In Progress',
            `completed_at` DATETIME NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_application_id` (`application_id`),
            CONSTRAINT `fk_onb_app` FOREIGN KEY (`application_id`) REFERENCES `recruitment_applications` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    error_log('Recruitment init error: ' . $e->getMessage());
}

function hris_sync_job_vacancy_status(PDO $pdo, int $jobId): void
{
    if ($jobId <= 0) return;

    $stmt = $pdo->prepare("
        SELECT
            j.slots_needed,
            (SELECT COUNT(*) FROM `recruitment_applications` a WHERE a.job_id = j.id AND a.stage = 'Hired') AS hired_count
        FROM `recruitment_jobs` j
        WHERE j.id = ?
        LIMIT 1
    ");
    $stmt->execute([$jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;

    $needed    = max(1, (int)$row['slots_needed']);
    $hired     = (int)$row['hired_count'];
    $remaining = max(0, $needed - $hired);
    $newStatus = $remaining === 0 ? 'Closed' : 'Open';

    $pdo->prepare("UPDATE `recruitment_jobs` SET `status` = ? WHERE `id` = ?")->execute([$newStatus, $jobId]);
}

function hris_ensure_onboarding_row(PDO $pdo, int $appId, string $fullName, string $email, string $jobTitle, string $department, bool $personalInfoDone = false): void
{
    if ($appId <= 0) return;

    $chk = $pdo->prepare("SELECT `id`, `step_info` FROM `employee_onboarding` WHERE `application_id` = ? LIMIT 1");
    $chk->execute([$appId]);
    $existing = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        $empCode = 'EMP-OB-' . str_pad((string)$appId, 3, '0', STR_PAD_LEFT);
        $pdo->prepare("
            INSERT INTO `employee_onboarding`
                (`application_id`, `employee_code`, `full_name`, `email`, `job_title`, `department`,
                 `step_info`, `step_documents`, `step_orientation`, `step_policy`, `status`)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 'In Progress')
        ")->execute([
            $appId,
            $empCode,
            $fullName,
            $email,
            $jobTitle,
            $department,
            $personalInfoDone ? 1 : 0,
        ]);
    } elseif ($personalInfoDone && (int)$existing['step_info'] !== 1) {
        $pdo->prepare("UPDATE `employee_onboarding` SET `step_info` = 1 WHERE `id` = ?")->execute([(int)$existing['id']]);
    }
}

function hris_get_recruitment_state(PDO $pdo): array
{
    $jobs = $pdo->query("
        SELECT
            j.*,
            COALESCE(h.hired_count, 0) AS hired_count,
            GREATEST(0, CAST(j.slots_needed AS SIGNED) - CAST(COALESCE(h.hired_count, 0) AS SIGNED)) AS remaining_needed
        FROM `recruitment_jobs` j
        LEFT JOIN (
            SELECT `job_id`, COUNT(*) AS hired_count
            FROM `recruitment_applications`
            WHERE `stage` = 'Hired'
            GROUP BY `job_id`
        ) h ON h.job_id = j.id
        ORDER BY j.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $applications = $pdo->query("
        SELECT a.*, j.job_title, j.department
        FROM `recruitment_applications` a
        INNER JOIN `recruitment_jobs` j ON j.id = a.job_id
        WHERE COALESCE(a.onboarded_to_employee, 0) = 0
        ORDER BY a.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $applicants = array_values(array_filter($applications, static fn($r) => ($r['stage'] ?? '') === 'Applicant'));
    $interviews = array_values(array_filter($applications, static fn($r) => ($r['stage'] ?? '') === 'Interview'));
    $hired      = array_values(array_filter($applications, static fn($r) => ($r['stage'] ?? '') === 'Hired'));

    $signature = md5(json_encode([
        'jobs'         => $jobs,
        'applications' => $applications,
    ], JSON_UNESCAPED_UNICODE));

    return [
        'ok'           => true,
        'signature'    => $signature,
        'serverTime'   => date('h:i:s A'),
        'counts'       => [
            'jobs'       => count($jobs),
            'applicants' => count($applicants),
            'interviews' => count($interviews),
            'hiring'     => count($hired),
        ],
        'jobs'         => $jobs,
        'applications' => $applications,
        'applicants'   => $applicants,
        'interviews'   => $interviews,
        'hired'        => $hired,
    ];
}

function hris_process_recruitment_action(PDO $pdo, array $post): array
{
    $action = (string)($post['rec_action'] ?? '');

    if ($action === 'save_job') {
        $jobId       = (int)($post['job_id'] ?? 0);
        $jobTitle    = trim((string)($post['job_title'] ?? ''));
        $department  = trim((string)($post['department'] ?? ''));
        $location    = trim((string)($post['location'] ?? 'On-site'));
        $empType     = trim((string)($post['employment_type'] ?? 'Full-time'));
        $slotsNeeded = max(1, (int)($post['slots_needed'] ?? 1));
        $status      = in_array($post['status'] ?? 'Open', ['Open', 'Closed'], true) ? $post['status'] : 'Open';
        $desc        = trim((string)($post['description'] ?? ''));

        if ($jobTitle === '' || $department === '') {
            return ['ok' => false, 'message' => 'Job Title and Department are required.'];
        }
        if ($jobId > 0) {
            $stmt = $pdo->prepare("
                UPDATE `recruitment_jobs`
                SET `job_title` = ?, `department` = ?, `location` = ?, `employment_type` = ?, `slots_needed` = ?, `status` = ?, `description` = ?
                WHERE `id` = ?
            ");
            $stmt->execute([$jobTitle, $department, $location, $empType, $slotsNeeded, $status, $desc, $jobId]);
            hris_sync_job_vacancy_status($pdo, $jobId);
            return ['ok' => true, 'message' => 'Job vacancy updated.'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO `recruitment_jobs` (`job_title`, `department`, `location`, `employment_type`, `slots_needed`, `status`, `description`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$jobTitle, $department, $location, $empType, $slotsNeeded, $status, $desc]);
        return ['ok' => true, 'message' => 'New job vacancy posted.'];
    }

    if ($action === 'delete_job') {
        $jobId = (int)($post['job_id'] ?? 0);
        if ($jobId > 0) {
            $pdo->prepare("DELETE FROM `recruitment_jobs` WHERE `id` = ?")->execute([$jobId]);
            return ['ok' => true, 'message' => 'Job vacancy removed.'];
        }
        return ['ok' => false, 'message' => 'Invalid job ID.'];
    }

    if ($action === 'add_applicant') {
        $jobId         = (int)($post['job_id'] ?? 0);
        $applicantName = trim((string)($post['applicant_name'] ?? ''));
        $email         = trim((string)($post['email'] ?? ''));
        $phone         = trim((string)($post['phone'] ?? ''));
        $stage         = in_array($post['stage'] ?? 'Applicant', ['Applicant', 'Interview', 'Hired', 'Rejected'], true) ? $post['stage'] : 'Applicant';
        $interviewRaw  = trim((string)($post['interview_date'] ?? ''));
        $interviewDate = $interviewRaw !== '' ? str_replace('T', ' ', $interviewRaw) . ':00' : null;
        $interviewer   = trim((string)($post['interviewer'] ?? ''));
        $notes         = trim((string)($post['notes'] ?? ''));

        if ($jobId <= 0 || $applicantName === '' || $email === '') {
            return ['ok' => false, 'message' => 'Vacancy, applicant name, and email are required.'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO `recruitment_applications`
                (`job_id`, `applicant_name`, `email`, `phone`, `stage`, `interview_date`, `interviewer`, `notes`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $jobId,
            $applicantName,
            $email,
            $phone !== '' ? $phone : null,
            $stage,
            $interviewDate,
            $interviewer !== '' ? $interviewer : null,
            $notes !== '' ? $notes : null,
        ]);
        $newAppId = (int)$pdo->lastInsertId();
        hris_sync_job_vacancy_status($pdo, $jobId);

        if ($stage === 'Hired') {
            $jStmt = $pdo->prepare("SELECT `job_title`, `department` FROM `recruitment_jobs` WHERE `id` = ? LIMIT 1");
            $jStmt->execute([$jobId]);
            $jRow = $jStmt->fetch(PDO::FETCH_ASSOC) ?: ['job_title' => 'Employee', 'department' => 'General'];
            hris_ensure_onboarding_row($pdo, $newAppId, $applicantName, $email, (string)$jRow['job_title'], (string)$jRow['department'], false);
        }

        return [
            'ok'      => true,
            'message' => $stage === 'Hired'
                ? 'Candidate accepted! Click "Onboard in Employees" in the Hiring tab to add them to Employees.'
                : 'Applicant added to recruitment pipeline.',
        ];
    }

    if ($action === 'update_stage') {
        $appId        = (int)($post['app_id'] ?? 0);
        $stage        = in_array($post['stage'] ?? 'Applicant', ['Applicant', 'Interview', 'Hired', 'Rejected'], true) ? $post['stage'] : 'Applicant';
        $interviewRaw = trim((string)($post['interview_date'] ?? ''));
        $interviewDt  = $interviewRaw !== '' ? str_replace('T', ' ', $interviewRaw) . ':00' : null;
        $interviewer  = trim((string)($post['interviewer'] ?? ''));

        if ($appId <= 0) {
            return ['ok' => false, 'message' => 'Invalid candidate.'];
        }

        $lookup = $pdo->prepare("
            SELECT a.job_id, a.applicant_name, a.email, j.job_title, j.department
            FROM `recruitment_applications` a
            INNER JOIN `recruitment_jobs` j ON j.id = a.job_id
            WHERE a.id = ?
            LIMIT 1
        ");
        $lookup->execute([$appId]);
        $appRow = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$appRow) {
            return ['ok' => false, 'message' => 'Candidate not found.'];
        }

        $pdo->prepare("
            UPDATE `recruitment_applications`
            SET `stage` = ?, `interview_date` = ?, `interviewer` = ?
            WHERE `id` = ?
        ")->execute([
            $stage,
            $interviewDt,
            $interviewer !== '' ? $interviewer : null,
            $appId,
        ]);

        $linkedJobId = (int)$appRow['job_id'];
        hris_sync_job_vacancy_status($pdo, $linkedJobId);

        if ($stage === 'Hired') {
            hris_ensure_onboarding_row(
                $pdo,
                $appId,
                (string)$appRow['applicant_name'],
                (string)$appRow['email'],
                (string)$appRow['job_title'],
                (string)$appRow['department'],
                false
            );
        }

        return [
            'ok'      => true,
            'message' => $stage === 'Hired'
                ? 'Candidate accepted! Open the Hiring tab and click "Onboard in Employees".'
                : 'Applicant stage updated to ' . $stage . '.',
        ];
    }

    return ['ok' => false, 'message' => 'Unknown action.'];
}

if (isset($_GET['api']) && $_GET['api'] === 'live_state') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(hris_get_recruitment_state($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_POST['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    try {
        $result = hris_process_recruitment_action($pdo, $_POST);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            $state = hris_get_recruitment_state($pdo);
            echo json_encode([
                'ok'      => (bool)$result['ok'],
                'message' => (string)$result['message'],
                'state'   => $state,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($result['ok']) {
            $flashSuccess = (string)$result['message'];
        } else {
            $flashError = (string)$result['message'];
        }
    } catch (Throwable $e) {
        error_log('Recruitment action failed: ' . $e->getMessage());
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Could not complete the request.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $flashError = 'Could not complete the request. Please check your input.';
    }
}

$activeTab = $_GET['tab'] ?? 'vacancies';
if (!in_array($activeTab, ['vacancies', 'applicants', 'interviews', 'hiring'], true)) {
    $activeTab = 'vacancies';
}

$initialState   = hris_get_recruitment_state($pdo);
$jobs           = $initialState['jobs'];
$applications   = $initialState['applications'];
$applicantsList = $initialState['applicants'];
$interviewsList = $initialState['interviews'];
$hiredList      = $initialState['hired'];

require_once __DIR__ . '/../templates/hrTemplates/header.php';
?>

<style>
  .rec-page { padding: 20px 24px 40px; color: #e2e8f0; }
  .rec-header-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
  .rec-live-pill {
    display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px;
    font-size: 12px; font-weight: 600; background: rgba(16, 185, 129, 0.14);
    border: 1px solid rgba(52, 211, 153, 0.35); color: #6ee7b7;
  }
  .rec-live-dot {
    width: 8px; height: 8px; border-radius: 50%; background: #34d399;
    box-shadow: 0 0 8px #34d399; animation: recPulse 1.6s infinite;
  }
  @keyframes recPulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.45; transform: scale(0.85); }
  }
  .rec-top-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 18px; }
  .rec-tabs {
    display: inline-flex; flex-wrap: wrap; gap: 6px; background: rgba(15, 23, 42, 0.75);
    border: 1px solid rgba(148, 163, 184, 0.2); border-radius: 12px; padding: 5px;
  }
  .rec-tab-btn {
    border: none; background: transparent; color: #94a3b8; font-size: 13.5px; font-weight: 600;
    padding: 8px 16px; border-radius: 8px; cursor: pointer; transition: all 0.15s ease;
  }
  .rec-tab-btn:hover { color: #f8fafc; background: rgba(59, 130, 246, 0.14); }
  .rec-tab-btn.is-active { background: #2563eb; color: #ffffff; }
  .rec-btn-primary {
    border: none; background: #2563eb; color: #ffffff; font-size: 13.5px; font-weight: 700;
    padding: 10px 18px; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none;
  }
  .rec-btn-primary:hover { background: #1d4ed8; }
  .rec-card { background: rgba(15, 23, 42, 0.72); border: 1px solid rgba(148, 163, 184, 0.2); border-radius: 14px; overflow: hidden; }
  .rec-table { width: 100%; border-collapse: collapse; font-size: 14px; }
  .rec-table th {
    text-align: left; padding: 14px 18px; font-size: 12px; text-transform: uppercase;
    letter-spacing: 0.04em; color: #94a3b8; border-bottom: 1px solid rgba(148, 163, 184, 0.16);
    background: rgba(30, 41, 59, 0.45);
  }
  .rec-table td { padding: 14px 18px; border-bottom: 1px solid rgba(148, 163, 184, 0.1); vertical-align: middle; }
  .rec-table tr:last-child td { border-bottom: none; }
  .rec-badge { display: inline-block; padding: 4px 12px; border-radius: 8px; font-size: 12px; font-weight: 700; }
  .rec-badge-open { background: rgba(34, 197, 94, 0.16); color: #4ade80; border: 1px solid rgba(74, 222, 128, 0.35); }
  .rec-badge-closed { background: rgba(245, 158, 11, 0.16); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.35); }
  .rec-badge-info { background: rgba(59, 130, 246, 0.16); color: #60a5fa; border: 1px solid rgba(96, 165, 250, 0.35); }
  .rec-slot-pill {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 999px;
    font-size: 12.5px; font-weight: 700; background: rgba(99, 102, 241, 0.16);
    color: #c7d2fe; border: 1px solid rgba(129, 140, 248, 0.38);
  }
  .rec-slot-pill.is-full { background: rgba(148, 163, 184, 0.15); color: #94a3b8; border-color: rgba(148, 163, 184, 0.3); }
  .rec-slot-sub { display: block; margin-top: 3px; font-size: 11.5px; color: #94a3b8; }
  .rec-actions { display: inline-flex; align-items: center; gap: 10px; }
  .rec-icon-btn {
    display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
    background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(148, 163, 184, 0.22);
    color: #e2e8f0; border-radius: 8px; padding: 6px 10px; cursor: pointer; font-size: 13px;
  }
  .rec-icon-btn:hover { border-color: #60a5fa; color: #93c5fd; }
  .rec-icon-btn.danger:hover { border-color: #f87171; color: #fca5a5; }
  .rec-panel-pane { display: none; }
  .rec-panel-pane.is-active { display: block; }
  .rec-alert-ok {
    padding: 11px 14px; border-radius: 10px; background: rgba(34, 197, 94, 0.15);
    border: 1px solid rgba(74, 222, 128, 0.4); color: #bbf7d0; margin-bottom: 14px; font-size: 13.5px;
  }
  .rec-alert-err {
    padding: 11px 14px; border-radius: 10px; background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; margin-bottom: 14px; font-size: 13.5px;
  }
  .rec-modal-bg {
    display: none; position: fixed; inset: 0; background: rgba(2, 6, 23, 0.78);
    z-index: 9999; align-items: center; justify-content: center; padding: 16px;
  }
  .rec-modal-bg.is-open { display: flex; }
  .rec-modal-box {
    width: 100%; max-width: 520px; background: #0f172a;
    border: 1px solid rgba(148, 163, 184, 0.25); border-radius: 14px; padding: 20px 22px; color: #f8fafc;
  }
  .rec-form-row { margin-bottom: 12px; }
  .rec-form-row label { display: block; font-size: 12px; color: #94a3b8; margin-bottom: 5px; }
  .rec-form-row input, .rec-form-row select, .rec-form-row textarea {
    width: 100%; box-sizing: border-box; padding: 9px 11px; border-radius: 8px;
    border: 1px solid rgba(148, 163, 184, 0.28); background: #1e293b; color: #f8fafc; font-size: 13.5px;
  }
  .rec-modal-foot { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; }
  .rec-btn-cancel {
    background: transparent; border: 1px solid rgba(148, 163, 184, 0.3);
    color: #cbd5e1; padding: 8px 14px; border-radius: 8px; cursor: pointer;
  }
  .rec-subbar {
    display: flex; justify-content: space-between; align-items: center; padding: 12px 18px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.14); background: rgba(30, 41, 59, 0.3);
  }
</style>

<div class="rec-page">
  <div class="rec-header-row">
    <div>
      <h1 style="margin: 0 0 4px; font-size: 24px;">Recruitment</h1>
      <p style="margin: 0; color: #94a3b8; font-size: 13.5px;">Live recruitment pipeline for job vacancies, applicants, interviews, and hiring.</p>
    </div>
    <div class="rec-live-pill" id="recLiveStatus">
      <span class="rec-live-dot"></span>
      <span>Live Sync • <strong id="recLastSync"><?= htmlspecialchars($initialState['serverTime'], ENT_QUOTES, 'UTF-8') ?></strong></span>
    </div>
  </div>

  <div id="recLiveBanner">
    <?php if ($flashSuccess !== ''): ?>
      <div class="rec-alert-ok"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
      <div class="rec-alert-err"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
  </div>

  <div class="rec-top-bar">
    <div class="rec-tabs" role="tablist">
      <button type="button" class="rec-tab-btn <?= $activeTab === 'vacancies' ? 'is-active' : '' ?>" data-tab="vacancies">Job Vacancies</button>
      <button type="button" class="rec-tab-btn <?= $activeTab === 'applicants' ? 'is-active' : '' ?>" id="tabBtnApplicants" data-tab="applicants">Applicants (<?= count($applicantsList) ?>)</button>
      <button type="button" class="rec-tab-btn <?= $activeTab === 'interviews' ? 'is-active' : '' ?>" id="tabBtnInterviews" data-tab="interviews">Interviews (<?= count($interviewsList) ?>)</button>
      <button type="button" class="rec-tab-btn <?= $activeTab === 'hiring' ? 'is-active' : '' ?>" id="tabBtnHiring" data-tab="hiring">Hiring (<?= count($hiredList) ?>)</button>
    </div>

    <button type="button" class="rec-btn-primary" id="openPostJobBtn">+ Post Job</button>
  </div>

  <!-- TAB 1: JOB VACANCIES -->
  <div class="rec-panel-pane <?= $activeTab === 'vacancies' ? 'is-active' : '' ?>" id="pane-vacancies">
    <div class="rec-card">
      <table class="rec-table">
        <thead>
          <tr>
            <th>Job Title</th>
            <th>Department</th>
            <th>Needed Indicator</th>
            <th>Status</th>
            <th style="width: 130px;">Action</th>
          </tr>
        </thead>
        <tbody id="vacanciesTableBody">
          <?php if (empty($jobs)): ?>
            <tr><td colspan="5" style="text-align:center; color:#94a3b8;">No job vacancies posted yet.</td></tr>
          <?php else: ?>
            <?php foreach ($jobs as $job): ?>
              <?php
              $needed    = max(1, (int)($job['slots_needed'] ?? 1));
              $hired     = (int)($job['hired_count'] ?? 0);
              $remaining = max(0, (int)($job['remaining_needed'] ?? ($needed - $hired)));
              $isOpen    = ($job['status'] ?? 'Open') === 'Open' && $remaining > 0;
              ?>
              <tr>
                <td><strong><?= htmlspecialchars($job['job_title'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><?= htmlspecialchars($job['department'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <?php if ($remaining > 0): ?>
                    <span class="rec-slot-pill"><?= $remaining ?> needed</span>
                    <span class="rec-slot-sub"><?= $hired ?> of <?= $needed ?> approved</span>
                  <?php else: ?>
                    <span class="rec-slot-pill is-full">0 needed (Filled)</span>
                    <span class="rec-slot-sub"><?= $hired ?> of <?= $needed ?> approved</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="rec-badge <?= $isOpen ? 'rec-badge-open' : 'rec-badge-closed' ?>">
                    <?= $isOpen ? 'Open' : 'Closed' ?>
                  </span>
                </td>
                <td>
                  <div class="rec-actions">
                    <button
                      type="button"
                      class="rec-icon-btn edit-job-btn"
                      title="Edit Job"
                      data-job='<?= htmlspecialchars(json_encode($job, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'
                    >✎</button>
                    <form method="post" action="" class="rec-ajax-form" style="display:inline;" data-confirm="Delete this job vacancy?">
                      <input type="hidden" name="rec_action" value="delete_job">
                      <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                      <button type="submit" class="rec-icon-btn danger" title="Delete Job">🗑</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- TAB 2: APPLICANTS -->
  <div class="rec-panel-pane <?= $activeTab === 'applicants' ? 'is-active' : '' ?>" id="pane-applicants">
    <div class="rec-card">
      <div class="rec-subbar">
        <strong>Candidate Applications</strong>
        <button type="button" class="rec-btn-primary" id="openAddApplicantBtn">+ Add Applicant</button>
      </div>
      <table class="rec-table">
        <thead>
          <tr>
            <th>Applicant</th>
            <th>Job Title</th>
            <th>Department</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="applicantsTableBody">
          <?php if (empty($applications)): ?>
            <tr><td colspan="5" style="text-align:center; color:#94a3b8;">No applicants recorded yet.</td></tr>
          <?php else: ?>
            <?php foreach ($applications as $app): ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($app['applicant_name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                  <span style="font-size:12px; color:#94a3b8;"><?= htmlspecialchars($app['email'], ENT_QUOTES, 'UTF-8') ?></span>
                </td>
                <td><?= htmlspecialchars($app['job_title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($app['department'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="rec-badge rec-badge-info"><?= htmlspecialchars($app['stage'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td>
                  <form method="post" action="" class="rec-ajax-form" style="display:inline-flex; gap:6px; align-items:center;">
                    <input type="hidden" name="rec_action" value="update_stage">
                    <input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>">
                    <input type="hidden" name="interview_date" value="<?= $app['interview_date'] ? htmlspecialchars(substr($app['interview_date'], 0, 16), ENT_QUOTES, 'UTF-8') : '' ?>">
                    <input type="hidden" name="interviewer" value="<?= htmlspecialchars($app['interviewer'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <select name="stage" style="padding:5px 8px; border-radius:6px; background:#1e293b; color:#f8fafc; border:1px solid rgba(148,163,184,0.3);">
                      <?php foreach (['Applicant', 'Interview', 'Hired', 'Rejected'] as $st): ?>
                        <option value="<?= $st ?>" <?= $app['stage'] === $st ? 'selected' : '' ?>><?= $st === 'Hired' ? 'Hired (Approve)' : $st ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="rec-icon-btn">Save</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- TAB 3: INTERVIEWS -->
  <div class="rec-panel-pane <?= $activeTab === 'interviews' ? 'is-active' : '' ?>" id="pane-interviews">
    <div class="rec-card">
      <table class="rec-table">
        <thead>
          <tr>
            <th>Candidate</th>
            <th>Job Title</th>
            <th>Department</th>
            <th>Interview Schedule</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="interviewsTableBody">
          <?php if (empty($interviewsList)): ?>
            <tr><td colspan="5" style="text-align:center; color:#94a3b8;">No interviews scheduled yet.</td></tr>
          <?php else: ?>
            <?php foreach ($interviewsList as $iv): ?>
              <tr>
                <td><strong><?= htmlspecialchars($iv['applicant_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><?= htmlspecialchars($iv['job_title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($iv['department'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($iv['interview_date'] ?: 'TBD', ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <form method="post" action="" class="rec-ajax-form" style="display:inline;">
                    <input type="hidden" name="rec_action" value="update_stage">
                    <input type="hidden" name="app_id" value="<?= (int)$iv['id'] ?>">
                    <input type="hidden" name="stage" value="Hired">
                    <button type="submit" class="rec-btn-primary" style="padding:6px 12px; font-size:12px;">Approve &amp; Hire</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- TAB 4: HIRING -->
  <div class="rec-panel-pane <?= $activeTab === 'hiring' ? 'is-active' : '' ?>" id="pane-hiring">
    <div class="rec-card">
      <table class="rec-table">
        <thead>
          <tr>
            <th>Hired Candidate</th>
            <th>Job Title</th>
            <th>Department</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="hiringTableBody">
          <?php if (empty($hiredList)): ?>
            <tr><td colspan="5" style="text-align:center; color:#94a3b8;">No pending hired candidates to onboard.</td></tr>
          <?php else: ?>
            <?php foreach ($hiredList as $hd): ?>
              <?php
              $addEmpUrl = '/hr/add_employee.php?' . http_build_query([
                  'app_id'     => (int)$hd['id'],
                  'full_name'  => (string)$hd['applicant_name'],
                  'email'      => (string)$hd['email'],
                  'phone'      => (string)($hd['phone'] ?? ''),
                  'department' => (string)$hd['department'],
                  'job_title'  => (string)$hd['job_title'],
              ]);
              ?>
              <tr>
                <td><strong><?= htmlspecialchars($hd['applicant_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><?= htmlspecialchars($hd['job_title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($hd['department'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="rec-badge rec-badge-open">Approved / Hired</span></td>
                <td>
                  <a href="<?= htmlspecialchars($addEmpUrl, ENT_QUOTES, 'UTF-8') ?>" class="rec-icon-btn">Onboard in Employees</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Post / Edit Job Modal -->
<div class="rec-modal-bg" id="jobModal">
  <div class="rec-modal-box">
    <h3 id="jobModalTitle" style="margin:0 0 14px;">Post New Job</h3>
    <form method="post" action="" class="rec-ajax-form" data-close-modal="jobModal">
      <input type="hidden" name="rec_action" value="save_job">
      <input type="hidden" name="job_id" id="jobIdInput" value="0">
      <div class="rec-form-row">
        <label for="jobTitleInput">Job Title</label>
        <input type="text" id="jobTitleInput" name="job_title" required placeholder="e.g. Software Developer">
      </div>
      <div class="rec-form-row">
        <label for="jobDeptInput">Department</label>
        <input type="text" id="jobDeptInput" name="department" required placeholder="e.g. IT">
      </div>
      <div class="rec-form-row">
        <label for="jobSlotsInput">How Many Needed (Headcount Slots)</label>
        <input type="number" id="jobSlotsInput" name="slots_needed" min="1" max="500" value="1" required>
      </div>
      <div class="rec-form-row">
        <label for="jobStatusInput">Status</label>
        <select id="jobStatusInput" name="status">
          <option value="Open">Open</option>
          <option value="Closed">Closed</option>
        </select>
      </div>
      <div class="rec-form-row">
        <label for="jobLocationInput">Location</label>
        <input type="text" id="jobLocationInput" name="location" value="Cebu City">
      </div>
      <div class="rec-form-row">
        <label for="jobDescInput">Description</label>
        <textarea id="jobDescInput" name="description" rows="3"></textarea>
      </div>
      <div class="rec-modal-foot">
        <button type="button" class="rec-btn-cancel" id="closeJobModalBtn">Cancel</button>
        <button type="submit" class="rec-btn-primary">Save Job</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Applicant Modal -->
<div class="rec-modal-bg" id="applicantModal">
  <div class="rec-modal-box">
    <h3 style="margin:0 0 14px;">Add Applicant</h3>
    <form method="post" action="" class="rec-ajax-form" data-close-modal="applicantModal">
      <input type="hidden" name="rec_action" value="add_applicant">
      <div class="rec-form-row">
        <label for="appJobSelect">Job Vacancy</label>
        <select id="appJobSelect" name="job_id" required>
          <?php foreach ($jobs as $j): ?>
            <?php $rem = max(0, (int)($j['remaining_needed'] ?? 1)); ?>
            <option value="<?= (int)$j['id'] ?>"><?= htmlspecialchars($j['job_title'] . ' (' . $j['department'] . ' — ' . $rem . ' needed)', ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rec-form-row">
        <label for="appNameInput">Applicant Name</label>
        <input type="text" id="appNameInput" name="applicant_name" required>
      </div>
      <div class="rec-form-row">
        <label for="appEmailInput">Email</label>
        <input type="email" id="appEmailInput" name="email" required>
      </div>
      <div class="rec-form-row">
        <label for="appStageSelect">Stage</label>
        <select id="appStageSelect" name="stage">
          <option value="Applicant">Applicant</option>
          <option value="Interview">Interview</option>
          <option value="Hired">Hired (Approve)</option>
        </select>
      </div>
      <div class="rec-form-row">
        <label for="appInterviewInput">Interview Date &amp; Time (Optional)</label>
        <input type="datetime-local" id="appInterviewInput" name="interview_date">
      </div>
      <div class="rec-modal-foot">
        <button type="button" class="rec-btn-cancel" id="closeAppModalBtn">Cancel</button>
        <button type="submit" class="rec-btn-primary">Add Applicant</button>
      </div>
    </form>
  </div>
</div>

<script>
  (function () {
    let lastSignature = <?= json_encode($initialState['signature'], JSON_UNESCAPED_UNICODE) ?>;

    function esc(v) {
      return String(v ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function showBanner(ok, msg) {
      const box = document.getElementById('recLiveBanner');
      if (!box || !msg) return;
      box.innerHTML = `<div class="${ok ? 'rec-alert-ok' : 'rec-alert-err'}">${esc(msg)}</div>`;
    }

    const tabButtons = document.querySelectorAll('.rec-tab-btn');
    const panes = document.querySelectorAll('.rec-panel-pane');

    tabButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        const target = btn.getAttribute('data-tab');
        tabButtons.forEach(b => b.classList.toggle('is-active', b === btn));
        panes.forEach(p => p.classList.toggle('is-active', p.id === 'pane-' + target));
      });
    });

    const jobModal = document.getElementById('jobModal');
    function openEditJobModal(job) {
      document.getElementById('jobModalTitle').textContent = 'Edit Job Vacancy';
      document.getElementById('jobIdInput').value = job.id || 0;
      document.getElementById('jobTitleInput').value = job.job_title || '';
      document.getElementById('jobDeptInput').value = job.department || '';
      document.getElementById('jobSlotsInput').value = Math.max(1, Number(job.slots_needed || 1));
      document.getElementById('jobStatusInput').value = job.status || 'Open';
      document.getElementById('jobLocationInput').value = job.location || 'Cebu City';
      document.getElementById('jobDescInput').value = job.description || '';
      jobModal.classList.add('is-open');
    }

    document.getElementById('openPostJobBtn').addEventListener('click', () => {
      document.getElementById('jobModalTitle').textContent = 'Post New Job';
      document.getElementById('jobIdInput').value = '0';
      document.getElementById('jobTitleInput').value = '';
      document.getElementById('jobDeptInput').value = '';
      document.getElementById('jobSlotsInput').value = '1';
      document.getElementById('jobStatusInput').value = 'Open';
      document.getElementById('jobLocationInput').value = 'Cebu City';
      document.getElementById('jobDescInput').value = '';
      jobModal.classList.add('is-open');
    });
    document.getElementById('closeJobModalBtn').addEventListener('click', () => jobModal.classList.remove('is-open'));
    jobModal.addEventListener('click', e => { if (e.target === jobModal) jobModal.classList.remove('is-open'); });

    const appModal = document.getElementById('applicantModal');
    const openAppBtn = document.getElementById('openAddApplicantBtn');
    if (openAppBtn) openAppBtn.addEventListener('click', () => appModal.classList.add('is-open'));
    document.getElementById('closeAppModalBtn').addEventListener('click', () => appModal.classList.remove('is-open'));
    appModal.addEventListener('click', e => { if (e.target === appModal) appModal.classList.remove('is-open'); });

    function buildAddEmployeeUrl(hd) {
      const params = new URLSearchParams({
        app_id: String(hd.id || 0),
        full_name: String(hd.applicant_name || ''),
        email: String(hd.email || ''),
        phone: String(hd.phone || ''),
        department: String(hd.department || ''),
        job_title: String(hd.job_title || '')
      });
      return '/hr/add_employee.php?' + params.toString();
    }

    function renderLiveState(data) {
      if (!data || !data.ok) return;

      if (data.signature) {
        lastSignature = data.signature;
      }

      if (data.serverTime) {
        const elTime = document.getElementById('recLastSync');
        if (elTime) elTime.textContent = data.serverTime;
      }

      if (data.counts) {
        const bApp = document.getElementById('tabBtnApplicants');
        const bInt = document.getElementById('tabBtnInterviews');
        const bHir = document.getElementById('tabBtnHiring');
        if (bApp) bApp.textContent = `Applicants (${data.counts.applicants ?? 0})`;
        if (bInt) bInt.textContent = `Interviews (${data.counts.interviews ?? 0})`;
        if (bHir) bHir.textContent = `Hiring (${data.counts.hiring ?? 0})`;
      }

      const jobSelect = document.getElementById('appJobSelect');
      if (jobSelect && Array.isArray(data.jobs)) {
        const currentVal = jobSelect.value;
        jobSelect.innerHTML = data.jobs.map(j => {
          const rem = Math.max(0, Number(j.remaining_needed ?? 1));
          return `<option value="${Number(j.id)}">${esc(j.job_title)} (${esc(j.department)} — ${rem} needed)</option>`;
        }).join('');
        if (currentVal) jobSelect.value = currentVal;
      }

      const vacBody = document.getElementById('vacanciesTableBody');
      if (vacBody && Array.isArray(data.jobs)) {
        if (!data.jobs.length) {
          vacBody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:#94a3b8;">No job vacancies posted yet.</td></tr>`;
        } else {
          vacBody.innerHTML = data.jobs.map(job => {
            const needed = Math.max(1, Number(job.slots_needed || 1));
            const hired = Number(job.hired_count || 0);
            const remaining = Math.max(0, Number(job.remaining_needed ?? (needed - hired)));
            const isOpen = (job.status || 'Open') === 'Open' && remaining > 0;
            const badgeCls = isOpen ? 'rec-badge-open' : 'rec-badge-closed';
            const slotHtml = remaining > 0
              ? `<span class="rec-slot-pill">${remaining} needed</span><span class="rec-slot-sub">${hired} of ${needed} approved</span>`
              : `<span class="rec-slot-pill is-full">0 needed (Filled)</span><span class="rec-slot-sub">${hired} of ${needed} approved</span>`;
            const jsonAttr = esc(JSON.stringify(job));
            return `
              <tr>
                <td><strong>${esc(job.job_title)}</strong></td>
                <td>${esc(job.department)}</td>
                <td>${slotHtml}</td>
                <td><span class="rec-badge ${badgeCls}">${isOpen ? 'Open' : 'Closed'}</span></td>
                <td>
                  <div class="rec-actions">
                    <button type="button" class="rec-icon-btn edit-job-btn" title="Edit Job" data-job="${jsonAttr}">✎</button>
                    <form method="post" action="" class="rec-ajax-form" style="display:inline;" data-confirm="Delete this job vacancy?">
                      <input type="hidden" name="rec_action" value="delete_job">
                      <input type="hidden" name="job_id" value="${Number(job.id)}">
                      <button type="submit" class="rec-icon-btn danger" title="Delete Job">🗑</button>
                    </form>
                  </div>
                </td>
              </tr>
            `;
          }).join('');
        }
      }

      const appBody = document.getElementById('applicantsTableBody');
      if (appBody && Array.isArray(data.applications)) {
        if (!data.applications.length) {
          appBody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:#94a3b8;">No applicants recorded yet.</td></tr>`;
        } else {
          appBody.innerHTML = data.applications.map(app => {
            const stages = ['Applicant', 'Interview', 'Hired', 'Rejected'];
            const opts = stages.map(st =>
              `<option value="${st}" ${app.stage === st ? 'selected' : ''}>${st === 'Hired' ? 'Hired (Approve)' : st}</option>`
            ).join('');
            const dtVal = app.interview_date ? esc(String(app.interview_date).slice(0, 16)) : '';
            return `
              <tr>
                <td>
                  <strong>${esc(app.applicant_name)}</strong><br>
                  <span style="font-size:12px; color:#94a3b8;">${esc(app.email)}</span>
                </td>
                <td>${esc(app.job_title)}</td>
                <td>${esc(app.department)}</td>
                <td><span class="rec-badge rec-badge-info">${esc(app.stage)}</span></td>
                <td>
                  <form method="post" action="" class="rec-ajax-form" style="display:inline-flex; gap:6px; align-items:center;">
                    <input type="hidden" name="rec_action" value="update_stage">
                    <input type="hidden" name="app_id" value="${Number(app.id)}">
                    <input type="hidden" name="interview_date" value="${dtVal}">
                    <input type="hidden" name="interviewer" value="${esc(app.interviewer || '')}">
                    <select name="stage" style="padding:5px 8px; border-radius:6px; background:#1e293b; color:#f8fafc; border:1px solid rgba(148,163,184,0.3);">${opts}</select>
                    <button type="submit" class="rec-icon-btn">Save</button>
                  </form>
                </td>
              </tr>
            `;
          }).join('');
        }
      }

      const intBody = document.getElementById('interviewsTableBody');
      if (intBody && Array.isArray(data.interviews)) {
        if (!data.interviews.length) {
          intBody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:#94a3b8;">No interviews scheduled yet.</td></tr>`;
        } else {
          intBody.innerHTML = data.interviews.map(iv => `
            <tr>
              <td><strong>${esc(iv.applicant_name)}</strong></td>
              <td>${esc(iv.job_title)}</td>
              <td>${esc(iv.department)}</td>
              <td>${esc(iv.interview_date || 'TBD')}</td>
              <td>
                <form method="post" action="" class="rec-ajax-form" style="display:inline;">
                  <input type="hidden" name="rec_action" value="update_stage">
                  <input type="hidden" name="app_id" value="${Number(iv.id)}">
                  <input type="hidden" name="stage" value="Hired">
                  <button type="submit" class="rec-btn-primary" style="padding:6px 12px; font-size:12px;">Approve &amp; Hire</button>
                </form>
              </td>
            </tr>
          `).join('');
        }
      }

      const hirBody = document.getElementById('hiringTableBody');
      if (hirBody && Array.isArray(data.hired)) {
        if (!data.hired.length) {
          hirBody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:#94a3b8;">No pending hired candidates to onboard.</td></tr>`;
        } else {
          hirBody.innerHTML = data.hired.map(hd => `
            <tr>
              <td><strong>${esc(hd.applicant_name)}</strong></td>
              <td>${esc(hd.job_title)}</td>
              <td>${esc(hd.department)}</td>
              <td><span class="rec-badge rec-badge-open">Approved / Hired</span></td>
              <td>
                <a href="${esc(buildAddEmployeeUrl(hd))}" class="rec-icon-btn">Onboard in Employees</a>
              </td>
            </tr>
          `).join('');
        }
      }

      bindDynamicEvents();
    }

    function bindDynamicEvents() {
      document.querySelectorAll('.edit-job-btn').forEach(btn => {
        if (btn.dataset.bound) return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', () => {
          openEditJobModal(JSON.parse(btn.getAttribute('data-job') || '{}'));
        });
      });

      document.querySelectorAll('form.rec-ajax-form').forEach(form => {
        if (form.dataset.bound) return;
        form.dataset.bound = '1';
        form.addEventListener('submit', async (e) => {
          e.preventDefault();
          const confirmMsg = form.getAttribute('data-confirm');
          if (confirmMsg && !window.confirm(confirmMsg)) return;

          const fd = new FormData(form);
          fd.append('ajax', '1');

          try {
            const res = await fetch(window.location.pathname + window.location.search, {
              method: 'POST',
              body: fd,
              cache: 'no-store'
            });
            const payload = await res.json();
            showBanner(Boolean(payload.ok), payload.message || '');
            if (payload.ok && payload.state) {
              renderLiveState(payload.state);
              const modalId = form.getAttribute('data-close-modal');
              if (modalId) {
                const m = document.getElementById(modalId);
                if (m) m.classList.remove('is-open');
              }
            }
          } catch (_) {
            showBanner(false, 'Network error while updating recruitment.');
          }
        });
      });
    }

    bindDynamicEvents();

    async function pollLiveState() {
      if (document.hidden) return;
      try {
        const res = await fetch('?api=live_state&_=' + Date.now(), { cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();
        if (data && data.signature && data.signature !== lastSignature) {
          renderLiveState(data);
        } else if (data && data.serverTime) {
          const elTime = document.getElementById('recLastSync');
          if (elTime) elTime.textContent = data.serverTime;
        }
      } catch (_) {}
    }

    setInterval(pollLiveState, 2500);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) pollLiveState();
    });
  })();
</script>