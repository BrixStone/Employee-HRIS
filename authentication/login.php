<?php
// authentication/login.php
session_start();
require_once __DIR__ . '/../config/supabase.php'; // provides $pdo

// If already logged in, send to role home
if (!empty($_SESSION['role'])) {
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
            // 2. Direct string comparison since you aren't using password_hash() yet
            if ($password === $user['password']) {
                
                $_SESSION['user_id'] = $user['id'];
                
                // 3. CHANGED: Using 'name' instead of 'username' based on your table
                $_SESSION['username'] = $user['name']; 
                $_SESSION['email'] = $user['email'];
                
                // 4. CHANGED: Since 'role' doesn't exist in your table, we hardcode a default 
                // so your roleManagement.php doesn't crash.
                $_SESSION['role'] = 'employee'; 

                header("Location: /employee/dashboard.php");
                exit();
            } else {
                $error = "Incorrect password.";
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