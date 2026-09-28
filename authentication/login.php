<?php
session_start();
require_once __DIR__ . '/../config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = "Email does not exist. Please check or sign up.";
        } else {

            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['username'] = $user['username'];

                header("Location: roleManagement.php");
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
        }

        .inline-form {
            display: inline;
            margin: 0;
        }

        .error-message {
            color: red;
            font-size: 14px;
            margin-bottom: 5px;
            text-align: center;
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
                <p1>Email Address</p1>
                <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                <p1>Password</p1>
                <input type="password" name="password" placeholder="Enter your password" required>
            </div>
        </form>
        <div class="remember">
            <label> <input type="checkbox" name="remember" <?= isset($_COOKIE['user_email']) ? 'cheched' : ''; ?>> Remember Me</label>
            <a href="/">Forgot Password</a>
        </div>

        <div class="button-group">
            <button type="submit" form="loginForm">Log in</button>
            </form>
        </div>
    </div>

</body>
</html>