<?php
// admin/create_user.php
// Create a login account and send a password setup invitation.

require_once __DIR__ . '/../authentication/guard.php';
$account = require_access('user_management', 'edit', 'all');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Mailer.php';

// Load email and application configuration.
$mailConfig = require __DIR__ . '/../config/mail.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$userModel = new User($pdo);
// Remove expired pending accounts.
$deletedAccounts = $userModel->deleteExpiredPendingAccounts();
$mailer = new Mailer();

$roles = Rbac::ROLES;
$employees = $userModel->getEmployeesWithoutUsers();



$errors = [];
$success = '';

if (isset($_SESSION['create_user_success'])) {
  $success = $_SESSION['create_user_success'];
  unset($_SESSION['create_user_success']);
}

$cleanupMessage = '';

if ($deletedAccounts > 0) {
  $cleanupMessage = $deletedAccounts
    . ' expired invitation account(s) were deleted.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  csrf_check($account['role']);

  $role = Rbac::normalizeRole($_POST['role'] ?? '');
  $username = trim($_POST['username'] ?? '');

  $status = ($_POST['status'] ?? 'Active') === 'Inactive'
    ? 'Inactive'
    : 'Active';

  $employee_id = ctype_digit((string)($_POST['employee_id'] ?? ''))
    && (int)$_POST['employee_id'] > 0
    ? (int)$_POST['employee_id']
    : null;

  // Validate role.
  if (!in_array($role, $roles, true)) {
    $errors[] = 'Please choose a valid role.';
  }

  // Validate username.
  if ($username === '' || strlen($username) < 3) {
    $errors[] = 'Username must be at least 3 characters.';
  }

  // Employees and managers require employee records.
  if (in_array($role, ['employee', 'manager'], true) && $employee_id === null) {
    $errors[] = "The {$role} role needs a linked employee.";
  }

  // Get employee email.
  $employeeEmail = null;

  if ($employee_id !== null) {

    $chk = $pdo->prepare(
      "SELECT email
             FROM employees
             WHERE id = :id
             AND id NOT IN (
                 SELECT employee_id
                 FROM users
                 WHERE employee_id IS NOT NULL
             )
             LIMIT 1"
    );

    $chk->execute(['id' => $employee_id]);

    $employeeEmail = $chk->fetchColumn();

    if ($employeeEmail === false) {
      $errors[] = 'That employee does not exist or already has an account.';
    } elseif (!filter_var($employeeEmail, FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'The selected employee does not have a valid email address.';
    }
  } else {
    // Unlinked accounts need a recipient email too.
    $employeeEmail = trim($_POST['email'] ?? '');

    if (!filter_var($employeeEmail, FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Please provide a valid email address for the invitation.';
    }
  }

  // Check duplicate username.
  if (!$errors) {

    $stmt = $pdo->prepare(
      "SELECT 1
             FROM users
             WHERE LOWER(username) = LOWER(:u)
             LIMIT 1"
    );

    $stmt->execute(['u' => $username]);

    if ($stmt->fetchColumn()) {
      $errors[] = 'Username already exists.';
    }
  }

  // Check application URL.
  $appUrl = $mailConfig['app_url'] ?? '';

  if (!$appUrl || !filter_var($appUrl, FILTER_VALIDATE_URL)) {
    $errors[] = 'Application URL is not configured correctly.';
  }

  // Create account and send invitation.
  if (!$errors) {

    try {

      // Create account without an administrator-provided password.
      $userId = $userModel->createPendingAccount([
        'employee_id' => $employee_id,
        'username' => $username,
        'role' => $role,
        'status' => $status
      ]);

      // Generate one-time password setup token.
      $token = $userModel->createSetupToken($userId);

      // Build invitation URL.
      $setupUrl = $appUrl
        . '/authentication/setup_password.php?token='
        . urlencode($token);

      // Escape values used in HTML.
      $safeUsername = htmlspecialchars(
        $username,
        ENT_QUOTES,
        'UTF-8'
      );

      $safeSetupUrl = htmlspecialchars(
        $setupUrl,
        ENT_QUOTES,
        'UTF-8'
      );

      // Invitation email.
      $subject = 'Welcome to the HRIS Employee Portal';

      $htmlBody = '
                <!DOCTYPE html>
                <html>
                <body style="font-family:Arial,sans-serif;background:#f4f6f9;padding:30px">

                    <div style="max-width:550px;margin:auto;background:white;padding:30px;border-radius:10px">

                        <h2 style="color:#1e293b">
                            Welcome to the HRIS Employee Portal!
                        </h2>

                        <p>Hello,</p>

                        <p>
                            Your HRIS login account has been created successfully.
                        </p>

                        <p>Your username is:</p>

                        <div style="background:#f1f5f9;padding:15px;border-radius:6px;font-size:18px;font-weight:bold">
                            ' . $safeUsername . '
                        </div>

                        <p>
                            To activate your account, please create your password
                            using the button below.
                        </p>

                        <div style="margin:30px 0">
                            <a href="' . $safeSetupUrl . '"
                               style="background:#2563eb;color:white;padding:14px 25px;text-decoration:none;border-radius:6px;font-weight:bold">
                                Set Up My Password
                            </a>
                        </div>

                        <p>
                            This invitation link expires after 10 minutes
                            and can only be used once.
                        </p>

                        <p>
                            If you did not expect this invitation,
                            please contact your HR administrator.
                        </p>

                        <hr>

                        <p style="color:#64748b;font-size:12px">
                            HRIS Payroll System
                        </p>

                    </div>

                </body>
                </html>
            ';

      $plainBody =
        "Welcome to the HRIS Employee Portal!\n\n"
        . "Your username is: {$username}\n\n"
        . "Set up your password using this link:\n"
        . "{$setupUrl}\n\n"
        . "This link expires after 10 minutes.";

      // Send email.
      $sent = $mailer->send(
        $employeeEmail,
        $subject,
        $htmlBody,
        $plainBody
      );

      if ($sent) {

        $_SESSION['create_user_success'] =
          "Account created successfully for {$username}. "
          . "The password setup invitation was sent to {$employeeEmail}.";

        header('Location: create_user.php');
        exit;
      } else {

        error_log(
          "HRIS invitation email failed for user ID: {$userId}"
        );

        $errors[] = 'The account was created, but the invitation email could not be sent. The account is still pending activation.';
      }
    } catch (Throwable $e) {

      error_log(
        'Create user invitation failed: '
          . $e->getMessage()
          . ' | File: '
          . $e->getFile()
          . ' | Line: '
          . $e->getLine()
      );

      $errors[] = 'Could not complete account creation. Please check the server logs.';
    }
  }
}

require __DIR__ . '/../templates/adminTemplates/header.php';
?>

<section class="page-head">
  <div class="ph-text">
    <p class="eyebrow">ACCESS CONTROL</p>
    <h1>Create User</h1>
    <p>
      Create a login account and send an email invitation
      for password setup.
    </p>
  </div>

  <div class="ph-actions">
    <a class="button" href="/admin/users.php">
      ← Back to Users
    </a>
  </div>
</section>
<?php if ($cleanupMessage): ?>
  <div class="alert" style="margin-bottom:20px">
    <?= htmlspecialchars($cleanupMessage) ?>
  </div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert error" style="margin-bottom:20px">
    <?php foreach ($errors as $e): ?>
      <div><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert"
    style="margin-bottom:20px;border-color:rgba(52,211,153,.4);background:rgba(52,211,153,.12);color:#c7f9e5">

    <?= htmlspecialchars($success) ?>

  </div>
<?php endif; ?>

<div class="panel">

  <div class="panel-head">
    <h2>New Account Details</h2>
  </div>

  <div class="panel-body">

    <form method="post" autocomplete="off">

      <?= csrf_field() ?>

      <div>
        <label for="employee_id">Employee</label>

        <select id="employee_id" name="employee_id">

          <option value="">-- None --</option>

          <?php foreach ($employees as $emp):

            $id = (int)$emp['id'];

            $code = !empty($emp['employee_id'])
              ? '[' . htmlspecialchars($emp['employee_id']) . '] '
              : '';

            $name = trim(
              ($emp['last_name'] ?? '') . ', '
                . ($emp['first_name'] ?? '')
            );

            $label = $code . $name;

            $sel = (
              isset($_POST['employee_id'])
              && (int)$_POST['employee_id'] === $id
            ) ? 'selected' : '';

          ?>

            <option value="<?= $id ?>" <?= $sel ?>>
              <?= htmlspecialchars($label) ?>
            </option>

          <?php endforeach; ?>

        </select>

        <div class="muted" style="margin-top:6px">
          Employee and manager accounts require an employee record.
          Linked accounts receive their invitation at the employee's
          registered email address.
        </div>
      </div>

      <div style="margin-top:18px">

        <label for="email">
          Invitation Email (for accounts without an employee record)
        </label>

        <input
          id="email"
          name="email"
          type="email"
          value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
          placeholder="example@gmail.com">

        <div class="muted" style="margin-top:6px">
          For linked accounts, the employee's registered email is used instead.
        </div>

      </div>

      <div style="margin-top:18px">

        <label for="username">Username</label>

        <input
          id="username"
          name="username"
          required
          minlength="3"
          value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

      </div>

      <div class="form-grid" style="margin-top:18px">

        <div>
          <label for="role">Role</label>

          <select id="role" name="role">

            <?php foreach ($roles as $r): ?>

              <option
                value="<?= htmlspecialchars($r) ?>"
                <?= (($_POST['role'] ?? 'employee') === $r) ? 'selected' : '' ?>>

                <?= htmlspecialchars(ucfirst($r)) ?>

              </option>

            <?php endforeach; ?>

          </select>
        </div>

        <div>
          <label for="status">Status</label>

          <select id="status" name="status">

            <option value="Active"
              <?= (($_POST['status'] ?? 'Active') === 'Active') ? 'selected' : '' ?>>
              Active
            </option>

            <option value="Inactive"
              <?= (($_POST['status'] ?? '') === 'Inactive') ? 'selected' : '' ?>>
              Inactive
            </option>

          </select>
        </div>

      </div>

      <div class="muted" style="margin-top:15px">
        The employee will create their own password using the
        invitation email. No password is required here.
      </div>

      <div class="form-actions">

        <button class="primary-button" type="submit">
          Create User & Send Invitation
        </button>

        <a class="button" href="/admin/users.php">
          Cancel
        </a>

      </div>

    </form>

  </div>
</div>