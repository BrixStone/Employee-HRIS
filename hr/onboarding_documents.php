<?php
// hr/onboarding_documents.php

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

function hris_ensure_docs_schema(PDO $pdo): void
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
            UNIQUE KEY `uq_onb_emp_code` (`employee_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `employee_onboarding_documents` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `onboarding_id` INT UNSIGNED NOT NULL,
            `gov_id_type` VARCHAR(80) NULL,
            `gov_id_number` VARCHAR(80) NULL,
            `gov_id_verified` TINYINT(1) NOT NULL DEFAULT 0,
            `clearance_type` VARCHAR(80) NULL,
            `clearance_ref` VARCHAR(100) NULL,
            `clearance_verified` TINYINT(1) NOT NULL DEFAULT 0,
            `job_offer_signed_date` DATE NULL,
            `job_offer_verified` TINYINT(1) NOT NULL DEFAULT 0,
            `sss_number` VARCHAR(40) NULL,
            `philhealth_number` VARCHAR(40) NULL,
            `pagibig_number` VARCHAR(40) NULL,
            `tin_number` VARCHAR(40) NULL,
            `emergency_contact_name` VARCHAR(160) NULL,
            `emergency_contact_phone` VARCHAR(60) NULL,
            `remarks` TEXT NULL,
            `verified_by` VARCHAR(160) NULL,
            `verified_at` DATETIME NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_doc_onboarding_id` (`onboarding_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function hris_recompute_onb_status_from_docs(PDO $pdo, int $onbId): string
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

function hris_fetch_onboarding_with_docs(PDO $pdo): array
{
    hris_ensure_docs_schema($pdo);

    $stmt = $pdo->query("
        SELECT
            o.*,
            d.gov_id_type,
            d.gov_id_number,
            d.gov_id_verified,
            d.clearance_type,
            d.clearance_ref,
            d.clearance_verified,
            d.job_offer_signed_date,
            d.job_offer_verified,
            d.sss_number,
            d.philhealth_number,
            d.pagibig_number,
            d.tin_number,
            d.emergency_contact_name,
            d.emergency_contact_phone,
            d.remarks,
            d.verified_by,
            d.verified_at
        FROM `employee_onboarding` o
        LEFT JOIN `employee_onboarding_documents` d ON d.onboarding_id = o.id
        ORDER BY o.id ASC
    ");
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pre_employment_docs') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    hris_ensure_docs_schema($pdo);

    $onbId = (int)($_POST['onboarding_id'] ?? 0);
    if ($onbId <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Please select a valid employee.']);
        exit;
    }

    $govIdType      = trim((string)($_POST['gov_id_type'] ?? ''));
    $govIdNumber    = trim((string)($_POST['gov_id_number'] ?? ''));
    $govIdVerified  = !empty($_POST['gov_id_verified']) ? 1 : 0;

    $clrType        = trim((string)($_POST['clearance_type'] ?? ''));
    $clrRef         = trim((string)($_POST['clearance_ref'] ?? ''));
    $clrVerified    = !empty($_POST['clearance_verified']) ? 1 : 0;

    $offerDateRaw   = trim((string)($_POST['job_offer_signed_date'] ?? ''));
    $offerDate      = $offerDateRaw !== '' ? $offerDateRaw : null;
    $offerVerified  = !empty($_POST['job_offer_verified']) ? 1 : 0;

    $sssNumber      = trim((string)($_POST['sss_number'] ?? ''));
    $philhealthNum  = trim((string)($_POST['philhealth_number'] ?? ''));
    $pagibigNum     = trim((string)($_POST['pagibig_number'] ?? ''));
    $tinNumber      = trim((string)($_POST['tin_number'] ?? ''));

    $emergName      = trim((string)($_POST['emergency_contact_name'] ?? ''));
    $emergPhone     = trim((string)($_POST['emergency_contact_phone'] ?? ''));
    $remarks        = trim((string)($_POST['remarks'] ?? ''));

    $upsert = $pdo->prepare("
        INSERT INTO `employee_onboarding_documents` (
            `onboarding_id`,
            `gov_id_type`, `gov_id_number`, `gov_id_verified`,
            `clearance_type`, `clearance_ref`, `clearance_verified`,
            `job_offer_signed_date`, `job_offer_verified`,
            `sss_number`, `philhealth_number`, `pagibig_number`, `tin_number`,
            `emergency_contact_name`, `emergency_contact_phone`,
            `remarks`, `verified_by`, `verified_at`
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )
        ON DUPLICATE KEY UPDATE
            `gov_id_type` = VALUES(`gov_id_type`),
            `gov_id_number` = VALUES(`gov_id_number`),
            `gov_id_verified` = VALUES(`gov_id_verified`),
            `clearance_type` = VALUES(`clearance_type`),
            `clearance_ref` = VALUES(`clearance_ref`),
            `clearance_verified` = VALUES(`clearance_verified`),
            `job_offer_signed_date` = VALUES(`job_offer_signed_date`),
            `job_offer_verified` = VALUES(`job_offer_verified`),
            `sss_number` = VALUES(`sss_number`),
            `philhealth_number` = VALUES(`philhealth_number`),
            `pagibig_number` = VALUES(`pagibig_number`),
            `tin_number` = VALUES(`tin_number`),
            `emergency_contact_name` = VALUES(`emergency_contact_name`),
            `emergency_contact_phone` = VALUES(`emergency_contact_phone`),
            `remarks` = VALUES(`remarks`),
            `verified_by` = VALUES(`verified_by`),
            `verified_at` = NOW()
    ");

    $upsert->execute([
        $onbId,
        $govIdType !== '' ? $govIdType : null,
        $govIdNumber !== '' ? $govIdNumber : null,
        $govIdVerified,
        $clrType !== '' ? $clrType : null,
        $clrRef !== '' ? $clrRef : null,
        $clrVerified,
        $offerDate,
        $offerVerified,
        $sssNumber !== '' ? $sssNumber : null,
        $philhealthNum !== '' ? $philhealthNum : null,
        $pagibigNum !== '' ? $pagibigNum : null,
        $tinNumber !== '' ? $tinNumber : null,
        $emergName !== '' ? $emergName : null,
        $emergPhone !== '' ? $emergPhone : null,
        $remarks !== '' ? $remarks : null,
        $hrActorName,
    ]);

    // Automatically mark Step 2 (Pre-Employment Documents) as Done (1) in employee_onboarding
    $pdo->prepare("UPDATE `employee_onboarding` SET `step_documents` = 1 WHERE `id` = ?")
        ->execute([$onbId]);

    $newStatus = hris_recompute_onb_status_from_docs($pdo, $onbId);

    // Broadcast real-time update to any open /hr/onboarding.php tab
    if (function_exists('hris_broadcast_onboarding_event')) {
        hris_broadcast_onboarding_event('onboarding_updated', [
            'onb_id' => $onbId,
            'step'   => 'step_documents',
            'value'  => 1,
            'status' => $newStatus,
        ]);
    }

    $records = hris_fetch_onboarding_with_docs($pdo);
    echo json_encode([
        'ok'      => true,
        'message' => $newStatus === 'Completed'
            ? 'Pre-Employment Documents saved! Step 2 marked Done and employee moved to Completed (4/4).'
            : 'Pre-Employment Documents saved to database! Step 2 is now marked Done in real time.',
        'records' => $records,
        'status'  => $newStatus,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$records = hris_fetch_onboarding_with_docs($pdo);
$preselectId = (int)($_GET['onb_id'] ?? 0);
if ($preselectId <= 0 && !empty($records)) {
    foreach ($records as $r) {
        if ((int)($r['step_documents'] ?? 0) === 0) {
            $preselectId = (int)$r['id'];
            break;
        }
    }
    if ($preselectId <= 0) {
        $preselectId = (int)$records[0]['id'];
    }
}

$pageTitle = 'Pre-Employment Documents';
require_once __DIR__ . '/../templates/hrTemplates/header.php';
?>

<style>
  .hr-doc-wrap { max-width: 1120px; margin: 22px auto 48px; padding: 0 20px; color: #e2e8f0; }
  .hr-doc-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
  .hr-doc-card {
    background: #111827; border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 16px; padding: 24px;
  }
  .hr-doc-section {
    background: #161f33; border: 1px solid rgba(148, 163, 184, 0.15);
    border-radius: 12px; padding: 16px 18px; margin-bottom: 16px;
  }
  .hr-doc-section-title {
    font-size: 14px; font-weight: 700; color: #ffffff; margin: 0 0 12px;
    display: flex; justify-content: space-between; align-items: center;
  }
  .hr-doc-grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
  .hr-doc-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
  @media (max-width: 820px) {
    .hr-doc-grid-2, .hr-doc-grid-4 { grid-template-columns: 1fr; }
  }
  .hr-label { display: block; font-size: 12px; font-weight: 600; color: #94a3b8; margin-bottom: 5px; }
  .hr-input {
    width: 100%; box-sizing: border-box; padding: 9px 12px; border-radius: 8px;
    border: 1px solid rgba(148, 163, 184, 0.35); background: #0b1120; color: #f8fafc; font-size: 13px;
  }
  .hr-check-row {
    display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600;
    color: #93c5fd; cursor: pointer; margin-top: 8px;
  }
  .hr-btn {
    padding: 9px 16px; border-radius: 9px; border: 1px solid rgba(148, 163, 184, 0.3);
    background: #1e293b; color: #f8fafc; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;
  }
  .hr-btn:hover { background: #334155; }
  .hr-btn-primary { background: #2563eb; border-color: #2563eb; color: #ffffff; }
  .hr-btn-primary:hover { background: #1d4ed8; }
  .hr-badge-done { background: rgba(16, 185, 129, 0.22); color: #6ee7b7; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
  .hr-badge-pend { background: rgba(245, 158, 11, 0.22); color: #fcd34d; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
</style>

<div class="hr-doc-wrap">
  <div class="hr-doc-head">
    <div>
      <h1 style="margin:0 0 4px; font-size:24px; font-weight:800; color:#ffffff;">Pre-Employment Documents Fill-Up</h1>
      <div style="font-size:13.5px; color:#94a3b8;">Record verified IDs, clearances, signed offer, and statutory numbers. Saving automatically marks Step 2 as Done in real time.</div>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <a href="/hr/onboarding.php" class="hr-btn">← Back to Onboarding Queue</a>
    </div>
  </div>

  <div id="hrDocBanner"></div>

  <div class="hr-doc-card">
    <form id="hrPreEmpDocsForm" method="post">
      <input type="hidden" name="action" value="save_pre_employment_docs">

      <!-- Employee Selector -->
      <div class="hr-doc-section">
        <div class="hr-doc-section-title">
          <span>Select New Employee</span>
          <span id="hrDocStepBadge" class="hr-badge-pend">Pending</span>
        </div>
        <div class="hr-doc-grid-2">
          <div>
            <label class="hr-label" for="hrDocEmpSelect">Employee in Onboarding Queue</label>
            <select name="onboarding_id" id="hrDocEmpSelect" class="hr-input" required></select>
          </div>
          <div>
            <label class="hr-label">Department &amp; Role</label>
            <input type="text" id="hrDocEmpRole" class="hr-input" readonly style="opacity:0.8;">
          </div>
        </div>
      </div>

      <!-- 1. Government ID & Clearance & Job Offer -->
      <div class="hr-doc-section">
        <div class="hr-doc-section-title"><span>1. Core Pre-Employment Requirements</span></div>
        <div class="hr-doc-grid-2">
          <div>
            <label class="hr-label">Primary Government ID Type</label>
            <select name="gov_id_type" id="docGovIdType" class="hr-input">
              <option value="">-- Select ID --</option>
              <option value="PhilSys National ID">PhilSys National ID</option>
              <option value="Passport">Philippine Passport</option>
              <option value="Driver's License">LTO Driver's License</option>
              <option value="UMID">UMID</option>
              <option value="PRC ID">PRC ID</option>
              <option value="Postal ID">Postal ID</option>
            </select>
          </div>
          <div>
            <label class="hr-label">Government ID Number</label>
            <input type="text" name="gov_id_number" id="docGovIdNumber" class="hr-input" placeholder="e.g. 1234-5678-9012">
            <label class="hr-check-row">
              <input type="checkbox" name="gov_id_verified" id="docGovIdVerified" value="1">
              <span>Valid Government ID Checked &amp; Verified</span>
            </label>
          </div>

          <div>
            <label class="hr-label">Background Clearance Type</label>
            <select name="clearance_type" id="docClearanceType" class="hr-input">
              <option value="">-- Select Clearance --</option>
              <option value="NBI Clearance">NBI Clearance</option>
              <option value="Police Clearance">National Police Clearance</option>
              <option value="Barangay Clearance">Barangay Clearance</option>
            </select>
          </div>
          <div>
            <label class="hr-label">Clearance Reference / Serial No.</label>
            <input type="text" name="clearance_ref" id="docClearanceRef" class="hr-input" placeholder="e.g. NBI-2026-884920">
            <label class="hr-check-row">
              <input type="checkbox" name="clearance_verified" id="docClearanceVerified" value="1">
              <span>NBI / Police Clearance Checked &amp; Valid</span>
            </label>
          </div>

          <div>
            <label class="hr-label">Signed Job Offer Date</label>
            <input type="date" name="job_offer_signed_date" id="docOfferDate" class="hr-input">
          </div>
          <div style="display:flex; align-items:flex-end;">
            <label class="hr-check-row" style="margin-bottom:8px;">
              <input type="checkbox" name="job_offer_verified" id="docOfferVerified" value="1">
              <span>Signed Job Offer &amp; Contract Received by HR</span>
            </label>
          </div>
        </div>
      </div>

      <!-- 2. Statutory & Government Numbers -->
      <div class="hr-doc-section">
        <div class="hr-doc-section-title"><span>2. Statutory &amp; Tax Numbers (Optional / If Available)</span></div>
        <div class="hr-doc-grid-4">
          <div>
            <label class="hr-label">SSS Number</label>
            <input type="text" name="sss_number" id="docSss" class="hr-input" placeholder="00-0000000-0">
          </div>
          <div>
            <label class="hr-label">PhilHealth Number</label>
            <input type="text" name="philhealth_number" id="docPhilhealth" class="hr-input" placeholder="00-000000000-0">
          </div>
          <div>
            <label class="hr-label">Pag-IBIG MID Number</label>
            <input type="text" name="pagibig_number" id="docPagibig" class="hr-input" placeholder="0000-0000-0000">
          </div>
          <div>
            <label class="hr-label">TIN Number</label>
            <input type="text" name="tin_number" id="docTin" class="hr-input" placeholder="000-000-000-000">
          </div>
        </div>
      </div>

      <!-- 3. Emergency Contact & HR Notes -->
      <div class="hr-doc-section">
        <div class="hr-doc-section-title"><span>3. Emergency Contact &amp; HR Verification Notes</span></div>
        <div class="hr-doc-grid-2" style="margin-bottom:12px;">
          <div>
            <label class="hr-label">Emergency Contact Full Name</label>
            <input type="text" name="emergency_contact_name" id="docEmergName" class="hr-input" placeholder="e.g. Maria Villanueva">
          </div>
          <div>
            <label class="hr-label">Emergency Contact Phone</label>
            <input type="text" name="emergency_contact_phone" id="docEmergPhone" class="hr-input" placeholder="e.g. 0917-123-4567">
          </div>
        </div>
        <div>
          <label class="hr-label">HR Remarks / Follow-Up Notes</label>
          <textarea name="remarks" id="docRemarks" rows="2" class="hr-input" placeholder="Any notes on submitted or to-follow documents..."></textarea>
        </div>
      </div>

      <div style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:12px;">
        <div id="hrDocLastVerified" style="font-size:12px; color:#94a3b8;"></div>
        <div style="display:flex; gap:10px;">
          <a href="/hr/onboarding.php" class="hr-btn">Cancel</a>
          <button type="submit" class="hr-btn hr-btn-primary" id="hrSaveDocsBtn">Save Documents &amp; Mark Step 2 Done</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
  (function () {
    let records = <?= json_encode($records, JSON_UNESCAPED_UNICODE) ?>;
    let currentOnbId = <?= (int)$preselectId ?>;

    const empSelect = document.getElementById('hrDocEmpSelect');
    const roleInput = document.getElementById('hrDocEmpRole');
    const stepBadge = document.getElementById('hrDocStepBadge');
    const lastVerEl = document.getElementById('hrDocLastVerified');

    function esc(v) {
      return String(v ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function populateDropdown() {
      if (!empSelect) return;
      empSelect.innerHTML = records.map(r => {
        const id = Number(r.id);
        const done = Number(r.step_documents || 0) === 1;
        const sel = id === currentOnbId ? 'selected' : '';
        return `<option value="${id}" ${sel}>${esc(r.full_name)} (${esc(r.employee_code)}) — ${done ? 'Docs Done' : 'Docs Pending'}</option>`;
      }).join('');
    }

    function fillFormForSelected() {
      const rec = records.find(r => Number(r.id) === currentOnbId) || records[0];
      if (!rec) return;
      currentOnbId = Number(rec.id);

      if (roleInput) roleInput.value = `${rec.department || ''} • ${rec.job_title || ''}`;
      const isDone = Number(rec.step_documents || 0) === 1;
      if (stepBadge) {
        stepBadge.className = isDone ? 'hr-badge-done' : 'hr-badge-pend';
        stepBadge.textContent = isDone ? 'Step 2: Done' : 'Step 2: Pending';
      }

      document.getElementById('docGovIdType').value = rec.gov_id_type || '';
      document.getElementById('docGovIdNumber').value = rec.gov_id_number || '';
      document.getElementById('docGovIdVerified').checked = Number(rec.gov_id_verified || 0) === 1;

      document.getElementById('docClearanceType').value = rec.clearance_type || '';
      document.getElementById('docClearanceRef').value = rec.clearance_ref || '';
      document.getElementById('docClearanceVerified').checked = Number(rec.clearance_verified || 0) === 1;

      document.getElementById('docOfferDate').value = rec.job_offer_signed_date || '';
      document.getElementById('docOfferVerified').checked = Number(rec.job_offer_verified || 0) === 1;

      document.getElementById('docSss').value = rec.sss_number || '';
      document.getElementById('docPhilhealth').value = rec.philhealth_number || '';
      document.getElementById('docPagibig').value = rec.pagibig_number || '';
      document.getElementById('docTin').value = rec.tin_number || '';

      document.getElementById('docEmergName').value = rec.emergency_contact_name || '';
      document.getElementById('docEmergPhone').value = rec.emergency_contact_phone || '';
      document.getElementById('docRemarks').value = rec.remarks || '';

      if (lastVerEl) {
        lastVerEl.textContent = rec.verified_by
          ? `Last verified by ${rec.verified_by}${rec.verified_at ? ' on ' + rec.verified_at : ''}`
          : 'Not yet filled up by HR.';
      }
    }

    populateDropdown();
    fillFormForSelected();

    if (empSelect) {
      empSelect.addEventListener('change', () => {
        currentOnbId = Number(empSelect.value || 0);
        fillFormForSelected();
      });
    }

    const form = document.getElementById('hrPreEmpDocsForm');
    const banner = document.getElementById('hrDocBanner');
    const saveBtn = document.getElementById('hrSaveDocsBtn');

    if (form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (saveBtn) saveBtn.disabled = true;
        try {
          const res = await fetch(window.location.pathname, {
            method: 'POST',
            body: new FormData(form),
            cache: 'no-store'
          });
          const data = await res.json();
          if (banner && data.message) {
            banner.innerHTML = data.ok
              ? `<div style="background:rgba(16,185,129,0.16); border:1px solid rgba(52,211,153,0.45); color:#6ee7b7; padding:12px 16px; border-radius:10px; margin-bottom:14px; font-size:13px; font-weight:600;">${esc(data.message)}</div>`
              : `<div style="background:rgba(239,68,68,0.16); border:1px solid rgba(248,113,113,0.45); color:#fca5a5; padding:12px 16px; border-radius:10px; margin-bottom:14px; font-size:13px; font-weight:600;">${esc(data.message)}</div>`;
          }
          if (data.ok && Array.isArray(data.records)) {
            records = data.records;
            populateDropdown();
            fillFormForSelected();
          }
        } catch (_) {
          if (banner) {
            banner.innerHTML = `<div style="background:rgba(239,68,68,0.16); border:1px solid rgba(248,113,113,0.45); color:#fca5a5; padding:12px 16px; border-radius:10px; margin-bottom:14px; font-size:13px; font-weight:600;">Network error while saving pre-employment documents.</div>`;
          }
        } finally {
          if (saveBtn) saveBtn.disabled = false;
        }
      });
    }
  })();
</script>

    </section>
  </main>
</body>

</html>