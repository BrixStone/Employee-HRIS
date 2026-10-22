<?php
// authentication/login.php
session_start();
require_once __DIR__ . '/../config/database.php'; // provides $pdo (MySQL)

// If already logged in, send to role home. Only on GET: a submitted login
// form must always be checked, otherwise signing in as a different user
// silently keeps the previous user's session (and shows their data).
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
            exit; // placeholder
        case 'employee':
            header('Location: /employee/dashboard.php');
            exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $error = 'Please enter both username/email and password.';
    } else {
        $sql = "
            SELECT
                u.id            AS user_id,
                u.username,
                u.password      AS password_hash,
                u.role,
                u.status,
                u.employee_id,
                e.id            AS emp_id,
                e.email         AS emp_email,
                e.first_name,
                e.last_name
            FROM users u
            LEFT JOIN employees e ON e.id = u.employee_id
            WHERE lower(u.username) = :id_lower OR lower(e.email) = :id_lower
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id_lower' => mb_strtolower($identifier, 'UTF-8')]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);


        if (!$user) {
            $error = 'Invalid credentials.';
        } elseif ($user['status'] !== 'Active') {
            $error = 'Account is inactive. Contact an admin.';
        } elseif (!password_verify($password, $user['password_hash'])) {
            $error = 'Invalid credentials.';
        } else {
            // Success: drop everything from any previous login before
            // storing the new identity, then issue a fresh session id.
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['user_id']     = (int)$user['user_id'];
            $_SESSION['username']    = $user['username'];
            $_SESSION['role']        = $user['role'];
            $_SESSION['employee_id'] = $user['employee_id'] ? (int)$user['employee_id'] : null;
            $_SESSION['email']       = $user['emp_email'] ?? null;

            switch ($user['role']) {
                case 'admin':
                    header('Location: /admin/users.php');
                    break;
                case 'hr':
                    header('Location: /hr/dashboard.php');
                    break;
                case 'payroll':
                    header('Location: /hr/dashboard.php');
                    break; // placeholder
                case 'employee':
                    header('Location: /employee/dashboard.php');
                    break;
                default:
                    header('Location: /');
            }
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Sign in</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        .card {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;


        }
    </style>
</head>

<body>
    <div class="card">
        <h2 style="margin-top:0">Sign in</h2>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <div class="row">
                <label for="identifier">Username or Employee Email</label>
                <input type="text" id="identifier" name="identifier" required
                    value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>">
            </div>
            <div class="row">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">Sign in</button>
        </form>
    </div>
</body>

</html>