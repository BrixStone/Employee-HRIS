<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// Logged in, account still active, employee portal, allowed by the permission matrix
require_once __DIR__ . '/../authentication/guard.php';
require_access('employee_records', 'view', 'own', ['employee']);

$employeeId = $_SESSION['employee_id'] ?? null;

if (empty($employeeId)) {
    exit('No employee ID found in session.');
}

// Make sure the avatar column exists before we read the row, so the saved
// profile photo is always included (it persists in the DB per account).
require_once __DIR__ . '/../classes/Avatar.php';
Avatar::ensureColumn($pdo);

$stmt = $pdo->prepare("
    SELECT *
    FROM employees
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([':id' => $employeeId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    exit('Employee record not found for employee ID: '
        . htmlspecialchars((string)$employeeId, ENT_QUOTES, 'UTF-8'));
}

$firstName = $employee['first_name'] ?? '';
$lastName = $employee['last_name'] ?? '';
$fullName = trim($firstName . ' ' . $lastName);

$user = [
    'id' => $employee['id'],
    'name' => htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'),
    'full_name' => htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'),
    'email' => htmlspecialchars($employee['email'] ?? '', ENT_QUOTES, 'UTF-8'),
    // employees table stores the contact number in `phone`
    'phone_number' => htmlspecialchars(
        $employee['phone'] ?? 'Not Provided',
        ENT_QUOTES,
        'UTF-8'
    ),
    // employees table stores the status in `employment_status`
    'status' => htmlspecialchars(
        $employee['employment_status'] ?? 'Active',
        ENT_QUOTES,
        'UTF-8'
    ),
    // Uploaded picture when present, otherwise a generated placeholder.
    'avatar' => htmlspecialchars(Avatar::url($employee), ENT_QUOTES, 'UTF-8')
];

$isActive = strcasecmp($user['status'], 'Active') === 0;

// ---------- Profile strength: how complete is this profile? ----------
// Each item is true when that detail has been filled in.
$strengthChecks = [
    'Name'  => $fullName !== '',
    'Email' => !empty($employee['email']),
    'Phone' => !empty($employee['phone']),
    // true only when the employee uploaded their own photo
    'Photo' => strpos(Avatar::url($employee), '/uploads/avatars/') !== false,
];

// Count the true items and turn them into a percentage (e.g. 3 of 4 = 75%).
$strengthDone    = count(array_filter($strengthChecks));
$strengthPercent = (int) round($strengthDone / count($strengthChecks) * 100);

// Names of the things still missing, used for the tip under the bar.
$strengthMissing = array_keys(array_filter($strengthChecks, fn($done) => !$done));

// Flash message after an avatar upload redirect.
$avatarFlash = $_GET['avatar'] ?? '';
$avatarMessages = [
    'ok'       => ['ok',    'Profile photo updated.'],
    'invalid'  => ['error', 'That file is not a supported image (use JPG, PNG, GIF or WEBP).'],
    'toolarge' => ['error', 'That image is too large. Please choose a file under 3 MB.'],
    'error'    => ['error', 'Could not update your photo. Please try again.'],
];
[$avatarFlashKind, $avatarFlashText] = $avatarMessages[$avatarFlash] ?? [null, null];

// Shell configuration
$title = 'My Profile';
$eyebrow = 'ACCOUNT';
$subtitle = 'Your personal and employment details';
$pageCss = 'emp_profile';
require __DIR__ . '/../templates/employeeTemplates/header.php';
?>

<?php if ($avatarFlashText !== null): ?>
  <div class="alert alert-<?= $avatarFlashKind === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($avatarFlashText) ?></div>
<?php endif; ?>

<section class="profile-head card">
  <form id="profileAvatarForm" action="/employee/upload_avatar.php" method="post" enctype="multipart/form-data">
    <button type="button" class="profile-avatar-btn" id="profileAvatarBtn" title="Change profile photo" aria-label="Change profile photo">
      <img src="<?= $user['avatar'] ?>" alt="Profile avatar" class="profile-avatar">
      <span class="profile-avatar-edit" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z" stroke="currentColor" stroke-width="2"/>
          <path d="M4 8.5 5.4 6A2 2 0 0 1 7.1 5h9.8a2 2 0 0 1 1.7 1l1.4 2.5A2 2 0 0 1 21 9.6V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9.6c0-.38.1-.76.3-1.1Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
        </svg>
      </span>
    </button>
    <input type="file" name="avatar" id="profileAvatarInput" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
  </form>
  <div class="profile-identity">
    <h1><?= $user['name'] ?></h1>
    <p class="muted"><?= $user['email'] ?></p>
    <span class="pill <?= $isActive ? 'pill-active' : 'pill-inactive' ?>"><?= $user['status'] ?></span>
  </div>

  <!-- Profile strength meter: the bar fills up and the number counts up -->
  <div class="profile-strength">
    <div class="strength-top">
      <span class="strength-title">Profile strength</span>
      <strong class="strength-percent" id="strengthPercent" data-target="<?= $strengthPercent ?>"><?= $strengthPercent ?>%</strong>
    </div>

    <div class="strength-bar" role="progressbar" aria-label="Profile strength"
         aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $strengthPercent ?>">
      <!-- --fill tells the CSS how far the bar should grow -->
      <span class="strength-fill" style="--fill: <?= $strengthPercent ?>%"></span>
    </div>

    <ul class="strength-list">
      <?php foreach ($strengthChecks as $label => $done): ?>
        <li class="<?= $done ? 'done' : 'todo' ?>"><?= $done ? '✓' : '○' ?> <?= htmlspecialchars($label) ?></li>
      <?php endforeach; ?>
    </ul>

    <p class="strength-tip">
      <?php if (empty($strengthMissing)): ?>
        Your profile is complete. Nice work!
      <?php else: ?>
        Add your <?= htmlspecialchars(implode(' and ', $strengthMissing)) ?> to reach 100%.
      <?php endif; ?>
    </p>
  </div>
</section>

<article class="card">
  <div class="card-heading" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <div><p class="eyebrow">DETAILS</p><h2>Personal information</h2></div>
    <button type="button" id="editProfileBtn" class="primary-button">Edit profile</button>
  </div>

  <!-- Inline flash for save results (shown by JS) -->
  <div id="profileFlash" class="alert" style="display:none;margin-bottom:14px"></div>

  <!-- VIEW MODE -->
  <div id="profileView" class="info-grid">
    <div class="info-cell">
      <span class="info-label">Employee ID</span>
      <strong class="info-value" data-view="employee_code"><?= htmlspecialchars((string)($employee['employee_id'] ?? ('#' . $user['id']))) ?></strong>
    </div>
    <div class="info-cell">
      <span class="info-label">Full Name</span>
      <strong class="info-value" data-view="full_name"><?= $user['name'] ?></strong>
    </div>
    <div class="info-cell">
      <span class="info-label">Email Address</span>
      <strong class="info-value teal" data-view="email"><?= $user['email'] ?></strong>
    </div>
    <div class="info-cell">
      <span class="info-label">Phone Number</span>
      <strong class="info-value" data-view="phone"><?= $user['phone_number'] ?></strong>
    </div>
    <div class="info-cell">
      <span class="info-label">Gender</span>
      <strong class="info-value" data-view="gender"><?= htmlspecialchars($employee['gender'] ?? 'Not Provided') ?></strong>
    </div>
    <div class="info-cell">
      <span class="info-label">Date of Birth</span>
      <strong class="info-value" data-view="date_of_birth"><?= htmlspecialchars($employee['date_of_birth'] ?? 'Not Provided') ?></strong>
    </div>
    <div class="info-cell wide">
      <span class="info-label">Address</span>
      <strong class="info-value" data-view="address"><?= htmlspecialchars($employee['address'] ?? 'Not Provided') ?></strong>
    </div>
    <div class="info-cell wide">
      <span class="info-label">Employment Status</span>
      <strong class="info-value <?= $isActive ? 'green' : '' ?>"><?= $user['status'] ?></strong>
    </div>
  </div>

  <!-- EDIT MODE (hidden until "Edit profile" is clicked) -->
  <form id="profileForm" class="info-grid" style="display:none" novalidate>
    <!-- Locked: shown read-only so the employee can see them but not change them -->
    <div class="info-cell">
      <label class="info-label" for="f_employee_code">Employee ID <span class="muted">(locked)</span></label>
      <input id="f_employee_code" class="form-input" type="text"
             value="<?= htmlspecialchars((string)($employee['employee_id'] ?? '')) ?>" disabled>
    </div>
    <div class="info-cell">
      <label class="info-label" for="f_status">Employment Status <span class="muted">(locked)</span></label>
      <input id="f_status" class="form-input" type="text"
             value="<?= $user['status'] ?>" disabled>
    </div>

    <!-- Editable personal fields -->
    <div class="info-cell">
      <label class="info-label" for="f_first_name">First Name</label>
      <input id="f_first_name" name="first_name" class="form-input" type="text" maxlength="100"
             value="<?= htmlspecialchars($employee['first_name'] ?? '') ?>" required>
      <small class="field-error" data-err="first_name"></small>
    </div>
    <div class="info-cell">
      <label class="info-label" for="f_middle_name">Middle Name</label>
      <input id="f_middle_name" name="middle_name" class="form-input" type="text" maxlength="100"
             value="<?= htmlspecialchars($employee['middle_name'] ?? '') ?>">
      <small class="field-error" data-err="middle_name"></small>
    </div>
    <div class="info-cell">
      <label class="info-label" for="f_last_name">Last Name</label>
      <input id="f_last_name" name="last_name" class="form-input" type="text" maxlength="100"
             value="<?= htmlspecialchars($employee['last_name'] ?? '') ?>" required>
      <small class="field-error" data-err="last_name"></small>
    </div>
    <div class="info-cell">
      <label class="info-label" for="f_suffix">Suffix</label>
      <input id="f_suffix" name="suffix" class="form-input" type="text" maxlength="20"
             value="<?= htmlspecialchars($employee['suffix'] ?? '') ?>" placeholder="Jr., Sr., III">
      <small class="field-error" data-err="suffix"></small>
    </div>
    <div class="info-cell">
      <label class="info-label" for="f_email">Email Address</label>
      <input id="f_email" name="email" class="form-input" type="email" maxlength="150"
             value="<?= htmlspecialchars($employee['email'] ?? '') ?>">
      <small class="field-error" data-err="email"></small>
    </div>
    <div class="info-cell">
      <label class="info-label" for="f_phone">Phone Number</label>
      <input id="f_phone" name="phone" class="form-input" type="text" maxlength="30"
             value="<?= htmlspecialchars($employee['phone'] ?? '') ?>">
      <small class="field-error" data-err="phone"></small>
    </div>
    <div class="info-cell">
      <label class="info-label" for="f_gender">Gender</label>
      <select id="f_gender" name="gender" class="form-input">
        <?php $g = $employee['gender'] ?? ''; ?>
        <option value="" <?= $g === '' ? 'selected' : '' ?>>Not set</option>
        <?php foreach (['Male', 'Female', 'Other'] as $opt): ?>
          <option value="<?= $opt ?>" <?= strcasecmp($g, $opt) === 0 ? 'selected' : '' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
      <small class="field-error" data-err="gender"></small>
    </div>
    <div class="info-cell">
      <label class="info-label" for="f_dob">Date of Birth</label>
      <input id="f_dob" name="date_of_birth" class="form-input" type="date" max="<?= date('Y-m-d') ?>"
             value="<?= htmlspecialchars($employee['date_of_birth'] ?? '') ?>">
      <small class="field-error" data-err="date_of_birth"></small>
    </div>
    <div class="info-cell wide">
      <label class="info-label" for="f_address">Address</label>
      <input id="f_address" name="address" class="form-input" type="text" maxlength="1000"
             value="<?= htmlspecialchars($employee['address'] ?? '') ?>">
      <small class="field-error" data-err="address"></small>
    </div>

    <div class="info-cell wide" style="display:flex;gap:10px;margin-top:6px">
      <button type="submit" id="saveProfileBtn" class="primary-button">Save changes</button>
      <button type="button" id="cancelProfileBtn" class="ghost-button">Cancel</button>
    </div>
  </form>
</article>

<?php
// Feed the shared Pocket Profile modal (rendered by the footer) with this
// page's full details so "About Me" shows ID, phone and status too.
$pocketProfile = [
    'id'           => $user['id'],
    'email'        => $employee['email'] ?? '',
    'phone'        => $employee['phone'] ?? 'Not Provided',
    'status'       => $user['status'],
    'avatar'       => Avatar::url($employee),
    // Only the profile page offers the "change photo" control.
    'allow_upload' => true,
];

// Wire the on-page profile-head avatar to the file browser + auto-submit.
$footerExtra = <<<'HTML'
<style>
  .form-input{width:100%;box-sizing:border-box;background:#0a0e20;color:#fff;border:1px solid var(--cardborder,#281545);
    border-radius:10px;padding:9px 12px;font-size:14px;font-family:inherit;margin-top:4px}
  .form-input:focus{outline:none;border-color:#4763ff}
  .form-input:disabled{opacity:.6;cursor:not-allowed;background:#070a16}
  .field-error{display:block;color:#f87a8f;font-size:12px;margin-top:4px;min-height:0}
  .ghost-button{background:transparent;color:#c9c7d1;border:1px solid var(--cardborder,#281545);
    border-radius:10px;padding:9px 16px;font-size:14px;cursor:pointer;font-family:inherit}
  .ghost-button:hover{border-color:#4763ff}
  #profileFlash.alert-success{display:block;background:#06281f;border:1px solid #10b981;color:#6ee7b7;padding:10px 14px;border-radius:10px}
  #profileFlash.alert-error{display:block;background:#2a0b12;border:1px solid #f43f5e;color:#fda4af;padding:10px 14px;border-radius:10px}
</style>
<script>
  (function () {
    var input = document.getElementById('profileAvatarInput');
    var form  = document.getElementById('profileAvatarForm');
    if (!input || !form) return;
    function pick(e) { if (e) e.preventDefault(); input.click(); }
    var btn = document.getElementById('profileAvatarBtn');
    if (btn) btn.addEventListener('click', pick);
    input.addEventListener('change', function () {
      if (input.files && input.files.length > 0) form.submit();
    });
  })();
</script>

<script>
  // ---- Edit profile: toggle view/edit and PATCH /api/me/profile.php ----
  // Employee ID and Employment Status are shown disabled and never submitted,
  // so an employee can see them but cannot change them. The server enforces
  // the same rule, so this is UI convenience, not the security boundary.
  (function () {
    var editBtn   = document.getElementById('editProfileBtn');
    var cancelBtn = document.getElementById('cancelProfileBtn');
    var viewEl    = document.getElementById('profileView');
    var formEl    = document.getElementById('profileForm');
    var flash     = document.getElementById('profileFlash');
    var saveBtn   = document.getElementById('saveProfileBtn');
    if (!editBtn || !formEl || !viewEl) return;

    // The only fields an employee may change (must match EMPLOYEE_SELF_FIELDS).
    var EDITABLE = ['first_name','middle_name','last_name','suffix','date_of_birth','gender','phone','email','address'];

    function show(edit) {
      formEl.style.display = edit ? '' : 'none';
      viewEl.style.display = edit ? 'none' : '';
      editBtn.style.display = edit ? 'none' : '';
      if (!edit) clearErrors();
    }
    function clearErrors() {
      formEl.querySelectorAll('.field-error').forEach(function (e) { e.textContent = ''; });
      flash.style.display = 'none';
      flash.className = 'alert';
    }
    function setFlash(kind, msg) {
      flash.textContent = msg;
      flash.className = 'alert alert-' + kind;
      flash.style.display = 'block';
    }

    editBtn.addEventListener('click', function () { show(true); });
    cancelBtn.addEventListener('click', function () { show(false); });

    formEl.addEventListener('submit', function (e) {
      e.preventDefault();
      clearErrors();

      // Collect only the editable fields. Never send employee_id/status.
      var payload = {};
      EDITABLE.forEach(function (name) {
        var el = formEl.querySelector('[name="' + name + '"]');
        if (el) payload[name] = el.value.trim();
      });

      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';

      fetch('/api/me/profile.php', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      })
        .then(function (res) { return res.json().then(function (body) { return { status: res.status, body: body }; }); })
        .then(function (r) {
          if (r.status === 200 && r.body.success) {
            setFlash('success', 'Profile updated. Refreshing...');
            setTimeout(function () { window.location.reload(); }, 700);
            return;
          }
          if (r.status === 422 && r.body.errors) {
            Object.keys(r.body.errors).forEach(function (field) {
              var slot = formEl.querySelector('[data-err="' + field + '"]');
              if (slot) slot.textContent = r.body.errors[field];
            });
            setFlash('error', 'Please fix the highlighted fields.');
          } else {
            setFlash('error', r.body.message || 'Could not save your changes.');
          }
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save changes';
        })
        .catch(function () {
          setFlash('error', 'Network error. Please try again.');
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save changes';
        });
    });
  })();
</script>

<script>
  // Count the "Profile strength" number up from 0 so it grows together with the bar.
  (function () {
    var el = document.getElementById('strengthPercent');
    if (!el) return;

    var target = parseInt(el.getAttribute('data-target'), 10) || 0;

    // Nothing to count, or the visitor asked for less motion: just show the final number.
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (target === 0 || reduceMotion) { el.textContent = target + '%'; return; }

    el.textContent = '0%';
    var current = 0;

    // Wait a moment (the bar starts after 0.3s), then add 1 at a time.
    setTimeout(function () {
      var timer = setInterval(function () {
        current = current + 1;
        el.textContent = current + '%';
        if (current >= target) clearInterval(timer);   // stop when we reach the goal
      }, 1300 / target);                                // whole count takes about 1.3 seconds
    }, 300);
  })();
</script>
HTML;
require __DIR__ . '/../templates/employeeTemplates/footer.php';
?>
