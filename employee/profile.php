<?php
session_start();

require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../authentication/login.php');
    exit;
}

if (strtolower(trim($_SESSION['role'] ?? '')) !== 'employee') {
    http_response_code(403);
    exit('Access denied: Employee account required.');
}

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
  <div class="card-heading"><div><p class="eyebrow">DETAILS</p><h2>Personal information</h2></div></div>
  <div class="info-grid">
    <div class="info-cell">
      <span class="info-label">Employee ID</span>
      <strong class="info-value">#<?= htmlspecialchars((string)$user['id']) ?></strong>
    </div>
    <div class="info-cell">
      <span class="info-label">Full Name</span>
      <strong class="info-value"><?= $user['name'] ?></strong>
    </div>
    <div class="info-cell">
      <span class="info-label">Email Address</span>
      <strong class="info-value teal"><?= $user['email'] ?></strong>
    </div>
    <div class="info-cell">
      <span class="info-label">Phone Number</span>
      <strong class="info-value"><?= $user['phone_number'] ?></strong>
    </div>
    <div class="info-cell wide">
      <span class="info-label">Employment Status</span>
      <strong class="info-value <?= $isActive ? 'green' : '' ?>"><?= $user['status'] ?></strong>
    </div>
  </div>
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
