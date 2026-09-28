<?php
    session_start();
    require 'config/database.php';

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $username = trim($_POST['username']);
        $password = $_POST['password'];

        if (empty($username) || empty($password)) {
            die("Please fill it all up");
        }

            $stmt =$pdo->prepare('SELECT id, username, password FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

        
        if ($user && password_verify($password, $user['password'])) {
     
            session_regenerate_id(true);

     
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];

     
            header('Location: employee/dashboard.php');
            exit;
        } else {
        echo "Invalid username or password.";
    }
 
}
