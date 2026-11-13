<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../config/helpers.php';

$userModel = new User($pdo);

$error = '';
$success = '';
$validToken = false;
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$invitation = null;

// CSRF initialization
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Validate invitation token
if ($token !== '' && preg_match('/^[a-f0-9]{64}$/i', $token)) {
    try {
        $invitation = $userModel->findValidSetupToken($token);
        $validToken = $invitation !== null;
    } catch (Throwable $e) {
        error_log('Password setup validation error: ' . $e->getMessage());
        $error = 'Unable to validate your invitation. Please try again.';
    }
}

if (!$validToken && $error === '') {
    $error = 'This invitation link is invalid, expired, or has already been used.';
}

// Process password submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = 'Your session has expired. Please refresh the page and try again.';
    } else {

        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must contain at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $error = 'Password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $error = 'Password must contain at least one lowercase letter.';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $error = 'Password must contain at least one number.';
        } elseif (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            $error = 'Password must contain at least one special character.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {

            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $completed = $userModel->completeSetup(
                    (int)$invitation['token_id'],
                    (int)$invitation['user_id'],
                    $passwordHash
                );

                if ($completed) {
                    $success = 'Your password has been created successfully. You can now sign in.';
                    $validToken = false;
                    unset($_SESSION['csrf_token']);
                } else {
                    $error = 'This invitation is no longer valid. Please request a new invitation.';
                    $validToken = false;
                }

            } catch (Throwable $e) {
                error_log('Password setup error: ' . $e->getMessage());
                $error = 'Unable to complete password setup. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>HRIS | Set Up Password</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<link rel="stylesheet" href="/asset/css/setup-password.css">
</head>

<body>

<div class="setup-page">

    <div class="background-overlay"></div>

    <!-- LOGO -->
    <div class="logo-group">
        <div class="logo-mark">
            <span>H</span>
        </div>

        <div class="logo-text">
            <strong>HRIS</strong>
            <small>Better People. Brighter Future.</small>
        </div>
    </div>

    <!-- LEFT CONTENT -->
    <div class="welcome-section">

        <div class="welcome-heading">
            <h1>
                Create Your<br>
                <span>Password.</span>
            </h1>

            <p>
                Secure your account and get started with
                your HRIS employee portal.
            </p>
        </div>

        <div class="feature-list">

            <div class="feature">
                <div class="feature-icon">✓</div>
                <div>
                    <strong>Secure Account Access</strong>
                    <p>Protect your personal HR information.</p>
                </div>
            </div>

            <div class="feature">
                <div class="feature-icon">✦</div>
                <div>
                    <strong>All Your HR Services</strong>
                    <p>Access attendance, leave, and payroll services.</p>
                </div>
            </div>

            <div class="feature">
                <div class="feature-icon">◎</div>
                <div>
                    <strong>One Account, Everything</strong>
                    <p>Your HRIS journey starts here.</p>
                </div>
            </div>

        </div>

    </div>

    <!-- PASSWORD CARD -->
    <div class="setup-container">

        <div class="setup-card">

            <div class="card-heading">
                <div class="heading-icon">✧</div>

                <h2>Set Up Password</h2>

                <p>
                    Choose a strong password for your account.
                </p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert error" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert success" role="status">
                    <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                </div>

                <a href="/authentication/login.php" class="submit-button">
                    Continue to Login
                </a>

            <?php elseif ($validToken): ?>

                <div class="account-info">
                    <span>USERNAME</span>
                    <strong><?= htmlspecialchars($invitation['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                </div>

                <form method="POST" autocomplete="off">

                    <input type="hidden" name="token"
                           value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                    <div class="form-field">

                        <label for="password">New Password</label>

                        <div class="password-wrapper">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Enter your new password"
                                autocomplete="new-password"
                                required
                            >

                            <button type="button" class="toggle-password"
                                    data-target="password"
                                    aria-label="Show password">◉</button>
                        </div>

                    </div>

                    <div class="form-field">

                        <label for="confirm_password">Confirm Password</label>

                        <div class="password-wrapper">
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                placeholder="Confirm your password"
                                autocomplete="new-password"
                                required
                            >

                            <button type="button" class="toggle-password"
                                    data-target="confirm_password"
                                    aria-label="Show password">◉</button>
                        </div>

                    </div>

                    <div class="password-requirements">
                        <strong>Password requirements</strong>

                        <ul>
                            <li>At least 8 characters</li>
                            <li>One uppercase letter</li>
                            <li>One lowercase letter</li>
                            <li>One number</li>
                            <li>One special character</li>
                        </ul>
                    </div>

                    <button type="submit" class="submit-button">
                        Create My Password
                    </button>

                </form>

            <?php else: ?>

                <div class="expired-message">
                    <div class="expired-icon">!</div>

                    <h3>Invitation Unavailable</h3>

                    <p>
                        Please contact your HR administrator
                        and request a new password setup invitation.
                    </p>
                </div>

                <a href="/authentication/login.php" class="secondary-button">
                    Back to Login
                </a>

            <?php endif; ?>

            <div class="card-footer">
                HRIS Payroll System
                <span>Secure Account Registration</span>
            </div>

        </div>

    </div>

</div>

<script>
document.querySelectorAll('.toggle-password').forEach(function(button) {
    button.addEventListener('click', function() {
        const input = document.getElementById(this.dataset.target);

        if (!input) return;

        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';

        this.setAttribute(
            'aria-label',
            showing ? 'Show password' : 'Hide password'
        );
    });
});
</script>

</body>
</html>
