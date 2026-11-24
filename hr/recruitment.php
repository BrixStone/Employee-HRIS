<?php
// hr/recruitment.php

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../authentication/guard.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$role = strtolower(trim((string)($_SESSION['role'] ?? 'employee')));
if (!in_array($role, ['hr', 'admin', 'manager'], true)) {
    header('Location: /employee/dashboard.php');
    exit;
}

try {
    $pdo->exec("SET time_zone = '+08:00'");
} catch (Throwable $e) {}

function rec_has_table(PDO $pdo, string $table): bool {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
        $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function rec_has_col(PDO $pdo, string $table, string $col): bool {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $col)) return false;
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($col));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function rec_ensure_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `job_vacancies` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(160) NOT NULL,
            `department` VARCHAR(120) NOT NULL DEFAULT 'General',
            `employment_type` VARCHAR(64) NOT NULL DEFAULT 'Full-time',
            `openings` INT NOT NULL DEFAULT 1,
            `status` VARCHAR(32) NOT NULL DEFAULT 'Open',
            `description` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `job_applicants` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `vacancy_id` INT UNSIGNED NULL,
            `full_name` VARCHAR(160) NOT NULL,
            `email` VARCHAR(190) NOT NULL,
            `phone` VARCHAR(64) NULL,
            `job_title` VARCHAR(160) NOT NULL DEFAULT 'Applicant',
            `department` VARCHAR(120) NOT NULL DEFAULT 'General',
            `stage` VARCHAR(32) NOT NULL DEFAULT 'Applied',
            `interview_at` DATETIME NULL,
            `interview_mode` VARCHAR(64) NULL DEFAULT 'Online / Video Call',
            `interview_location` VARCHAR(255) NULL,
            `interviewer_name` VARCHAR(160) NULL,
            `interview_notes` TEXT NULL,
            `decision_notes` TEXT NULL,
            `decided_at` DATETIME NULL,
            `email_sent_at` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `employee_onboarding` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `candidate_id` INT UNSIGNED NULL,
            `employee_id` INT UNSIGNED NULL,
            `employee_code` VARCHAR(64) NULL,
            `full_name` VARCHAR(160) NOT NULL,
            `email` VARCHAR(190) NULL,
            `department` VARCHAR(120) NOT NULL DEFAULT 'General',
            `job_title` VARCHAR(160) NOT NULL DEFAULT 'New Hire',
            `stage` VARCHAR(32) NOT NULL DEFAULT 'Hired',
            `status` VARCHAR(32) NOT NULL DEFAULT 'In Progress',
            `step_info` TINYINT(1) NOT NULL DEFAULT 1,
            `step_docs` TINYINT(1) NOT NULL DEFAULT 0,
            `step_account` TINYINT(1) NOT NULL DEFAULT 0,
            `step_orientation` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_onb_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $alterCols = [
        'job_title'          => "ALTER TABLE `job_applicants` ADD COLUMN `job_title` VARCHAR(160) NOT NULL DEFAULT 'Applicant'",
        'department'         => "ALTER TABLE `job_applicants` ADD COLUMN `department` VARCHAR(120) NOT NULL DEFAULT 'General'",
        'stage'              => "ALTER TABLE `job_applicants` ADD COLUMN `stage` VARCHAR(32) NOT NULL DEFAULT 'Applied'",
        'interview_at'       => "ALTER TABLE `job_applicants` ADD COLUMN `interview_at` DATETIME NULL",
        'interview_mode'     => "ALTER TABLE `job_applicants` ADD COLUMN `interview_mode` VARCHAR(64) NULL DEFAULT 'Online / Video Call'",
        'interview_location' => "ALTER TABLE `job_applicants` ADD COLUMN `interview_location` VARCHAR(255) NULL",
        'interviewer_name'   => "ALTER TABLE `job_applicants` ADD COLUMN `interviewer_name` VARCHAR(160) NULL",
        'interview_notes'    => "ALTER TABLE `job_applicants` ADD COLUMN `interview_notes` TEXT NULL",
        'decision_notes'     => "ALTER TABLE `job_applicants` ADD COLUMN `decision_notes` TEXT NULL",
        'decided_at'         => "ALTER TABLE `job_applicants` ADD COLUMN `decided_at` DATETIME NULL",
        'email_sent_at'      => "ALTER TABLE `job_applicants` ADD COLUMN `email_sent_at` DATETIME NULL",
    ];
    foreach ($alterCols as $col => $sql) {
        if (!rec_has_col($pdo, 'job_applicants', $col)) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
    }

    $onbAlterCols = [
        'candidate_id' => "ALTER TABLE `employee_onboarding` ADD COLUMN `candidate_id` INT UNSIGNED NULL",
        'stage'        => "ALTER TABLE `employee_onboarding` ADD COLUMN `stage` VARCHAR(32) NOT NULL DEFAULT 'Hired'",
    ];
    foreach ($onbAlterCols as $col => $sql) {
        if (!rec_has_col($pdo, 'employee_onboarding', $col)) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
    }
}

function rec_build_interview_email(array $candidate, array $sched): array {
    $companyName  = trim((string)(getenv('COMPANY_NAME') ?: ($_SESSION['company_name'] ?? 'HRIS Human Resources')));
    $hrSenderName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'HR Recruitment Team'));
    $candName     = trim((string)($candidate['full_name'] ?? 'Applicant'));
    $jobTitle     = trim((string)($candidate['job_title'] ?? 'Open Position'));
    $department   = trim((string)($candidate['department'] ?? 'General'));
    $dtRaw        = trim((string)($sched['interview_at'] ?? ''));
    $mode         = trim((string)($sched['interview_mode'] ?? 'Online / Video Call'));
    $location     = trim((string)($sched['interview_location'] ?? 'To be provided'));
    $interviewer  = trim((string)($sched['interviewer_name'] ?? $hrSenderName));
    $notes        = trim((string)($sched['interview_notes'] ?? ''));

    $ts = strtotime($dtRaw);
    $formattedDate = $ts ? date('l, F j, Y', $ts) : $dtRaw;
    $formattedTime = $ts ? date('g:i A', $ts) . ' (PHT)' : '';

    $subject = "Interview Invitation: {$jobTitle} Position at {$companyName}";

    $notesPlain = $notes !== '' ? "\nAdditional Instructions:\n{$notes}\n" : '';
    $plainBody = "Dear {$candName},\n\n"
        . "Thank you for applying for the {$jobTitle} role in our {$department} Department at {$companyName}. "
        . "After reviewing your application, we are pleased to invite you to an interview.\n\n"
        . "INTERVIEW DETAILS\n"
        . "----------------------------------------\n"
        . "Position:       {$jobTitle} ({$department})\n"
        . "Date:           {$formattedDate}\n"
        . "Time:           {$formattedTime}\n"
        . "Interview Type: {$mode}\n"
        . "Location/Link:  {$location}\n"
        . "Interviewer:    {$interviewer}\n"
        . "----------------------------------------\n"
        . $notesPlain . "\n"
        . "Please reply to this email to confirm your availability for the scheduled date and time. "
        . "If you require any rescheduling or accommodations, kindly let us know at your earliest convenience.\n\n"
        . "Best regards,\n"
        . "{$hrSenderName}\n"
        . "Human Resources Department\n"
        . "{$companyName}";

    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $notesHtml = $notes !== ''
        ? '<div style="margin-top:16px;padding:12px 14px;background:#f8fafc;border-left:4px solid #2563eb;border-radius:6px;color:#334155;font-size:13.5px;">'
          . '<strong style="color:#0f172a;display:block;margin-bottom:4px;">Additional Instructions:</strong>'
          . nl2br($esc($notes))
          . '</div>'
        : '';

    $htmlBody = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>' . $esc($subject) . '</title></head>
<body style="margin:0;padding:24px;background-color:#f1f5f9;font-family:\'Segoe UI\',Arial,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
    <tr>
      <td style="background:linear-gradient(135deg,#0f172a,#1e3a8a);padding:24px 28px;color:#ffffff;">
        <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#93c5fd;font-weight:700;">' . $esc($companyName) . ' • Recruitment</div>
        <h1 style="margin:6px 0 0;font-size:21px;font-weight:700;color:#ffffff;">Interview Invitation Letter</h1>
      </td>
    </tr>
    <tr>
      <td style="padding:26px 28px;font-size:14.5px;line-height:1.65;color:#334155;">
        <p style="margin:0 0 14px;">Dear <strong style="color:#0f172a;">' . $esc($candName) . '</strong>,</p>
        <p style="margin:0 0 18px;">
          Thank you for your interest in joining <strong style="color:#0f172a;">' . $esc($companyName) . '</strong>.
          Following our initial review of your application for the <strong style="color:#2563eb;">' . $esc($jobTitle) . '</strong> position
          under the <strong>' . $esc($department) . '</strong> Department, we would like to invite you to an interview.
        </p>

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:10px;padding:16px;margin:16px 0;">
          <tr>
            <td style="padding:6px 10px;font-size:13px;color:#64748b;width:36%;font-weight:600;">Position</td>
            <td style="padding:6px 10px;font-size:13.5px;color:#0f172a;font-weight:700;">' . $esc($jobTitle) . ' (' . $esc($department) . ')</td>
          </tr>
          <tr>
            <td style="padding:6px 10px;font-size:13px;color:#64748b;font-weight:600;">Interview Date</td>
            <td style="padding:6px 10px;font-size:13.5px;color:#0f172a;font-weight:700;">' . $esc($formattedDate) . '</td>
          </tr>
          <tr>
            <td style="padding:6px 10px;font-size:13px;color:#64748b;font-weight:600;">Interview Time</td>
            <td style="padding:6px 10px;font-size:13.5px;color:#0f172a;font-weight:700;">' . $esc($formattedTime) . '</td>
          </tr>
          <tr>
            <td style="padding:6px 10px;font-size:13px;color:#64748b;font-weight:600;">Interview Format</td>
            <td style="padding:6px 10px;font-size:13.5px;color:#0f172a;">' . $esc($mode) . '</td>
          </tr>
          <tr>
            <td style="padding:6px 10px;font-size:13px;color:#64748b;font-weight:600;">Location / Link</td>
            <td style="padding:6px 10px;font-size:13.5px;color:#2563eb;font-weight:600;">' . $esc($location) . '</td>
          </tr>
          <tr>
            <td style="padding:6px 10px;font-size:13px;color:#64748b;font-weight:600;">Interviewer</td>
            <td style="padding:6px 10px;font-size:13.5px;color:#0f172a;">' . $esc($interviewer) . '</td>
          </tr>
        </table>

        ' . $notesHtml . '

        <p style="margin:18px 0 14px;">
          Please reply directly to this email to confirm your attendance. Should you have any questions or need to request a different time slot, feel free to reach out to us.
        </p>

        <p style="margin:20px 0 0;">
          Best regards,<br>
          <strong style="color:#0f172a;">' . $esc($hrSenderName) . '</strong><br>
          <span style="font-size:13px;color:#64748b;">Human Resources Department • ' . $esc($companyName) . '</span>
        </p>
      </td>
    </tr>
  </table>
</body>
</html>';

    return [
        'subject'    => $subject,
        'html_body'  => $htmlBody,
        'plain_body' => $plainBody,
    ];
}

function rec_build_rejection_email(array $candidate, string $reasonNote = ''): array {
    $companyName  = trim((string)(getenv('COMPANY_NAME') ?: ($_SESSION['company_name'] ?? 'HRIS Human Resources')));
    $hrSenderName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'HR Recruitment Team'));
    $candName     = trim((string)($candidate['full_name'] ?? 'Applicant'));
    $jobTitle     = trim((string)($candidate['job_title'] ?? 'Open Position'));
    $department   = trim((string)($candidate['department'] ?? 'General'));
    $reasonNote   = trim($reasonNote);

    $subject = "Application Status Update: {$jobTitle} at {$companyName}";

    $notePlain = $reasonNote !== '' ? "\nFeedback / Note:\n{$reasonNote}\n" : '';
    $plainBody = "Dear {$candName},\n\n"
        . "Thank you for taking the time to interview with us for the {$jobTitle} position in our {$department} Department at {$companyName}.\n\n"
        . "After careful consideration of all candidates, we regret to inform you that we will not be moving forward with your application at this time, and you have not been selected for the role.\n"
        . $notePlain . "\n"
        . "We truly appreciate the effort and interest you shared with our team, and we will keep your profile on file for future opportunities that match your qualifications.\n\n"
        . "We wish you the very best in your job search and future career endeavors.\n\n"
        . "Sincerely,\n"
        . "{$hrSenderName}\n"
        . "Human Resources Department\n"
        . "{$companyName}";

    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $noteHtml = $reasonNote !== ''
        ? '<div style="margin:16px 0;padding:12px 14px;background:#fef2f2;border-left:4px solid #ef4444;border-radius:6px;color:#7f1d1d;font-size:13.5px;">'
          . '<strong style="display:block;margin-bottom:4px;">Feedback / Remarks:</strong>'
          . nl2br($esc($reasonNote))
          . '</div>'
        : '';

    $htmlBody = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>' . $esc($subject) . '</title></head>
<body style="margin:0;padding:24px;background-color:#f1f5f9;font-family:\'Segoe UI\',Arial,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
    <tr>
      <td style="background:linear-gradient(135deg,#0f172a,#334155);padding:24px 28px;color:#ffffff;">
        <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#cbd5e1;font-weight:700;">' . $esc($companyName) . ' • Recruitment</div>
        <h1 style="margin:6px 0 0;font-size:21px;font-weight:700;color:#ffffff;">Application Decision Notice</h1>
      </td>
    </tr>
    <tr>
      <td style="padding:26px 28px;font-size:14.5px;line-height:1.65;color:#334155;">
        <p style="margin:0 0 14px;">Dear <strong style="color:#0f172a;">' . $esc($candName) . '</strong>,</p>
        <p style="margin:0 0 16px;">
          Thank you for taking the time to interview with <strong style="color:#0f172a;">' . $esc($companyName) . '</strong>
          for the <strong>' . $esc($jobTitle) . '</strong> position in our <strong>' . $esc($department) . '</strong> Department.
        </p>
        <p style="margin:0 0 16px;">
          After careful evaluation of all candidates who interviewed for this role, we regret to inform you that
          <strong>we will not be moving forward with your application</strong> and you were not selected for hire at this time.
        </p>
        ' . $noteHtml . '
        <p style="margin:0 0 16px;">
          We sincerely appreciate your interest in joining our team and the time you invested throughout the interview process.
          We wish you every success in your career ahead.
        </p>
        <p style="margin:20px 0 0;">
          Sincerely,<br>
          <strong style="color:#0f172a;">' . $esc($hrSenderName) . '</strong><br>
          <span style="font-size:13px;color:#64748b;">Human Resources Department • ' . $esc($companyName) . '</span>
        </p>
      </td>
    </tr>
  </table>
</body>
</html>';

    return [
        'subject'    => $subject,
        'html_body'  => $htmlBody,
        'plain_body' => $plainBody,
    ];
}

function rec_send_candidate_email(string $toEmail, string $toName, string $subject, string $htmlBody, string $plainBody): array {
    $toEmail = trim($toEmail);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'error' => 'Invalid candidate email address: ' . $toEmail];
    }

    $mailerFile = __DIR__ . '/../classes/Mailer.php';
    if (!is_file($mailerFile)) {
        return ['sent' => false, 'error' => 'classes/Mailer.php not found.'];
    }

    try {
        require_once $mailerFile;
        if (!class_exists('Mailer')) {
            return ['sent' => false, 'error' => 'Mailer class not found in classes/Mailer.php.'];
        }

        $mailer = new Mailer();
        $sent = $mailer->send($toEmail, $subject, $htmlBody, $plainBody);
        return $sent
            ? ['sent' => true, 'error' => '']
            : ['sent' => false, 'error' => 'Mailer::send() returned false. Check your PHP error log.'];
    } catch (Throwable $e) {
        error_log('HRIS recruitment email failed: ' . $e->getMessage());
        return ['sent' => false, 'error' => $e->getMessage()];
    }
}

function rec_broadcast_event(string $type, array $payload = []): void {
    $wsNotifyUrl = trim((string)(getenv('WS_NOTIFY_URL') ?: 'http://127.0.0.1:8090/notify'));
    if ($wsNotifyUrl === '') return;
    $body = json_encode([
        'event' => [
            'type'      => $type,
            'timestamp' => date('c'),
            'data'      => $payload,
        ],
    ], JSON_UNESCAPED_UNICODE);

    if (function_exists('curl_init')) {
        $ch = curl_init($wsNotifyUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => $body,
            CURLOPT_HTTPHEADER        => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_TIMEOUT_MS        => 350,
            CURLOPT_CONNECTTIMEOUT_MS => 200,
        ]);
        @curl_exec($ch);
        @curl_close($ch);
    }
}

function rec_edit_employee_url(int $employeeId): string {
    if ($employeeId > 0) {
        return '/hr/edit_employee.php?id=' . $employeeId;
    }
    return '/hr/employees.php';
}

function rec_resolve_lookup_id(PDO $pdo, string $table, string $col, string $value): ?int {
    $value = trim($value);
    if ($value === '' || !rec_has_table($pdo, $table) || !rec_has_col($pdo, $table, $col)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT `id` FROM `{$table}` WHERE LOWER(TRIM(`{$col}`)) = LOWER(?) LIMIT 1");
        $stmt->execute([$value]);
        $found = $stmt->fetchColumn();
        return $found !== false ? (int)$found : null;
    } catch (Throwable $e) {
        return null;
    }
}

function rec_sync_candidate_to_onboarding(PDO $pdo, array $cand, int $candId, int $employeeId = 0, string $stageLabel = 'Hired'): void {
    if (!rec_has_table($pdo, 'employee_onboarding')) {
        return;
    }
    try {
        $colsStmt = $pdo->query("SHOW COLUMNS FROM `employee_onboarding`");
        $onbCols = [];
        foreach ($colsStmt->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
            $onbCols[strtolower((string)$cRow['Field'])] = (string)$cRow['Field'];
        }

        $email    = trim((string)($cand['email'] ?? ''));
        $fullName = trim((string)($cand['full_name'] ?? 'New Hire'));
        $deptName = trim((string)($cand['department'] ?? 'General')) ?: 'General';
        $roleName = trim((string)($cand['job_title'] ?? 'New Hire')) ?: 'New Hire';
        $codeVal  = 'EMP-' . date('Ymd') . '-' . str_pad((string)max(1, $candId), 3, '0', STR_PAD_LEFT);

        if ($employeeId > 0 && rec_has_table($pdo, 'employees')) {
            $empCodeCol = rec_has_col($pdo, 'employees', 'employee_id') ? 'employee_id' : (rec_has_col($pdo, 'employees', 'employee_code') ? 'employee_code' : null);
            if ($empCodeCol !== null) {
                $cStmt = $pdo->prepare("SELECT `{$empCodeCol}` FROM `employees` WHERE `id` = ? LIMIT 1");
                $cStmt->execute([$employeeId]);
                $dbCode = trim((string)($cStmt->fetchColumn() ?: ''));
                if ($dbCode !== '') $codeVal = $dbCode;
            }
        }

        $existingId = 0;
        if ($email !== '' && isset($onbCols['email'])) {
            $fStmt = $pdo->prepare("SELECT `id` FROM `employee_onboarding` WHERE LOWER(TRIM(`{$onbCols['email']}`)) = LOWER(?) LIMIT 1");
            $fStmt->execute([$email]);
            $existingId = (int)($fStmt->fetchColumn() ?: 0);
        }
        if ($existingId <= 0 && $candId > 0 && isset($onbCols['candidate_id'])) {
            $fStmt = $pdo->prepare("SELECT `id` FROM `employee_onboarding` WHERE `{$onbCols['candidate_id']}` = ? LIMIT 1");
            $fStmt->execute([$candId]);
            $existingId = (int)($fStmt->fetchColumn() ?: 0);
        }

        $rowMap = [];
        if (isset($onbCols['candidate_id']) && $candId > 0)       $rowMap[$onbCols['candidate_id']] = $candId;
        if (isset($onbCols['employee_id']) && $employeeId > 0)    $rowMap[$onbCols['employee_id']]  = $employeeId;
        if (isset($onbCols['employee_code']))                     $rowMap[$onbCols['employee_code']] = $codeVal;
        if (isset($onbCols['full_name']))                         $rowMap[$onbCols['full_name']]    = $fullName;
        if (isset($onbCols['email']) && $email !== '')            $rowMap[$onbCols['email']]        = $email;
        if (isset($onbCols['department']))                        $rowMap[$onbCols['department']]   = $deptName;
        if (isset($onbCols['job_title']))                         $rowMap[$onbCols['job_title']]    = $roleName;
        if (isset($onbCols['stage']))                             $rowMap[$onbCols['stage']]        = $stageLabel;
        if (isset($onbCols['status']))                            $rowMap[$onbCols['status']]       = 'In Progress';
        if (isset($onbCols['step_info']))                         $rowMap[$onbCols['step_info']]    = 1;

        if ($existingId > 0) {
            $sets = [];
            $vals = [];
            foreach ($rowMap as $col => $val) {
                $sets[] = "`{$col}` = ?";
                $vals[] = $val;
            }
            $vals[] = $existingId;
            $pdo->prepare("UPDATE `employee_onboarding` SET " . implode(', ', $sets) . " WHERE `id` = ?")->execute($vals);
        } elseif (!empty($rowMap)) {
            $colsSql = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($rowMap)));
            $phSql   = implode(', ', array_fill(0, count($rowMap), '?'));
            $pdo->prepare("INSERT INTO `employee_onboarding` ({$colsSql}) VALUES ({$phSql})")->execute(array_values($rowMap));
        }
    } catch (Throwable $e) {
        error_log('HRIS onboarding sync error: ' . $e->getMessage());
    }
}

function rec_remove_candidate_from_onboarding(PDO $pdo, array $cand, int $candId): void {
    if (!rec_has_table($pdo, 'employee_onboarding')) {
        return;
    }
    try {
        $email = trim((string)($cand['email'] ?? ''));
        if ($candId > 0 && rec_has_col($pdo, 'employee_onboarding', 'candidate_id')) {
            $pdo->prepare("DELETE FROM `employee_onboarding` WHERE `candidate_id` = ?")->execute([$candId]);
        }
        if ($email !== '' && rec_has_col($pdo, 'employee_onboarding', 'email')) {
            $pdo->prepare("DELETE FROM `employee_onboarding` WHERE LOWER(TRIM(`email`)) = LOWER(?)")->execute([$email]);
        }
    } catch (Throwable $e) {}
}

function rec_hire_candidate_to_employee_and_onboarding(PDO $pdo, array $cand, int $candId): array {
    $employeeId = 0;

    if (rec_has_table($pdo, 'employees')) {
        try {
            $empColsStmt = $pdo->query("SHOW COLUMNS FROM `employees`");
            $empCols = [];
            foreach ($empColsStmt->fetchAll(PDO::FETCH_ASSOC) as $colRow) {
                $empCols[strtolower((string)$colRow['Field'])] = (string)$colRow['Field'];
            }

            $email = trim((string)($cand['email'] ?? ''));
            $fullName = trim((string)($cand['full_name'] ?? 'New Hire'));

            if ($email !== '' && isset($empCols['email'])) {
                $findStmt = $pdo->prepare("SELECT `id` FROM `employees` WHERE LOWER(TRIM(`{$empCols['email']}`)) = LOWER(?) LIMIT 1");
                $findStmt->execute([$email]);
                $employeeId = (int)($findStmt->fetchColumn() ?: 0);
            }

            if ($employeeId <= 0 && $fullName !== '' && isset($empCols['first_name']) && isset($empCols['last_name'])) {
                $findByName = $pdo->prepare("
                    SELECT `id` FROM `employees`
                    WHERE LOWER(TRIM(CONCAT(`{$empCols['first_name']}`, ' ', `{$empCols['last_name']}`))) = LOWER(?)
                    LIMIT 1
                ");
                $findByName->execute([$fullName]);
                $employeeId = (int)($findByName->fetchColumn() ?: 0);
            }

            $parts = preg_split('/\s+/', $fullName) ?: ['New', 'Hire'];
            $firstName = array_shift($parts) ?: 'New';
            $lastName  = count($parts) ? implode(' ', $parts) : 'Hire';
            $codeVal   = 'EMP-' . date('Ymd') . '-' . str_pad((string)max(1, $candId), 3, '0', STR_PAD_LEFT);
            $deptName  = trim((string)($cand['department'] ?? 'General')) ?: 'General';
            $roleTitle = trim((string)($cand['job_title'] ?? 'New Hire')) ?: 'New Hire';
            $deptId    = rec_resolve_lookup_id($pdo, 'departments', 'name', $deptName);
            $posId     = rec_resolve_lookup_id($pdo, 'positions', 'title', $roleTitle);

            if ($employeeId <= 0) {
                $insertMap = [];
                if (isset($empCols['employee_id']))       $insertMap[$empCols['employee_id']]       = $codeVal;
                if (isset($empCols['employee_code']))     $insertMap[$empCols['employee_code']]     = $codeVal;
                if (isset($empCols['first_name']))        $insertMap[$empCols['first_name']]        = $firstName;
                if (isset($empCols['last_name']))         $insertMap[$empCols['last_name']]         = $lastName;
                if (isset($empCols['full_name']))         $insertMap[$empCols['full_name']]         = $fullName;
                if (isset($empCols['email']))             $insertMap[$empCols['email']]             = $email;
                if (isset($empCols['phone']) && !empty($cand['phone'])) {
                    $insertMap[$empCols['phone']] = (string)$cand['phone'];
                }
                if (isset($empCols['department_id']))     $insertMap[$empCols['department_id']]     = $deptId;
                if (isset($empCols['position_id']))       $insertMap[$empCols['position_id']]       = $posId;
                if (isset($empCols['department']))        $insertMap[$empCols['department']]        = $deptName;
                if (isset($empCols['job_title']))         $insertMap[$empCols['job_title']]         = $roleTitle;
                if (isset($empCols['rank']))              $insertMap[$empCols['rank']]              = 'employee';
                if (isset($empCols['date_hired']))        $insertMap[$empCols['date_hired']]        = date('Y-m-d');
                if (isset($empCols['employment_type']))   $insertMap[$empCols['employment_type']]   = 'Full-time';
                if (isset($empCols['employment_status'])) $insertMap[$empCols['employment_status']] = 'Active';
                if (isset($empCols['status']))            $insertMap[$empCols['status']]            = 'Onboarding';

                if (!empty($insertMap)) {
                    $colList = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($insertMap)));
                    $placeholders = implode(', ', array_fill(0, count($insertMap), '?'));
                    $insEmp = $pdo->prepare("INSERT INTO `employees` ({$colList}) VALUES ({$placeholders})");
                    $insEmp->execute(array_values($insertMap));
                    $employeeId = (int)$pdo->lastInsertId();
                }
            }
        } catch (Throwable $e) {
            error_log('HRIS hire-to-employee sync error: ' . $e->getMessage());
        }
    }

    rec_sync_candidate_to_onboarding($pdo, $cand, $candId, $employeeId, 'Hired');

    return [
        'employee_id'       => $employeeId,
        'edit_employee_url' => rec_edit_employee_url($employeeId),
    ];
}

function rec_fetch_pipeline(PDO $pdo): array {
    $vacancies  = [];
    $applicants = [];
    $interviews = [];
    $hired      = [];
    $rejected   = [];

    $empByEmail = [];
    $empByName  = [];
    if (rec_has_table($pdo, 'employees')) {
        try {
            if (rec_has_col($pdo, 'employees', 'email')) {
                $eRows = $pdo->query("SELECT `id`, LOWER(TRIM(`email`)) AS email_key FROM `employees` WHERE `email` IS NOT NULL AND TRIM(`email`) <> ''")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($eRows as $er) {
                    $k = (string)($er['email_key'] ?? '');
                    if ($k !== '' && !isset($empByEmail[$k])) {
                        $empByEmail[$k] = (int)$er['id'];
                    }
                }
            }
            if (rec_has_col($pdo, 'employees', 'first_name') && rec_has_col($pdo, 'employees', 'last_name')) {
                $nRows = $pdo->query("SELECT `id`, LOWER(TRIM(CONCAT(`first_name`, ' ', `last_name`))) AS name_key FROM `employees`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($nRows as $nr) {
                    $k = (string)($nr['name_key'] ?? '');
                    if ($k !== '' && !isset($empByName[$k])) {
                        $empByName[$k] = (int)$nr['id'];
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    try {
        if (rec_has_table($pdo, 'job_vacancies')) {
            $vacancies = $pdo->query("SELECT * FROM `job_vacancies` ORDER BY `id` DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if (rec_has_table($pdo, 'job_applicants')) {
            $rows = $pdo->query("SELECT * FROM `job_applicants` ORDER BY `id` DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $st = strtolower(trim((string)($r['stage'] ?? 'applied')));
                $dt = trim((string)($r['interview_at'] ?? ''));
                $r['interview_formatted'] = ($dt !== '' && $dt !== '0000-00-00 00:00:00')
                    ? date('M j, Y • g:i A', strtotime($dt))
                    : 'TBD';
                $r['interview_input_val'] = ($dt !== '' && $dt !== '0000-00-00 00:00:00')
                    ? date('Y-m-d\TH:i', strtotime($dt))
                    : '';

                $decDt = trim((string)($r['decided_at'] ?? ''));
                $r['decided_formatted'] = ($decDt !== '' && $decDt !== '0000-00-00 00:00:00')
                    ? date('M j, Y • g:i A', strtotime($decDt))
                    : '';

                $emailKey = strtolower(trim((string)($r['email'] ?? '')));
                $nameKey  = strtolower(trim((string)($r['full_name'] ?? '')));
                $matchedEmpId = 0;
                if ($emailKey !== '' && isset($empByEmail[$emailKey])) {
                    $matchedEmpId = (int)$empByEmail[$emailKey];
                } elseif ($nameKey !== '' && isset($empByName[$nameKey])) {
                    $matchedEmpId = (int)$empByName[$nameKey];
                }

                $r['employee_id'] = $matchedEmpId;
                $r['edit_employee_url'] = rec_edit_employee_url($matchedEmpId);

                if (in_array($st, ['interview', 'interviews', 'scheduled'], true)) {
                    $interviews[] = $r;
                } elseif (in_array($st, ['hired', 'approved'], true)) {
                    $hired[] = $r;
                } elseif (in_array($st, ['rejected', 'declined', 'not hired'], true)) {
                    $rejected[] = $r;
                } else {
                    $applicants[] = $r;
                }
            }
        }

        foreach ($hired as &$hrRow) {
            if ((int)($hrRow['employee_id'] ?? 0) <= 0) {
                $provisioned = rec_hire_candidate_to_employee_and_onboarding($pdo, $hrRow, (int)($hrRow['id'] ?? 0));
                $empId = (int)($provisioned['employee_id'] ?? 0);
                $hrRow['employee_id'] = $empId;
                $hrRow['edit_employee_url'] = rec_edit_employee_url($empId);
            } else {
                rec_sync_candidate_to_onboarding($pdo, $hrRow, (int)($hrRow['id'] ?? 0), (int)$hrRow['employee_id'], 'Hired');
            }
        }
        unset($hrRow);
    } catch (Throwable $e) {}

    return [
        'vacancies'  => $vacancies,
        'applicants' => $applicants,
        'interviews' => $interviews,
        'hired'      => $hired,
        'rejected'   => $rejected,
    ];
}

try {
    rec_ensure_schema($pdo);
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rec_ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = trim((string)($_POST['rec_ajax'] ?? ''));

    try {
        if ($action === 'schedule_interview') {
            $candId      = (int)($_POST['candidate_id'] ?? 0);
            $interviewAt = trim((string)($_POST['interview_at'] ?? ''));
            $mode        = trim((string)($_POST['interview_mode'] ?? 'Online / Video Call'));
            $location    = trim((string)($_POST['interview_location'] ?? ''));
            $interviewer = trim((string)($_POST['interviewer_name'] ?? ''));
            $notes       = trim((string)($_POST['interview_notes'] ?? ''));
            $candEmail   = trim((string)($_POST['candidate_email'] ?? ''));
            $sendEmail   = ((string)($_POST['send_email'] ?? '1')) === '1';

            if ($candId <= 0) {
                throw new RuntimeException('Invalid candidate selected.');
            }
            if ($interviewAt === '') {
                throw new RuntimeException('Please select an interview date and time.');
            }

            $ts = strtotime($interviewAt);
            if ($ts === false) {
                throw new RuntimeException('Invalid interview date/time format.');
            }
            $sqlDatetime = date('Y-m-d H:i:s', $ts);

            $stmt = $pdo->prepare("SELECT * FROM `job_applicants` WHERE `id` = ? LIMIT 1");
            $stmt->execute([$candId]);
            $cand = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cand) {
                throw new RuntimeException('Candidate record not found.');
            }

            if ($candEmail !== '') {
                $cand['email'] = $candEmail;
            }

            $upd = $pdo->prepare("
                UPDATE `job_applicants`
                SET `email` = ?,
                    `stage` = 'Interview',
                    `interview_at` = ?,
                    `interview_mode` = ?,
                    `interview_location` = ?,
                    `interviewer_name` = ?,
                    `interview_notes` = ?
                WHERE `id` = ?
            ");
            $upd->execute([
                $cand['email'],
                $sqlDatetime,
                $mode,
                $location,
                $interviewer,
                $notes,
                $candId,
            ]);

            $emailResult = ['sent' => false, 'error' => ''];
            if ($sendEmail) {
                $tpl = rec_build_interview_email($cand, [
                    'interview_at'       => $sqlDatetime,
                    'interview_mode'     => $mode,
                    'interview_location' => $location,
                    'interviewer_name'   => $interviewer,
                    'interview_notes'    => $notes,
                ]);
                $emailResult = rec_send_candidate_email(
                    (string)$cand['email'],
                    (string)$cand['full_name'],
                    $tpl['subject'],
                    $tpl['html_body'],
                    $tpl['plain_body']
                );
                if ($emailResult['sent']) {
                    $pdo->prepare("UPDATE `job_applicants` SET `email_sent_at` = NOW() WHERE `id` = ?")->execute([$candId]);
                }
            }

            rec_broadcast_event('recruitment.interview_scheduled', [
                'candidate_id' => $candId,
                'interview_at' => $sqlDatetime,
            ]);

            echo json_encode([
                'ok'          => true,
                'email_sent'  => $emailResult['sent'],
                'email_error' => $emailResult['error'],
                'message'     => $emailResult['sent']
                    ? 'Interview scheduled and invitation letter sent to ' . $cand['email'] . '.'
                    : ('Interview schedule saved' . ($sendEmail ? ' (Email notice: ' . $emailResult['error'] . ')' : '.')),
                'server_time' => date('h:i:s A'),
                'pipeline'    => rec_fetch_pipeline($pdo),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'move_stage') {
            $candId       = (int)($_POST['candidate_id'] ?? 0);
            $stage        = trim((string)($_POST['stage'] ?? 'Interview'));
            $decisionNote = trim((string)($_POST['decision_notes'] ?? ''));
            $sendEmail    = ((string)($_POST['send_email'] ?? '1')) === '1';

            if ($candId <= 0) {
                throw new RuntimeException('Invalid candidate ID.');
            }

            $cStmt = $pdo->prepare("SELECT * FROM `job_applicants` WHERE `id` = ? LIMIT 1");
            $cStmt->execute([$candId]);
            $cand = $cStmt->fetch(PDO::FETCH_ASSOC);
            if (!$cand) {
                throw new RuntimeException('Candidate record not found.');
            }

            $upd = $pdo->prepare("
                UPDATE `job_applicants`
                SET `stage` = ?,
                    `decision_notes` = ?,
                    `decided_at` = NOW()
                WHERE `id` = ?
            ");
            $upd->execute([$stage, $decisionNote !== '' ? $decisionNote : null, $candId]);

            $employeeId  = 0;
            $editUrl     = '/hr/employees.php';
            $emailResult = ['sent' => false, 'error' => ''];

            if (strcasecmp($stage, 'Hired') === 0) {
                $hireMeta   = rec_hire_candidate_to_employee_and_onboarding($pdo, $cand, $candId);
                $employeeId = (int)$hireMeta['employee_id'];
                $editUrl    = (string)$hireMeta['edit_employee_url'];

                rec_broadcast_event('onboarding_updated', [
                    'candidate_id' => $candId,
                    'employee_id'  => $employeeId,
                    'stage'        => 'Hired',
                ]);
                rec_broadcast_event('recruitment.hired', [
                    'candidate_id' => $candId,
                    'employee_id'  => $employeeId,
                ]);

                $msg = 'Candidate approved & hired — added to Hiring and Onboarding.';
            } elseif (strcasecmp($stage, 'Rejected') === 0) {
                rec_remove_candidate_from_onboarding($pdo, $cand, $candId);

                if ($sendEmail) {
                    $tpl = rec_build_rejection_email($cand, $decisionNote);
                    $emailResult = rec_send_candidate_email(
                        (string)$cand['email'],
                        (string)$cand['full_name'],
                        $tpl['subject'],
                        $tpl['html_body'],
                        $tpl['plain_body']
                    );
                    if ($emailResult['sent']) {
                        $pdo->prepare("UPDATE `job_applicants` SET `email_sent_at` = NOW() WHERE `id` = ?")->execute([$candId]);
                    }
                }

                rec_broadcast_event('recruitment.rejected', [
                    'candidate_id' => $candId,
                ]);
                rec_broadcast_event('onboarding_updated', [
                    'candidate_id' => $candId,
                    'stage'        => 'Rejected',
                ]);

                $msg = $emailResult['sent']
                    ? 'Candidate rejected and rejection email sent to ' . $cand['email'] . '.'
                    : ('Candidate marked as rejected' . ($sendEmail ? ' (Email notice: ' . $emailResult['error'] . ')' : '.'));
            } else {
                $msg = 'Candidate moved to ' . $stage . '.';
            }

            rec_broadcast_event('recruitment.stage_updated', [
                'candidate_id' => $candId,
                'employee_id'  => $employeeId,
                'stage'        => $stage,
            ]);

            echo json_encode([
                'ok'                => true,
                'employee_id'       => $employeeId,
                'edit_employee_url' => $editUrl,
                'email_sent'        => $emailResult['sent'],
                'email_error'       => $emailResult['error'],
                'message'           => $msg,
                'server_time'       => date('h:i:s A'),
                'pipeline'          => rec_fetch_pipeline($pdo),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'post_job') {
            $title = trim((string)($_POST['title'] ?? ''));
            $dept  = trim((string)($_POST['department'] ?? 'General'));
            $type  = trim((string)($_POST['employment_type'] ?? 'Full-time'));
            $open  = max(1, (int)($_POST['openings'] ?? 1));
            $desc  = trim((string)($_POST['description'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('Job title is required.');
            }
            $stmt = $pdo->prepare("INSERT INTO `job_vacancies` (`title`, `department`, `employment_type`, `openings`, `description`) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$title, $dept, $type, $open, $desc]);
            rec_broadcast_event('recruitment.vacancy_created', ['title' => $title]);

            echo json_encode([
                'ok'          => true,
                'message'     => 'Job vacancy posted.',
                'server_time' => date('h:i:s A'),
                'pipeline'    => rec_fetch_pipeline($pdo),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'live_state') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode([
        'ok'          => true,
        'server_time' => date('h:i:s A'),
        'pipeline'    => rec_fetch_pipeline($pdo),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pipeline = rec_fetch_pipeline($pdo);
$vacanciesList  = is_array($pipeline['vacancies'] ?? null) ? $pipeline['vacancies'] : [];
$applicantsList = is_array($pipeline['applicants'] ?? null) ? $pipeline['applicants'] : [];
$interviewsList = is_array($pipeline['interviews'] ?? null) ? $pipeline['interviews'] : [];
$hiredList      = is_array($pipeline['hired'] ?? null) ? $pipeline['hired'] : [];
$rejectedList   = is_array($pipeline['rejected'] ?? null) ? $pipeline['rejected'] : [];

$activeTab = trim((string)($_GET['tab'] ?? 'interviews'));
if (!in_array($activeTab, ['vacancies', 'applicants', 'interviews', 'hiring', 'rejected'], true)) {
    $activeTab = 'interviews';
}

$hrHeader = __DIR__ . '/../templates/hrTemplates/header.php';
if (is_file($hrHeader)) {
    require_once $hrHeader;
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<script>
  if (!window.jQuery) {
    document.write('<script src="https://code.jquery.com/jquery-3.7.1.min.js"><\/script>');
  }
</script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<style>
  .rec-wrap { max-width: 1320px; margin: 22px auto 56px; padding: 0 22px; color: #f8fafc; }
  .rec-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
  .rec-title { margin: 0; font-size: 26px; font-weight: 700; color: #ffffff; }
  .rec-sub { margin: 4px 0 0; font-size: 13px; color: #94a3b8; }
  .rec-live-pill {
    display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 999px;
    font-size: 12px; font-weight: 600; background: rgba(16, 185, 129, 0.14); color: #34d399;
    border: 1px solid rgba(52, 211, 153, 0.35);
  }
  .rec-live-dot { width: 8px; height: 8px; border-radius: 50%; background: #34d399; box-shadow: 0 0 8px #34d399; }

  .rec-toolbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
  .rec-tabs {
    display: inline-flex; flex-wrap: wrap; gap: 4px; background: #0f172a; padding: 5px;
    border-radius: 12px; border: 1px solid rgba(148,163,184,0.18);
  }
  .rec-tab {
    padding: 8px 16px; border-radius: 9px; border: none; background: transparent;
    color: #94a3b8; font-size: 13px; font-weight: 600; cursor: pointer; transition: 0.15s;
  }
  .rec-tab:hover { color: #ffffff; }
  .rec-tab.is-active { background: #2563eb; color: #ffffff; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35); }

  .rec-btn {
    padding: 8px 14px; border-radius: 9px; border: none; background: #2563eb; color: #ffffff;
    font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none;
  }
  .rec-btn:hover { background: #1d4ed8; }
  .rec-btn-sched { background: #0ea5e9; color: #ffffff; }
  .rec-btn-sched:hover { background: #0284c7; }
  .rec-btn-hire { background: #16a34a; color: #ffffff; }
  .rec-btn-hire:hover { background: #15803d; }
  .rec-btn-reject { background: #dc2626; color: #ffffff; }
  .rec-btn-reject:hover { background: #b91c1c; }
  .rec-btn-ghost { background: #1e293b; color: #f8fafc; border: 1px solid #334155; }
  .rec-btn-ghost:hover { background: #334155; color: #ffffff; }

  .rec-card { background: #111827; border: 1px solid rgba(148,163,184,0.18); border-radius: 14px; overflow: hidden; }
  .rec-table { width: 100% !important; border-collapse: collapse; font-size: 13.5px; color: #f8fafc; }
  .rec-table th, .rec-table td { padding: 14px 16px; border-bottom: 1px solid rgba(148,163,184,0.14); text-align: left; }
  .rec-table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; background: #0f172a; }
  .rec-table tbody tr { background: #111827 !important; }
  .rec-table tbody tr:hover { background: #162033 !important; }
  .rec-table tr:last-child td { border-bottom: none; }

  /* DataTables Dark Theme Overrides */
  .dataTables_wrapper {
    padding: 16px;
    color: #cbd5e1;
    font-size: 13px;
  }
  .dataTables_wrapper .dataTables_length { margin-bottom: 12px; }
  .dataTables_wrapper .dataTables_filter { margin-bottom: 12px; }
  .dataTables_wrapper .dataTables_length label,
  .dataTables_wrapper .dataTables_filter label,
  .dataTables_wrapper .dataTables_info,
  .dataTables_wrapper .dataTables_empty {
    color: #94a3b8 !important;
    font-size: 12.5px;
  }
  .dataTables_wrapper .dataTables_length select,
  .dataTables_wrapper .dataTables_filter input {
    background: #0f172a !important;
    color: #f8fafc !important;
    border: 1px solid #334155 !important;
    border-radius: 8px;
    padding: 5px 10px;
    margin: 0 5px;
    outline: none;
  }
  .dataTables_wrapper .dataTables_filter input:focus,
  .dataTables_wrapper .dataTables_length select:focus {
    border-color: #2563eb !important;
  }
  .dataTables_wrapper .dataTables_info,
  .dataTables_wrapper .dataTables_paginate {
    margin-top: 14px;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button {
    color: #cbd5e1 !important;
    background: #0f172a !important;
    border: 1px solid #334155 !important;
    border-radius: 8px !important;
    padding: 4px 10px !important;
    margin: 0 2px !important;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button.current,
  .dataTables_wrapper .dataTables_paginate .paginate_button:hover:not(.disabled) {
    background: #2563eb !important;
    color: #ffffff !important;
    border-color: #2563eb !important;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
    color: #64748b !important;
    background: #0f172a !important;
    opacity: 0.55;
  }
  table.dataTable.no-footer {
    border-bottom: 1px solid rgba(148,163,184,0.14) !important;
  }

  .rec-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
  .rec-b-tbd { background: rgba(245,158,11,0.16); color: #fbbf24; border: 1px solid rgba(251,191,36,0.3); }
  .rec-b-ok { background: rgba(16,185,129,0.16); color: #34d399; border: 1px solid rgba(52,211,153,0.3); }
  .rec-b-mail { background: rgba(59,130,246,0.16); color: #93c5fd; border: 1px solid rgba(147,197,253,0.3); }
  .rec-b-rej { background: rgba(239,68,68,0.16); color: #fca5a5; border: 1px solid rgba(248,113,113,0.3); }

  .rec-toast {
    position: fixed; bottom: 22px; right: 22px; z-index: 1200; max-width: 440px;
    background: #0f172a; border: 1px solid #3b82f6; color: #f8fafc; padding: 12px 16px;
    border-radius: 10px; box-shadow: 0 14px 32px rgba(0,0,0,0.5); font-size: 13px; display: none;
  }

  .rec-modal-backdrop {
    position: fixed; inset: 0; background: rgba(2, 6, 23, 0.78); backdrop-filter: blur(4px);
    z-index: 1100; display: none; align-items: center; justify-content: center; padding: 16px;
  }
  .rec-modal-backdrop.is-open { display: flex; }
  .rec-modal {
    width: 100%; max-width: 960px; max-height: 92vh; overflow-y: auto;
    background: #111827; border: 1px solid rgba(148,163,184,0.25); border-radius: 16px;
    padding: 22px; box-shadow: 0 24px 56px rgba(0,0,0,0.65);
  }
  .rec-modal-grid { display: grid; grid-template-columns: 1fr 1.05fr; gap: 18px; margin-top: 14px; }
  @media (max-width: 860px) { .rec-modal-grid { grid-template-columns: 1fr; } }
  .rec-field { margin-bottom: 11px; }
  .rec-label { display: block; font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #94a3b8; margin-bottom: 5px; }
  .rec-input, .rec-select, .rec-textarea {
    width: 100%; padding: 9px 11px; border-radius: 8px; border: 1px solid #334155;
    background: #0f172a; color: #f8fafc; font-size: 13px; box-sizing: border-box;
  }
  .rec-letter-preview {
    background: #ffffff; color: #1e293b; border-radius: 12px; padding: 18px 20px;
    border: 1px solid #cbd5e1; font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; line-height: 1.55;
  }
</style>

<div class="rec-wrap">
  <div class="rec-header">
    <div>
      <h1 class="rec-title">Recruitment</h1>
      <p class="rec-sub">Live recruitment pipeline for job vacancies, applicants, interviews, hiring, and onboarding.</p>
    </div>
    <div class="rec-live-pill">
      <span class="rec-live-dot"></span>
      <span id="recLiveStatus">Live Sync • <?= date('h:i:s A') ?></span>
    </div>
  </div>

  <div class="rec-toolbar">
    <div class="rec-tabs" id="recTabs">
      <button type="button" class="rec-tab <?= $activeTab === 'vacancies' ? 'is-active' : '' ?>" data-tab="vacancies">
        Job Vacancies (<span id="cntVacancies"><?= count($vacanciesList) ?></span>)
      </button>
      <button type="button" class="rec-tab <?= $activeTab === 'applicants' ? 'is-active' : '' ?>" data-tab="applicants">
        Applicants (<span id="cntApplicants"><?= count($applicantsList) ?></span>)
      </button>
      <button type="button" class="rec-tab <?= $activeTab === 'interviews' ? 'is-active' : '' ?>" data-tab="interviews">
        Interviews (<span id="cntInterviews"><?= count($interviewsList) ?></span>)
      </button>
      <button type="button" class="rec-tab <?= $activeTab === 'hiring' ? 'is-active' : '' ?>" data-tab="hiring">
        Hiring (<span id="cntHiring"><?= count($hiredList) ?></span>)
      </button>
      <button type="button" class="rec-tab <?= $activeTab === 'rejected' ? 'is-active' : '' ?>" data-tab="rejected">
        Rejected (<span id="cntRejected"><?= count($rejectedList) ?></span>)
      </button>
    </div>

    <button type="button" class="rec-btn" id="btnOpenPostJob">+ Post Job</button>
  </div>

  <div class="rec-card rec-pane" id="pane-vacancies" style="display:<?= $activeTab === 'vacancies' ? 'block' : 'none' ?>;">
    <table class="rec-table display" id="tblVacancies">
      <thead>
        <tr>
          <th>Job Title</th>
          <th>Department</th>
          <th>Type</th>
          <th>Openings</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody id="tbodyVacancies"></tbody>
    </table>
  </div>

  <div class="rec-card rec-pane" id="pane-applicants" style="display:<?= $activeTab === 'applicants' ? 'block' : 'none' ?>;">
    <table class="rec-table display" id="tblApplicants">
      <thead>
        <tr>
          <th>Candidate</th>
          <th>Email</th>
          <th>Job Title</th>
          <th>Department</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="tbodyApplicants"></tbody>
    </table>
  </div>

  <div class="rec-card rec-pane" id="pane-interviews" style="display:<?= $activeTab === 'interviews' ? 'block' : 'none' ?>;">
    <table class="rec-table display" id="tblInterviews">
      <thead>
        <tr>
          <th>Candidate</th>
          <th>Job Title</th>
          <th>Department</th>
          <th>Interview Schedule</th>
          <th>Interview Status</th>
          <th>Decision &amp; Action</th>
        </tr>
      </thead>
      <tbody id="tbodyInterviews"></tbody>
    </table>
  </div>

  <div class="rec-card rec-pane" id="pane-hiring" style="display:<?= $activeTab === 'hiring' ? 'block' : 'none' ?>;">
    <table class="rec-table display" id="tblHiring">
      <thead>
        <tr>
          <th>Candidate</th>
          <th>Job Title</th>
          <th>Department</th>
          <th>Interview Date</th>
          <th>Status &amp; Employee Record</th>
        </tr>
      </thead>
      <tbody id="tbodyHiring"></tbody>
    </table>
  </div>

  <div class="rec-card rec-pane" id="pane-rejected" style="display:<?= $activeTab === 'rejected' ? 'block' : 'none' ?>;">
    <table class="rec-table display" id="tblRejected">
      <thead>
        <tr>
          <th>Candidate</th>
          <th>Job Title</th>
          <th>Department</th>
          <th>Interview Date</th>
          <th>Status &amp; Remarks</th>
        </tr>
      </thead>
      <tbody id="tbodyRejected"></tbody>
    </table>
  </div>
</div>

<!-- MODAL: SCHEDULE INTERVIEW & EMAIL LETTER -->
<div class="rec-modal-backdrop" id="schedModalBackdrop">
  <div class="rec-modal">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
      <div>
        <h2 style="margin:0; font-size:18px; color:#ffffff;">Schedule Interview &amp; Send Email Invitation</h2>
        <p style="margin:3px 0 0; font-size:12.5px; color:#94a3b8;">Set the interview date/time and send the formatted letter directly to the applicant's email.</p>
      </div>
      <button type="button" class="rec-btn rec-btn-ghost" id="btnCloseSchedModal">✕ Close</button>
    </div>

    <form id="schedInterviewForm">
      <input type="hidden" name="rec_ajax" value="schedule_interview">
      <input type="hidden" name="candidate_id" id="schedCandId" value="">

      <div class="rec-modal-grid">
        <div>
          <div class="rec-field">
            <label class="rec-label">Applicant Name &amp; Role</label>
            <input type="text" id="schedCandSummary" class="rec-input" readonly>
          </div>
          <div class="rec-field">
            <label class="rec-label">Applicant Email Address</label>
            <input type="email" name="candidate_email" id="schedCandEmail" class="rec-input" required placeholder="applicant@example.com">
          </div>
          <div class="rec-field">
            <label class="rec-label">Interview Date &amp; Time</label>
            <input type="datetime-local" name="interview_at" id="schedDateTime" class="rec-input" required>
          </div>
          <div class="rec-field">
            <label class="rec-label">Interview Format</label>
            <select name="interview_mode" id="schedMode" class="rec-select">
              <option value="Online / Video Call (Google Meet)">Online / Video Call (Google Meet)</option>
              <option value="On-Site / Office Interview">On-Site / Office Interview</option>
              <option value="Phone Screening">Phone Screening</option>
              <option value="Technical Panel Interview">Technical Panel Interview</option>
            </select>
          </div>
          <div class="rec-field">
            <label class="rec-label">Meeting Link or Office Address</label>
            <input type="text" name="interview_location" id="schedLocation" class="rec-input" placeholder="https://meet.google.com/... or HR Office, 2nd Floor" required>
          </div>
          <div class="rec-field">
            <label class="rec-label">Interviewer Name / Panel</label>
            <input type="text" name="interviewer_name" id="schedInterviewer" class="rec-input" value="<?= htmlspecialchars((string)($_SESSION['full_name'] ?? 'HR Recruitment Team'), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="rec-field">
            <label class="rec-label">Additional Notes for Candidate (Optional)</label>
            <textarea name="interview_notes" id="schedNotes" rows="2" class="rec-textarea" placeholder="Please bring a valid ID and a copy of your updated resume / portfolio."></textarea>
          </div>
          <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:#cbd5e1; margin:10px 0 14px; cursor:pointer;">
            <input type="checkbox" name="send_email" id="schedSendEmailChk" value="1" checked>
            Send formatted interview invitation letter to applicant's email now
          </label>
          <div style="display:flex; gap:10px;">
            <button type="submit" class="rec-btn" id="btnSubmitSched">Save Schedule &amp; Send Email</button>
            <button type="button" class="rec-btn rec-btn-ghost" id="btnCancelSched">Cancel</button>
          </div>
        </div>

        <div>
          <label class="rec-label">Live Email Invitation Letter Preview (Applicant View)</label>
          <div class="rec-letter-preview" id="schedLetterPreview"></div>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: REJECT CANDIDATE & SEND REJECTION EMAIL -->
<div class="rec-modal-backdrop" id="rejectModalBackdrop">
  <div class="rec-modal" style="max-width:560px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
      <div>
        <h2 style="margin:0; font-size:18px; color:#ffffff;">Reject Candidate &amp; Send Email Notice</h2>
        <p style="margin:3px 0 0; font-size:12.5px; color:#94a3b8;">Mark this applicant as rejected and notify them via email that they were not hired.</p>
      </div>
      <button type="button" class="rec-btn rec-btn-ghost" id="btnCloseRejectModal">✕</button>
    </div>

    <form id="rejectCandidateForm" style="margin-top:14px;">
      <input type="hidden" name="rec_ajax" value="move_stage">
      <input type="hidden" name="stage" value="Rejected">
      <input type="hidden" name="candidate_id" id="rejectCandId" value="">

      <div class="rec-field">
        <label class="rec-label">Candidate</label>
        <input type="text" id="rejectCandSummary" class="rec-input" readonly>
      </div>
      <div class="rec-field">
        <label class="rec-label">Feedback / Reason (Optional — included in email)</label>
        <textarea name="decision_notes" id="rejectDecisionNotes" rows="3" class="rec-textarea" placeholder="e.g., We decided to move forward with another candidate whose experience more closely matches the role requirements."></textarea>
      </div>
      <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:#cbd5e1; margin:10px 0 14px; cursor:pointer;">
        <input type="checkbox" name="send_email" id="rejectSendEmailChk" value="1" checked>
        Send rejection email to candidate now
      </label>
      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button type="button" class="rec-btn rec-btn-ghost" id="btnCancelReject">Cancel</button>
        <button type="submit" class="rec-btn rec-btn-reject" id="btnSubmitReject">Confirm Reject &amp; Send Email</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: POST JOB -->
<div class="rec-modal-backdrop" id="postJobBackdrop">
  <div class="rec-modal" style="max-width:500px;">
    <div style="display:flex; justify-content:space-between; align-items:center;">
      <h2 style="margin:0; font-size:18px;">Post New Job Vacancy</h2>
      <button type="button" class="rec-btn rec-btn-ghost" id="btnClosePostJob">✕</button>
    </div>
    <form id="postJobForm" style="margin-top:14px;">
      <input type="hidden" name="rec_ajax" value="post_job">
      <div class="rec-field">
        <label class="rec-label">Job Title</label>
        <input type="text" name="title" class="rec-input" required placeholder="e.g., Software Developer">
      </div>
      <div class="rec-field">
        <label class="rec-label">Department</label>
        <input type="text" name="department" class="rec-input" required placeholder="e.g., IT">
      </div>
      <div class="rec-field">
        <label class="rec-label">Employment Type</label>
        <select name="employment_type" class="rec-select">
          <option value="Full-time">Full-time</option>
          <option value="Part-time">Part-time</option>
          <option value="Contract">Contract</option>
          <option value="Internship">Internship</option>
        </select>
      </div>
      <div class="rec-field">
        <label class="rec-label">Openings</label>
        <input type="number" name="openings" min="1" value="1" class="rec-input">
      </div>
      <button type="submit" class="rec-btn" style="width:100%; justify-content:center;">Publish Job Vacancy</button>
    </form>
  </div>
</div>

<div class="rec-toast" id="recToast"></div>

<script>
(function () {
  let pipeline = <?= json_encode($pipeline, JSON_UNESCAPED_UNICODE) ?>;
  let activeTab = <?= json_encode($activeTab, JSON_UNESCAPED_UNICODE) ?>;
  let activeCandidate = null;
  let lastPipelineHash = '';

  const TABLE_CONFIG = {
    tblVacancies:  { emptyMsg: 'No job vacancies posted yet.' },
    tblApplicants: { emptyMsg: 'No pending applicants in queue.' },
    tblInterviews: { emptyMsg: 'No pending interviews.' },
    tblHiring:     { emptyMsg: 'No hired candidates yet.' },
    tblRejected:   { emptyMsg: 'No rejected candidates.' }
  };
  const TABLE_IDS = Object.keys(TABLE_CONFIG);
  const dtInstances = {};

  function esc(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function showToast(msg, isErr) {
    const el = document.getElementById('recToast');
    if (!el) return;
    el.textContent = msg;
    el.style.borderColor = isErr ? '#ef4444' : '#10b981';
    el.style.display = 'block';
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.style.display = 'none'; }, 5200);
  }

  function formatHumanDateTime(val) {
    if (!val) return { dateStr: 'To Be Determined', timeStr: '' };
    const d = new Date(val);
    if (isNaN(d.getTime())) return { dateStr: val, timeStr: '' };
    const dateStr = d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    const timeStr = d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) + ' (PHT)';
    return { dateStr, timeStr };
  }

  function updateLetterPreview() {
    const box = document.getElementById('schedLetterPreview');
    if (!box || !activeCandidate) return;

    const dtVal = document.getElementById('schedDateTime').value;
    const mode = document.getElementById('schedMode').value || 'Online / Video Call';
    const loc = document.getElementById('schedLocation').value || 'To be provided';
    const interviewer = document.getElementById('schedInterviewer').value || 'HR Recruitment Team';
    const notes = document.getElementById('schedNotes').value.trim();
    const { dateStr, timeStr } = formatHumanDateTime(dtVal);

    const notesBlock = notes
      ? `<div style="margin-top:12px;padding:10px 12px;background:#f8fafc;border-left:3px solid #2563eb;border-radius:4px;font-size:12.5px;">
           <strong>Additional Instructions:</strong><br>${esc(notes).replace(/\n/g, '<br>')}
         </div>`
      : '';

    box.innerHTML = `
      <div style="background:#0f172a;color:#ffffff;padding:14px 16px;border-radius:8px;margin-bottom:14px;">
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.08em;color:#93c5fd;font-weight:700;">HRIS Recruitment • Official Notice</div>
        <div style="font-size:16px;font-weight:700;margin-top:3px;">Interview Invitation Letter</div>
      </div>
      <p style="margin:0 0 10px;">Dear <strong>${esc(activeCandidate.full_name)}</strong>,</p>
      <p style="margin:0 0 12px;">
        Thank you for applying for the <strong style="color:#2563eb;">${esc(activeCandidate.job_title)}</strong> position
        in our <strong>${esc(activeCandidate.department)}</strong> Department. We are pleased to invite you to an interview with the following schedule:
      </p>
      <table style="width:100%;border-collapse:collapse;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;font-size:12.5px;">
        <tr><td style="padding:7px 10px;color:#64748b;font-weight:600;width:34%;">Position:</td><td style="padding:7px 10px;font-weight:700;">${esc(activeCandidate.job_title)} (${esc(activeCandidate.department)})</td></tr>
        <tr><td style="padding:7px 10px;color:#64748b;font-weight:600;">Date:</td><td style="padding:7px 10px;font-weight:700;">${esc(dateStr)}</td></tr>
        <tr><td style="padding:7px 10px;color:#64748b;font-weight:600;">Time:</td><td style="padding:7px 10px;font-weight:700;">${esc(timeStr || '—')}</td></tr>
        <tr><td style="padding:7px 10px;color:#64748b;font-weight:600;">Format:</td><td style="padding:7px 10px;">${esc(mode)}</td></tr>
        <tr><td style="padding:7px 10px;color:#64748b;font-weight:600;">Location / Link:</td><td style="padding:7px 10px;color:#2563eb;font-weight:600;">${esc(loc)}</td></tr>
        <tr><td style="padding:7px 10px;color:#64748b;font-weight:600;">Interviewer:</td><td style="padding:7px 10px;">${esc(interviewer)}</td></tr>
      </table>
      ${notesBlock}
      <p style="margin:12px 0 8px;">Please reply to this email to confirm your availability for the scheduled date and time.</p>
      <p style="margin:12px 0 0;">Best regards,<br><strong>${esc(interviewer)}</strong><br><span style="color:#64748b;font-size:12px;">Human Resources Department</span></p>
    `;
  }

  function openScheduleModal(cand) {
    activeCandidate = cand;
    document.getElementById('schedCandId').value = cand.id;
    document.getElementById('schedCandSummary').value = `${cand.full_name} — ${cand.job_title} (${cand.department})`;
    document.getElementById('schedCandEmail').value = cand.email || '';

    if (cand.interview_input_val) {
      document.getElementById('schedDateTime').value = cand.interview_input_val;
    } else {
      const tomorrow = new Date(Date.now() + 86400000);
      tomorrow.setHours(10, 0, 0, 0);
      const localIso = new Date(tomorrow.getTime() - tomorrow.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
      document.getElementById('schedDateTime').value = localIso;
    }

    if (cand.interview_mode) document.getElementById('schedMode').value = cand.interview_mode;
    document.getElementById('schedLocation').value = cand.interview_location || '';
    if (cand.interviewer_name) document.getElementById('schedInterviewer').value = cand.interviewer_name;
    document.getElementById('schedNotes').value = cand.interview_notes || '';

    updateLetterPreview();
    document.getElementById('schedModalBackdrop').classList.add('is-open');
  }

  function openRejectModal(cand) {
    document.getElementById('rejectCandId').value = cand.id;
    document.getElementById('rejectCandSummary').value = `${cand.full_name} (${cand.email}) — ${cand.job_title}`;
    document.getElementById('rejectDecisionNotes').value = '';
    document.getElementById('rejectModalBackdrop').classList.add('is-open');
  }

  function broadcastRealtime(types) {
    try {
      if ('BroadcastChannel' in window) {
        const bc = new BroadcastChannel('hris_realtime_sync');
        types.forEach(t => bc.postMessage({ type: t, ts: Date.now() }));
      }
    } catch (_) {}
  }

  function destroyDataTables() {
    if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) return;
    TABLE_IDS.forEach((id) => {
      const $tbl = jQuery('#' + id);
      if ($tbl.length && jQuery.fn.DataTable.isDataTable($tbl)) {
        $tbl.DataTable().destroy();
      }
      dtInstances[id] = null;
    });
  }

  function initDataTables() {
    if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) return;

    TABLE_IDS.forEach((id) => {
      const $tbl = jQuery('#' + id);
      if (!$tbl.length) return;

      const lastColIdx = $tbl.find('thead th').length - 1;
      const cfg = TABLE_CONFIG[id] || { emptyMsg: 'No records available.' };

      dtInstances[id] = $tbl.DataTable({
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50],
        order: [],
        autoWidth: false,
        columnDefs: [
          { orderable: false, targets: lastColIdx }
        ],
        language: {
          emptyTable: cfg.emptyMsg,
          zeroRecords: 'No matching records found.',
          search: 'Search:',
          lengthMenu: 'Show _MENU_ entries',
          info: 'Showing _START_ to _END_ of _TOTAL_ records',
          infoEmpty: 'Showing 0 to 0 of 0 records',
          paginate: { previous: 'Prev', next: 'Next' }
        }
      });
    });
  }

  function renderPipeline(force) {
    const newHash = JSON.stringify(pipeline);
    if (!force && newHash === lastPipelineHash) {
      return;
    }
    lastPipelineHash = newHash;

    destroyDataTables();

    const vacancies  = Array.isArray(pipeline.vacancies) ? pipeline.vacancies : [];
    const applicants = Array.isArray(pipeline.applicants) ? pipeline.applicants : [];
    const interviews = Array.isArray(pipeline.interviews) ? pipeline.interviews : [];
    const hired      = Array.isArray(pipeline.hired) ? pipeline.hired : [];
    const rejected   = Array.isArray(pipeline.rejected) ? pipeline.rejected : [];

    document.getElementById('cntVacancies').textContent  = vacancies.length;
    document.getElementById('cntApplicants').textContent = applicants.length;
    document.getElementById('cntInterviews').textContent = interviews.length;
    document.getElementById('cntHiring').textContent     = hired.length;
    document.getElementById('cntRejected').textContent   = rejected.length;

    document.getElementById('tbodyVacancies').innerHTML = vacancies.map(v => `
      <tr>
        <td><strong>${esc(v.title)}</strong></td>
        <td>${esc(v.department)}</td>
        <td>${esc(v.employment_type)}</td>
        <td>${esc(v.openings)}</td>
        <td><span class="rec-badge rec-b-ok">${esc(v.status)}</span></td>
      </tr>
    `).join('');

    document.getElementById('tbodyApplicants').innerHTML = applicants.map(a => `
      <tr>
        <td><strong>${esc(a.full_name)}</strong></td>
        <td>${esc(a.email)}</td>
        <td>${esc(a.job_title)}</td>
        <td>${esc(a.department)}</td>
        <td>
          <button type="button" class="rec-btn rec-btn-sched" data-sched-id="${a.id}">Schedule Interview</button>
        </td>
      </tr>
    `).join('');

    document.getElementById('tbodyInterviews').innerHTML = interviews.map(i => {
      const isTbd = !i.interview_formatted || i.interview_formatted === 'TBD';
      const badge = isTbd
        ? `<span class="rec-badge rec-b-tbd">TBD</span>`
        : `<span class="rec-badge rec-b-ok">${esc(i.interview_formatted)}</span>
           <div style="font-size:11.5px;color:#94a3b8;margin-top:4px;">${esc(i.interview_mode || '')}${i.interview_location ? ' • ' + esc(i.interview_location) : ''}</div>`;
      const mailBadge = i.email_sent_at
        ? `<div style="margin-top:4px;"><span class="rec-badge rec-b-mail">✉ Emailed ${esc(i.email)}</span></div>`
        : `<div style="font-size:11px;color:#64748b;margin-top:3px;">${esc(i.email || 'No email')}</div>`;
      const statusBadge = isTbd
        ? `<span class="rec-badge rec-b-tbd">Awaiting Schedule</span>`
        : `<span class="rec-badge rec-b-tbd">Pending Approval</span>`;

      return `
        <tr>
          <td>
            <strong>${esc(i.full_name)}</strong>
            ${mailBadge}
          </td>
          <td>${esc(i.job_title)}</td>
          <td>${esc(i.department)}</td>
          <td>${badge}</td>
          <td>${statusBadge}</td>
          <td>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
              <button type="button" class="rec-btn rec-btn-sched" data-sched-id="${i.id}">
                ${isTbd ? 'Schedule &amp; Email' : 'Reschedule &amp; Email'}
              </button>
              <button type="button" class="rec-btn rec-btn-hire" data-hire-id="${i.id}">
                Approve &amp; Hire
              </button>
              <button type="button" class="rec-btn rec-btn-reject" data-reject-id="${i.id}">
                Reject &amp; Email
              </button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    document.getElementById('tbodyHiring').innerHTML = hired.map(h => {
      const editHref = h.edit_employee_url || '/hr/employees.php';
      return `
        <tr>
          <td><strong>${esc(h.full_name)}</strong><div style="font-size:11.5px;color:#94a3b8;">${esc(h.email)}</div></td>
          <td>${esc(h.job_title)}</td>
          <td>${esc(h.department)}</td>
          <td>${esc(h.interview_formatted)}</td>
          <td>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
              <span class="rec-badge rec-b-ok">Approved &amp; Hired</span>
              <span class="rec-badge rec-b-mail">In Onboarding</span>
              <a href="${esc(editHref)}" class="rec-btn rec-btn-ghost">
                Edit Employee
              </a>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    document.getElementById('tbodyRejected').innerHTML = rejected.map(r => {
      const mailTag = r.email_sent_at
        ? `<span class="rec-badge rec-b-mail">✉ Rejection Emailed</span>`
        : '';
      const noteLine = r.decision_notes
        ? `<div style="font-size:11.5px;color:#94a3b8;margin-top:4px;">${esc(r.decision_notes)}</div>`
        : '';
      return `
        <tr>
          <td><strong>${esc(r.full_name)}</strong><div style="font-size:11.5px;color:#94a3b8;">${esc(r.email)}</div></td>
          <td>${esc(r.job_title)}</td>
          <td>${esc(r.department)}</td>
          <td>${esc(r.interview_formatted)}</td>
          <td>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
              <span class="rec-badge rec-b-rej">Rejected / Not Hired</span>
              ${mailTag}
            </div>
            ${noteLine}
          </td>
        </tr>
      `;
    }).join('');

    initDataTables();
  }

  function switchTab(tab) {
    activeTab = tab;
    document.querySelectorAll('.rec-tab').forEach(btn => {
      btn.classList.toggle('is-active', btn.getAttribute('data-tab') === tab);
    });
    document.querySelectorAll('.rec-pane').forEach(pane => {
      pane.style.display = pane.id === 'pane-' + tab ? 'block' : 'none';
    });
    const activeTblId = 'tbl' + tab.charAt(0).toUpperCase() + tab.slice(1);
    if (dtInstances[activeTblId]) {
      dtInstances[activeTblId].columns.adjust().draw(false);
    }
  }

  document.querySelectorAll('.rec-tab').forEach(btn => {
    btn.addEventListener('click', () => switchTab(btn.getAttribute('data-tab')));
  });

  document.addEventListener('click', async (e) => {
    const schedBtn = e.target.closest('[data-sched-id]');
    if (schedBtn) {
      const id = Number(schedBtn.getAttribute('data-sched-id'));
      const all = [...(pipeline.interviews || []), ...(pipeline.applicants || [])];
      const found = all.find(x => Number(x.id) === id);
      if (found) openScheduleModal(found);
      return;
    }

    const rejectBtn = e.target.closest('[data-reject-id]');
    if (rejectBtn) {
      const id = Number(rejectBtn.getAttribute('data-reject-id'));
      const found = (pipeline.interviews || []).find(x => Number(x.id) === id);
      if (found) openRejectModal(found);
      return;
    }

    const hireBtn = e.target.closest('[data-hire-id]');
    if (hireBtn) {
      const id = Number(hireBtn.getAttribute('data-hire-id'));
      hireBtn.disabled = true;
      hireBtn.textContent = 'Approving...';
      try {
        const fd = new FormData();
        fd.append('rec_ajax', 'move_stage');
        fd.append('candidate_id', String(id));
        fd.append('stage', 'Hired');
        const res = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
          pipeline = data.pipeline;
          renderPipeline(true);
          switchTab('hiring');
          showToast(data.message, false);
          broadcastRealtime(['recruitment.hired', 'recruitment.stage_updated', 'onboarding_updated']);
        } else {
          showToast(data.error || 'Action failed.', true);
          hireBtn.disabled = false;
          hireBtn.textContent = 'Approve & Hire';
        }
      } catch (_) {
        showToast('Network error while approving hire.', true);
        hireBtn.disabled = false;
        hireBtn.textContent = 'Approve & Hire';
      }
    }
  });

  ['schedDateTime', 'schedMode', 'schedLocation', 'schedInterviewer', 'schedNotes'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updateLetterPreview);
    if (el) el.addEventListener('change', updateLetterPreview);
  });

  document.getElementById('btnCloseSchedModal').addEventListener('click', () => {
    document.getElementById('schedModalBackdrop').classList.remove('is-open');
  });
  document.getElementById('btnCancelSched').addEventListener('click', () => {
    document.getElementById('schedModalBackdrop').classList.remove('is-open');
  });

  document.getElementById('btnCloseRejectModal').addEventListener('click', () => {
    document.getElementById('rejectModalBackdrop').classList.remove('is-open');
  });
  document.getElementById('btnCancelReject').addEventListener('click', () => {
    document.getElementById('rejectModalBackdrop').classList.remove('is-open');
  });

  document.getElementById('schedInterviewForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = document.getElementById('btnSubmitSched');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving & Sending Email...';

    try {
      const fd = new FormData(e.target);
      const res = await fetch('', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        pipeline = data.pipeline;
        renderPipeline(true);
        document.getElementById('schedModalBackdrop').classList.remove('is-open');
        showToast(data.message, !data.email_sent && document.getElementById('schedSendEmailChk').checked);
        broadcastRealtime(['recruitment.interview_scheduled']);
      } else {
        showToast(data.error || 'Unable to schedule interview.', true);
      }
    } catch (err) {
      showToast('Network error while scheduling interview.', true);
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Save Schedule & Send Email';
    }
  });

  document.getElementById('rejectCandidateForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = document.getElementById('btnSubmitReject');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Rejecting & Sending Email...';

    try {
      const fd = new FormData(e.target);
      const res = await fetch('', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        pipeline = data.pipeline;
        renderPipeline(true);
        document.getElementById('rejectModalBackdrop').classList.remove('is-open');
        switchTab('rejected');
        showToast(data.message, !data.email_sent && document.getElementById('rejectSendEmailChk').checked);
        broadcastRealtime(['recruitment.rejected', 'recruitment.stage_updated', 'onboarding_updated']);
      } else {
        showToast(data.error || 'Unable to reject candidate.', true);
      }
    } catch (_) {
      showToast('Network error while rejecting candidate.', true);
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Confirm Reject & Send Email';
    }
  });

  document.getElementById('btnOpenPostJob').addEventListener('click', () => {
    document.getElementById('postJobBackdrop').classList.add('is-open');
  });
  document.getElementById('btnClosePostJob').addEventListener('click', () => {
    document.getElementById('postJobBackdrop').classList.remove('is-open');
  });
  document.getElementById('postJobForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      pipeline = data.pipeline;
      renderPipeline(true);
      e.target.reset();
      document.getElementById('postJobBackdrop').classList.remove('is-open');
      showToast(data.message, false);
      broadcastRealtime(['recruitment.vacancy_created']);
    } else {
      showToast(data.error || 'Failed to post job.', true);
    }
  });

  async function pollLiveRecruitment() {
    try {
      const res = await fetch('?ajax=live_state&_t=' + Date.now(), { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      if (data.ok && data.pipeline) {
        pipeline = data.pipeline;
        renderPipeline(false);
        if (data.server_time) {
          document.getElementById('recLiveStatus').textContent = 'Live Sync • ' + data.server_time;
        }
      }
    } catch (_) {}
  }

  renderPipeline(true);
  setInterval(pollLiveRecruitment, 3000);

  try {
    if ('BroadcastChannel' in window) {
      const bc = new BroadcastChannel('hris_realtime_sync');
      bc.onmessage = (ev) => {
        if (ev && ev.data && String(ev.data.type || '').startsWith('recruitment.')) {
          pollLiveRecruitment();
        }
      };
    }
  } catch (_) {}

  (async function connectWs() {
    try {
      for (const url of ['/api/realtime_token.php', '../api/realtime_token.php']) {
        const r = await fetch(url, { cache: 'no-store' });
        if (!r.ok) continue;
        const j = await r.json();
        const token = j.token || (j.data && j.data.token) || '';
        if (!token) continue;
        const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const ws = new WebSocket(`${proto}//${window.location.hostname}:8090/ws?token=${encodeURIComponent(token)}`);
        ws.onmessage = (ev) => {
          try {
            const pkt = JSON.parse(ev.data);
            const t = (pkt && pkt.event && pkt.event.type) || '';
            if (t.startsWith('recruitment.')) pollLiveRecruitment();
          } catch (_) {}
        };
        break;
      }
    } catch (_) {}
  })();
})();
</script>