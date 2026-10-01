<?php
session_start();
// Ensure this points to your config file
require_once __DIR__ . '/../config/supabase.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        // 1. CHANGED: Now targeting the 'employee' table
        $stmt = $pdo->prepare("SELECT * FROM employee WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = "Email does not exist. Please check or sign up.";
        } else {
            // 2. Direct string comparison since you aren't using password_hash() yet
            if ($password === $user['password']) {
                
                $_SESSION['user_id'] = $user['id'];
                
                // 3. CHANGED: Using 'name' instead of 'username' based on your table
                $_SESSION['username'] = $user['name']; 
                
                // 4. CHANGED: Since 'role' doesn't exist in your table, we hardcode a default 
                // so your roleManagement.php doesn't crash.
                $_SESSION['role'] = 'employee'; 

                header("Location: /employee/dashboard.php");
                exit();
            } else {
                $error = "Incorrect password.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Log in</title>
    <style>
        body {
            margin: 0;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: Arial, sans-serif;
        }
        .login-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }
        .form-inputs {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .button-group {
            display: flex;
            flex-direction: row;
            gap: 10px;
            width: 100%;
            justify-content: center;
            margin-top: 10px;
        }
        .error-message {
            color: red;
            font-size: 14px;
            margin-bottom: 5px;
            text-align: center;
        }
        .remember {
            display: flex;
            justify-content: space-between;
            width: 100%;
            font-size: 14px;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <h1>Sign in</h1>
        <p>Enter your credentials to continue</p>

        <?php if (!empty($error)): ?>
            <div class="error-message"><?= htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form action="login.php" method="POST" id="loginForm">
            <div class="form-inputs">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter your password" required>
            </div>
        </form>

        <div class="remember">
            <label> <input type="checkbox" name="remember" <?= isset($_COOKIE['user_email']) ? 'checked' : ''; ?>> Remember Me</label>
            <a href="/">Forgot Password</a>
        </div>

        <div class="button-group">
            <button type="submit" form="loginForm">Log in</button>
        </div>
    </div>

</body>
</html>