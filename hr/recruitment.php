<?php
// hr/recruitment.php

require_once __DIR__ . '/../authentication/guard.php';
require_access('employee_records', 'view', 'all');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../templates/hrTemplates/header.php';

// Ensure recruitment tables exist safely without touching existing HRIS tables
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `recruitment_jobs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `job_title` VARCHAR(150) NOT NULL,
            `department` VARCHAR(120) NOT NULL,
            `location` VARCHAR(120) NOT NULL DEFAULT 'On-site',
            `employment_type` VARCHAR(60) NOT NULL DEFAULT 'Full-time',
            `status` ENUM('Open', 'Closed') NOT NULL DEFAULT 'Open',
            `description` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

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
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_job_id` (`job_id`),
            CONSTRAINT `fk_rec_app_job` FOREIGN KEY (`job_id`) REFERENCES `recruitment_jobs` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $jobCount = (int)$pdo->query("SELECT COUNT(*) FROM `recruitment_jobs`")->fetchColumn();
    if ($jobCount === 0) {
        $seedJobs = [
            ['Software Developer', 'IT', 'Cebu City', 'Full-time', 'Open', 'Develop and maintain web and internal HRIS applications.'],
            ['System Analyst', 'IT', 'Cebu City', 'Full-time', 'Open', 'Analyze business workflows and system requirements.'],
            ['Accountant', 'Finance', 'Mandaue City', 'Full-time', 'Closed', 'Handle payroll reconciliation, ledgers, and financial reports.'],
            ['HR Specialist', 'HR', 'Cebu City', 'Full-time', 'Open', 'Manage talent acquisition, onboarding, and employee records.'],
            ['Marketing Officer', 'Marketing', 'Lapu-Lapu City', 'Full-time', 'Open', 'Plan campaigns, brand outreach, and employer branding.'],
        ];
        $insJob = $pdo->prepare("
            INSERT INTO `recruitment_jobs` (`job_title`, `department`, `location`, `employment_type`, `status`, `description`)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($seedJobs as $sj) {
            $insJob->execute($sj);
        }

        $firstJobId = (int)$pdo->query("SELECT `id` FROM `recruitment_jobs` WHERE `job_title` = 'Software Developer' LIMIT 1")->fetchColumn();
        $secondJobId = (int)$pdo->query("SELECT `id` FROM `recruitment_jobs` WHERE `job_title` = 'HR Specialist' LIMIT 1")->fetchColumn();
        if ($firstJobId) {
            $insApp = $pdo->prepare("
                INSERT INTO `recruitment_applications` (`job_id`, `applicant_name`, `email`, `phone`, `stage`, `interview_date`, `interviewer`, `notes`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insApp->execute([$firstJobId, 'Liam Santos', 'liam.santos@example.com', '09175550101', 'Applicant', null, null, 'Strong PHP & SQL background']);
            $insApp->execute([$firstJobId, 'Chloe Mendoza', 'chloe.mendoza@example.com', '09175550102', 'Interview', '2026-11-24 10:00:00', 'IT Lead', 'Technical panel scheduled']);
            if ($secondJobId) {
                $insApp->execute([$secondJobId, 'Noah Villanueva', 'noah.v@example.com', '09175550103', 'Hired', '2026-11-12 14:00:00', 'HR Manager', 'Offer accepted; ready for onboarding']);
            }
        }
    }
} catch (Throwable $e) {
    error_log('Recruitment init error: ' . $e->getMessage());
}

$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['rec_action'] ?? '');

    try {
        if ($action === 'save_job') {
            $jobId      = (int)($_POST['job_id'] ?? 0);
            $jobTitle   = trim((string)($_POST['job_title'] ?? ''));
            $department = trim((string)($_POST['department'] ?? ''));
            $location   = trim((string)($_POST['location'] ?? 'On-site'));
            $empType    = trim((string)($_POST['employment_type'] ?? 'Full-time'));
            $status     = in_array($_POST['status'] ?? 'Open', ['Open', 'Closed'], true) ? $_POST['status'] : 'Open';
            $desc       = trim((string)($_POST['description'] ?? ''));

            if ($jobTitle === '' || $department === '') {
                $flashError = 'Job Title and Department are required.';
            } elseif ($jobId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE `recruitment_jobs`
                    SET `job_title` = ?, `department` = ?, `location` = ?, `employment_type` = ?, `status` = ?, `description` = ?
                    WHERE `id` = ?
                ");
                $stmt->execute([$jobTitle, $department, $location, $empType, $status, $desc, $jobId]);
                $flashSuccess = 'Job vacancy updated.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO `recruitment_jobs` (`job_title`, `department`, `location`, `employment_type`, `status`, `description`)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$jobTitle, $department, $location, $empType, $status, $desc]);
                $flashSuccess = 'New job vacancy posted.';
            }
        } elseif ($action === 'delete_job') {
            $jobId = (int)($_POST['job_id'] ?? 0);
            if ($jobId > 0) {
                $stmt = $pdo->prepare("DELETE FROM `recruitment_jobs` WHERE `id` = ?");
                $stmt->execute([$jobId]);
                $flashSuccess = 'Job vacancy removed.';
            }
        } elseif ($action === 'add_applicant') {
            $jobId         = (int)($_POST['job_id'] ?? 0);
            $applicantName = trim((string)($_POST['applicant_name'] ?? ''));
            $email         = trim((string)($_POST['email'] ?? ''));
            $phone         = trim((string)($_POST['phone'] ?? ''));
            $stage         = in_array($_POST['stage'] ?? 'Applicant', ['Applicant', 'Interview', 'Hired', 'Rejected'], true) ? $_POST['stage'] : 'Applicant';
            $interviewRaw  = trim((string)($_POST['interview_date'] ?? ''));
            $interviewDate = $interviewRaw !== '' ? str_replace('T', ' ', $interviewRaw) . ':00' : null;
            $interviewer   = trim((string)($_POST['interviewer'] ?? ''));
            $notes         = trim((string)($_POST['notes'] ?? ''));

            if ($jobId <= 0 || $applicantName === '' || $email === '') {
                $flashError = 'Vacancy, applicant name, and email are required.';
            } else {
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
                $flashSuccess = 'Applicant added to recruitment pipeline.';
            }
        } elseif ($action === 'update_stage') {
            $appId        = (int)($_POST['app_id'] ?? 0);
            $stage        = in_array($_POST['stage'] ?? 'Applicant', ['Applicant', 'Interview', 'Hired', 'Rejected'], true) ? $_POST['stage'] : 'Applicant';
            $interviewRaw = trim((string)($_POST['interview_date'] ?? ''));
            $interviewDt  = $interviewRaw !== '' ? str_replace('T', ' ', $interviewRaw) . ':00' : null;
            $interviewer  = trim((string)($_POST['interviewer'] ?? ''));

            if ($appId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE `recruitment_applications`
                    SET `stage` = ?, `interview_date` = ?, `interviewer` = ?
                    WHERE `id` = ?
                ");
                $stmt->execute([
                    $stage,
                    $interviewDt,
                    $interviewer !== '' ? $interviewer : null,
                    $appId,
                ]);
                $flashSuccess = 'Applicant stage updated to ' . $stage . '.';
            }
        }
    } catch (Throwable $e) {
        error_log('Recruitment action failed: ' . $e->getMessage());
        $flashError = 'Could not complete the request. Please check your input.';
    }
}

$activeTab = $_GET['tab'] ?? 'vacancies';
if (!in_array($activeTab, ['vacancies', 'applicants', 'interviews', 'hiring'], true)) {
    $activeTab = 'vacancies';
}

$jobs = $pdo->query("SELECT * FROM `recruitment_jobs` ORDER BY `id` ASC")->fetchAll(PDO::FETCH_ASSOC);

$applications = $pdo->query("
    SELECT a.*, j.job_title, j.department
    FROM `recruitment_applications` a
    INNER JOIN `recruitment_jobs` j ON j.id = a.job_id
    ORDER BY a.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$applicantsList = array_values(array_filter($applications, fn($r) => $r['stage'] === 'Applicant'));
$interviewsList = array_values(array_filter($applications, fn($r) => $r['stage'] === 'Interview'));
$hiredList      = array_values(array_filter($applications, fn($r) => $r['stage'] === 'Hired'));
?>

<style>
  .rec-page {
    padding: 20px 24px 40px;
    color: #e2e8f0;
  }
  .rec-top-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 18px;
  }
  .rec-tabs {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 6px;
    background: rgba(15, 23, 42, 0.75);
    border: 1px solid rgba(148, 163, 184, 0.2);
    border-radius: 12px;
    padding: 5px;
  }
  .rec-tab-btn {
    border: none;
    background: transparent;
    color: #94a3b8;
    font-size: 13.5px;
    font-weight: 600;
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
  }
  .rec-tab-btn:hover {
    color: #f8fafc;
    background: rgba(59, 130, 246, 0.14);
  }
  .rec-tab-btn.is-active {
    background: #2563eb;
    color: #ffffff;
  }
  .rec-btn-primary {
    border: none;
    background: #2563eb;
    color: #ffffff;
    font-size: 13.5px;
    font-weight: 700;
    padding: 10px 18px;
    border-radius: 10px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  .rec-btn-primary:hover {
    background: #1d4ed8;
  }
  .rec-card {
    background: rgba(15, 23, 42, 0.72);
    border: 1px solid rgba(148, 163, 184, 0.2);
    border-radius: 14px;
    overflow: hidden;
  }
  .rec-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
  }
  .rec-table th {
    text-align: left;
    padding: 14px 18px;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #94a3b8;
    border-bottom: 1px solid rgba(148, 163, 184, 0.16);
    background: rgba(30, 41, 59, 0.45);
  }
  .rec-table td {
    padding: 14px 18px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.1);
    vertical-align: middle;
  }
  .rec-table tr:last-child td {
    border-bottom: none;
  }
  .rec-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
  }
  .rec-badge-open {
    background: rgba(34, 197, 94, 0.16);
    color: #4ade80;
    border: 1px solid rgba(74, 222, 128, 0.35);
  }
  .rec-badge-closed {
    background: rgba(245, 158, 11, 0.16);
    color: #fbbf24;
    border: 1px solid rgba(251, 191, 36, 0.35);
  }
  .rec-badge-info {
    background: rgba(59, 130, 246, 0.16);
    color: #60a5fa;
    border: 1px solid rgba(96, 165, 250, 0.35);
  }
  .rec-actions {
    display: inline-flex;
    align-items: center;
    gap: 10px;
  }
  .rec-icon-btn {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(148, 163, 184, 0.22);
    color: #e2e8f0;
    border-radius: 8px;
    padding: 6px 9px;
    cursor: pointer;
    font-size: 13px;
  }
  .rec-icon-btn:hover {
    border-color: #60a5fa;
    color: #93c5fd;
  }
  .rec-icon-btn.danger:hover {
    border-color: #f87171;
    color: #fca5a5;
  }
  .rec-panel-pane {
    display: none;
  }
  .rec-panel-pane.is-active {
    display: block;
  }
  .rec-alert-ok {
    padding: 11px 14px;
    border-radius: 10px;
    background: rgba(34, 197, 94, 0.15);
    border: 1px solid rgba(74, 222, 128, 0.4);
    color: #bbf7d0;
    margin-bottom: 14px;
    font-size: 13.5px;
  }
  .rec-alert-err {
    padding: 11px 14px;
    border-radius: 10px;
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(248, 113, 113, 0.4);
    color: #fecaca;
    margin-bottom: 14px;
    font-size: 13.5px;
  }
  .rec-modal-bg {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(2, 6, 23, 0.78);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }
  .rec-modal-bg.is-open {
    display: flex;
  }
  .rec-modal-box {
    width: 100%;
    max-width: 500px;
    background: #0f172a;
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 14px;
    padding: 20px 22px;
    color: #f8fafc;
  }
  .rec-form-row {
    margin-bottom: 12px;
  }
  .rec-form-row label {
    display: block;
    font-size: 12px;
    color: #94a3b8;
    margin-bottom: 5px;
  }
  .rec-form-row input,
  .rec-form-row select,
  .rec-form-row textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 9px 11px;
    border-radius: 8px;
    border: 1px solid rgba(148, 163, 184, 0.28);
    background: #1e293b;
    color: #f8fafc;
    font-size: 13.5px;
  }
  .rec-modal-foot {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 16px;
  }
  .rec-btn-cancel {
    background: transparent;
    border: 1px solid rgba(148, 163, 184, 0.3);
    color: #cbd5e1;
    padding: 8px 14px;
    border-radius: 8px;
    cursor: pointer;
  }
  .rec-subbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 18px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.14);
    background: rgba(30, 41, 59, 0.3);
  }
</style>

<div class="rec-page">
  <div style="margin-bottom: 16px;">
    <h1 style="margin: 0 0 4px; font-size: 24px;">Recruitment</h1>
    <p style="margin: 0; color: #94a3b8; font-size: 13.5px;">Manage job vacancies, applicants, scheduled interviews, and hiring.</p>
  </div>

  <?php if ($flashSuccess !== ''): ?>
    <div class="rec-alert-ok"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($flashError !== ''): ?>
    <div class="rec-alert-err"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="rec-top-bar">
    <div class="rec-tabs" role="tablist">
      <button type="button" class="rec-tab-btn <?= $activeTab === 'vacancies' ? 'is-active' : '' ?>" data-tab="vacancies">Job Vacancies</button>
      <button type="button" class="rec-tab-btn <?= $activeTab === 'applicants' ? 'is-active' : '' ?>" data-tab="applicants">Applicants (<?= count($applicantsList) ?>)</button>
      <button type="button" class="rec-tab-btn <?= $activeTab === 'interviews' ? 'is-active' : '' ?>" data-tab="interviews">Interviews (<?= count($interviewsList) ?>)</button>
      <button type="button" class="rec-tab-btn <?= $activeTab === 'hiring' ? 'is-active' : '' ?>" data-tab="hiring">Hiring (<?= count($hiredList) ?>)</button>
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
            <th>Status</th>
            <th style="width: 130px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($jobs)): ?>
            <tr><td colspan="4" style="text-align:center; color:#94a3b8;">No job vacancies posted yet.</td></tr>
          <?php else: ?>
            <?php foreach ($jobs as $job): ?>
              <?php $isOpen = ($job['status'] ?? 'Open') === 'Open'; ?>
              <tr>
                <td><strong><?= htmlspecialchars($job['job_title'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><?= htmlspecialchars($job['department'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <span class="rec-badge <?= $isOpen ? 'rec-badge-open' : 'rec-badge-closed' ?>">
                    <?= htmlspecialchars($job['status'], ENT_QUOTES, 'UTF-8') ?>
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
                    <form method="post" action="" style="display:inline;" onsubmit="return confirm('Delete this job vacancy?');">
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
        <tbody>
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
                  <form method="post" action="" style="display:inline-flex; gap:6px; align-items:center;">
                    <input type="hidden" name="rec_action" value="update_stage">
                    <input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>">
                    <input type="hidden" name="interview_date" value="<?= $app['interview_date'] ? htmlspecialchars(substr($app['interview_date'], 0, 16), ENT_QUOTES, 'UTF-8') : '' ?>">
                    <input type="hidden" name="interviewer" value="<?= htmlspecialchars($app['interviewer'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <select name="stage" style="padding:5px 8px; border-radius:6px; background:#1e293b; color:#f8fafc; border:1px solid rgba(148,163,184,0.3);">
                      <?php foreach (['Applicant', 'Interview', 'Hired', 'Rejected'] as $st): ?>
                        <option value="<?= $st ?>" <?= $app['stage'] === $st ? 'selected' : '' ?>><?= $st ?></option>
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
        <tbody>
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
                  <form method="post" action="" style="display:inline;">
                    <input type="hidden" name="rec_action" value="update_stage">
                    <input type="hidden" name="app_id" value="<?= (int)$iv['id'] ?>">
                    <input type="hidden" name="stage" value="Hired">
                    <button type="submit" class="rec-btn-primary" style="padding:6px 12px; font-size:12px;">Move to Hiring</button>
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
        <tbody>
          <?php if (empty($hiredList)): ?>
            <tr><td colspan="5" style="text-align:center; color:#94a3b8;">No hired candidates yet.</td></tr>
          <?php else: ?>
            <?php foreach ($hiredList as $hd): ?>
              <tr>
                <td><strong><?= htmlspecialchars($hd['applicant_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><?= htmlspecialchars($hd['job_title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($hd['department'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="rec-badge rec-badge-open">Hired</span></td>
                <td><a href="/hr/add_employee.php" class="rec-icon-btn" style="text-decoration:none;">Onboard in Employees</a></td>
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
    <form method="post" action="">
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
    <form method="post" action="">
      <input type="hidden" name="rec_action" value="add_applicant">
      <div class="rec-form-row">
        <label for="appJobSelect">Job Vacancy</label>
        <select id="appJobSelect" name="job_id" required>
          <?php foreach ($jobs as $j): ?>
            <option value="<?= (int)$j['id'] ?>"><?= htmlspecialchars($j['job_title'] . ' (' . $j['department'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
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
          <option value="Hired">Hired</option>
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
    document.getElementById('openPostJobBtn').addEventListener('click', () => {
      document.getElementById('jobModalTitle').textContent = 'Post New Job';
      document.getElementById('jobIdInput').value = '0';
      document.getElementById('jobTitleInput').value = '';
      document.getElementById('jobDeptInput').value = '';
      document.getElementById('jobStatusInput').value = 'Open';
      document.getElementById('jobLocationInput').value = 'Cebu City';
      document.getElementById('jobDescInput').value = '';
      jobModal.classList.add('is-open');
    });

    document.querySelectorAll('.edit-job-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const job = JSON.parse(btn.getAttribute('data-job') || '{}');
        document.getElementById('jobModalTitle').textContent = 'Edit Job Vacancy';
        document.getElementById('jobIdInput').value = job.id || 0;
        document.getElementById('jobTitleInput').value = job.job_title || '';
        document.getElementById('jobDeptInput').value = job.department || '';
        document.getElementById('jobStatusInput').value = job.status || 'Open';
        document.getElementById('jobLocationInput').value = job.location || 'Cebu City';
        document.getElementById('jobDescInput').value = job.description || '';
        jobModal.classList.add('is-open');
      });
    });

    document.getElementById('closeJobModalBtn').addEventListener('click', () => jobModal.classList.remove('is-open'));
    jobModal.addEventListener('click', e => { if (e.target === jobModal) jobModal.classList.remove('is-open'); });

    const appModal = document.getElementById('applicantModal');
    const openAppBtn = document.getElementById('openAddApplicantBtn');
    if (openAppBtn) {
      openAppBtn.addEventListener('click', () => appModal.classList.add('is-open'));
    }
    document.getElementById('closeAppModalBtn').addEventListener('click', () => appModal.classList.remove('is-open'));
    appModal.addEventListener('click', e => { if (e.target === appModal) appModal.classList.remove('is-open'); });
  })();
</script>