<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// Redirect users who are already logged in.
// POST requests must still be checked.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: /admin/users.php');
            exit;

        case 'hr':
            header('Location: /hr/dashboard.php');
            exit;

        case 'payroll':
            header('Location: /hr/dashboard.php');
            exit;

        case 'manager':
            header('Location: /manager/dashboard.php');
            exit;

        case 'employee':
            header('Location: /employee/dashboard.php');
            exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $error = 'Please enter both username/email and password.';
    } else {
        $sql = "
            SELECT
                u.id AS user_id,
                u.username,
                u.password AS password_hash,
                u.role,
                u.status,
                u.employee_id,
                e.id AS emp_id,
                e.email AS emp_email,
                e.first_name,
                e.last_name
            FROM users u
            LEFT JOIN employees e ON e.id = u.employee_id
            WHERE LOWER(u.username) = :id_lower
               OR LOWER(e.email) = :id_lower
            LIMIT 1
        ";

        try {
            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                ':id_lower' => mb_strtolower($identifier, 'UTF-8')
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error = 'Invalid credentials.';
            } elseif (strcasecmp((string) $user['status'], 'Active') !== 0) {
                $error = 'Account is inactive. Contact an admin.';
            } elseif (!password_verify($password, $user['password_hash'])) {
                $error = 'Invalid credentials.';
            } else {
                // Clear old session data and regenerate the session ID.
                $_SESSION = [];

                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['employee_id'] = !empty($user['employee_id'])
                    ? (int) $user['employee_id']
                    : null;
                $_SESSION['email'] = $user['emp_email'] ?? null;

                // Redirect according to the user's role.
                switch ($user['role']) {
                    case 'admin':
                        $destination = '/admin/users.php';
                        break;

                    case 'hr':
                        $destination = '/hr/dashboard.php';
                        break;

                    case 'payroll':
                        $destination = '/hr/dashboard.php';
                        break;

                    case 'manager':
                        $destination = '/manager/dashboard.php';
                        break;

                    case 'employee':
                        $destination = '/employee/dashboard.php';
                        break;

                    default:
                        $destination = '/';
                        break;
                }

                header('Location: ' . $destination);
                exit;
            }
        } catch (PDOException $e) {
            // Log the technical error without exposing database details.
            error_log('Login database error: ' . $e->getMessage());
            $error = 'Unable to sign in right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>HRIS | Sign In</title>

    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Inter:wght@200;300;400;500;600;700;800&display=swap">

    <link rel="stylesheet" href="/asset/css/style.css">
</head>

<body>
<div class="hris-login">

    <div class="boliviainteligente-vzg-skzowiu">

        <img
            class="boliviainteligente-vzg-skzowiu-child"
            src="/images/log-back.jpg"
            alt="Background"
        >

        <div class="boliviainteligente-vzg-skzowiu-item"></div>

        <!-- LOGIN CARD -->
        <div class="login-container">
            <div class="right-signin-card">

                <div class="signin-header">
                    <b class="sign-in">Sign in</b>
                    <div class="enter-your-credentials">
                        Enter your credentials to continue
                    </div>
                </div>

                <form method="post" autocomplete="on" class="<?= $error !== '' ? 'has-error' : '' ?>">

                    <div class="form-fields">

                        <!-- USERNAME OR EMAIL -->
                        <div class="field-email">
                            <label for="identifier" class="email-address">
                                Username or Employee Email
                            </label>

                            <div class="input-email-box">
                                <input
                                    type="text"
                                    id="identifier"
                                    name="identifier"
                                    class="youexamplecom"
                                    placeholder="you@example.com"
                                    value="<?= htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    autocomplete="username"
                                    required
                                >

                                <svg class="user-icon"
                                     viewBox="0 0 24 24"
                                     fill="none"
                                     xmlns="http://www.w3.org/2000/svg"
                                     aria-hidden="true">
                                    <circle cx="12" cy="8" r="4"
                                            stroke="white" stroke-width="2"/>
                                    <path d="M4 21v-2a8 8 0 0 1 16 0v2"
                                          stroke="white" stroke-width="2"
                                          stroke-linecap="round"/>
                                </svg>
                            </div>

                            <div class="line"></div>
                        </div>

                        <!-- PASSWORD -->
                        <div class="field-password">
                            <label for="password" class="password">
                                Password
                            </label>

                            <div class="input-password-box">
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="enter-your-password"
                                    placeholder="Enter your password"
                                    autocomplete="current-password"
                                    required
                                >

                                <svg class="user-icon2"
                                     viewBox="0 0 24 24"
                                     fill="none"
                                     xmlns="http://www.w3.org/2000/svg"
                                     aria-hidden="true">
                                    <rect x="5" y="10" width="14" height="11"
                                          rx="2" stroke="white"
                                          stroke-width="2"/>
                                    <path d="M8 10V7a4 4 0 0 1 8 0v3"
                                          stroke="white" stroke-width="2"
                                          stroke-linecap="round"/>
                                </svg>

                                <button type="button"
                                        class="password-toggle"
                                        id="password-toggle"
                                        aria-label="Show password"
                                        aria-pressed="false">
                                    <!-- Eye (password hidden) -->
                                    <svg class="icon-eye"
                                         viewBox="0 0 24 24"
                                         fill="none"
                                         xmlns="http://www.w3.org/2000/svg"
                                         aria-hidden="true">
                                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"
                                              stroke="white" stroke-width="2"
                                              stroke-linecap="round"
                                              stroke-linejoin="round"/>
                                        <circle cx="12" cy="12" r="3"
                                                stroke="white" stroke-width="2"/>
                                    </svg>
                                    <!-- Eye off (password visible) -->
                                    <svg class="icon-eye-off"
                                         viewBox="0 0 24 24"
                                         fill="none"
                                         xmlns="http://www.w3.org/2000/svg"
                                         aria-hidden="true">
                                        <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19M6.61 6.61A18.5 18.5 0 0 0 1 12s4 7 11 7a9.12 9.12 0 0 0 5.39-1.61"
                                              stroke="white" stroke-width="2"
                                              stroke-linecap="round"
                                              stroke-linejoin="round"/>
                                        <path d="M9.88 9.88a3 3 0 0 0 4.24 4.24M1 1l22 22"
                                              stroke="white" stroke-width="2"
                                              stroke-linecap="round"
                                              stroke-linejoin="round"/>
                                    </svg>
                                </button>
                            </div>

                            <div class="line2"></div>

                            <?php if ($error !== ''): ?>
                                <p class="field-error" role="alert">
                                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            <?php endif; ?>
                        </div>

                    </div>

                    <!-- FORGOT PASSWORD -->
                    <div class="form-options">
                        <a class="forgot-password" href="forgot-password.php">
                            Forgot Password?
                        </a>
                    </div>

                    <!-- LOGIN BUTTON -->
                    <div class="action-button-group">
                        <button type="submit" class="btn-login">
                            <span class="login">Login</span>
                        </button>
                    </div>

                </form>
            </div>
        </div>

        <!-- LEFT SIDE FEATURES -->
        <div class="features-list">

            <div class="feature-row">
                <div class="icon-circle">
                    <div class="icon-wrapper-captioncalendar">
                        <svg viewBox="0 0 24 24" fill="none"
                             xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="5" width="18" height="16" rx="2"
                                  stroke="#9F8FF2" stroke-width="2"/>
                            <path d="M7 3v4M17 3v4M3 10h18"
                                  stroke="#9F8FF2" stroke-width="2"
                                  stroke-linecap="round"/>
                        </svg>
                    </div>
                </div>

                <div class="feature-text">
                    <div class="track-your-attendance">Track Your Attendance</div>
                    <div class="view-your-attendance">
                        View your attendance and leave history.
                    </div>
                </div>
            </div>

            <div class="feature-row2">
                <div class="icon-circle">
                    <div class="icon-wrapper-captionbriefcase">
                        <svg viewBox="0 0 24 24" fill="none"
                             xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="7" width="18" height="14" rx="2"
                                  stroke="#9F8FF2" stroke-width="2"/>
                            <path d="M8 7V4h8v3M3 12h18M12 12v9"
                                  stroke="#9F8FF2" stroke-width="2"
                                  stroke-linecap="round"/>
                        </svg>
                    </div>
                </div>

                <div class="feature-text2">
                    <div class="track-your-attendance">Access HR Services</div>
                    <div class="view-your-attendance">
                        Request leave, view payslips, and more.
                    </div>
                </div>
            </div>

        </div>

        <!-- THIRD FEATURE -->
        <div class="feature-row3">
            <div class="icon-circle3">
                <div class="icon-wrapper-captionuser">
                    <svg viewBox="0 0 24 24" fill="none"
                         xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="8" r="4"
                                stroke="#9F8FF2" stroke-width="2"/>
                        <path d="M4 21v-2a8 8 0 0 1 16 0v2"
                              stroke="#9F8FF2" stroke-width="2"
                              stroke-linecap="round"/>
                    </svg>
                </div>
            </div>

            <div class="feature-text3">
                <div class="track-your-attendance">Manage Your Profile</div>
                <div class="view-your-attendance">
                    Keep your information up to date.
                </div>
            </div>
        </div>

        <!-- WELCOME HEADING -->
        <div class="heading-stack">
            <b class="sign-in">
                <span>Welcome<br></span>
                <span class="back">Back!</span>
            </b>

            <div class="log-in-to">
                Log in to your account to access your HR services and manage your information.
            </div>
        </div>

        <!-- HRIS LOGO -->
        <div class="logo-group">
            <div class="logo-mark">
                <div class="icon-wrapper-captioncalendar">
                    <svg viewBox="0 0 24 24" fill="none"
                         xmlns="http://www.w3.org/2000/svg">
                        <circle cx="9" cy="8" r="4"
                                stroke="white" stroke-width="2"/>
                        <path d="M2 21v-2a7 7 0 0 1 14 0v2"
                              stroke="white" stroke-width="2"/>
                        <path d="M16 5a4 4 0 0 1 0 7M19 15a6 6 0 0 1 3 5"
                              stroke="white" stroke-width="2"
                              stroke-linecap="round"/>
                    </svg>
                </div>
            </div>

            <div class="logo-text-stack">
                <div class="hris">HRIS</div>
                <div class="better-people-brighter">
                    Better People. Brighter Future.
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    (function () {
        var toggle = document.getElementById('password-toggle');
        var input = document.getElementById('password');

        if (!toggle || !input) {
            return;
        }

        toggle.addEventListener('click', function () {
            var showing = input.getAttribute('type') === 'text';
            input.setAttribute('type', showing ? 'password' : 'text');
            toggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
            toggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            toggle.classList.toggle('is-visible', !showing);
            input.focus();
        });
    })();
</script>
</body>
</html>
