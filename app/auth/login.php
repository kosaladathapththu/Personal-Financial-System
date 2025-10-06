<?php
session_start();
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if ($email && $pass) {
        $pdo = sqlite();
        $stmt = $pdo->prepare("SELECT local_user_id, password_hash FROM USERS_LOCAL WHERE email=?");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && password_verify($pass, $row['password_hash'])) {
            $_SESSION['uid'] = (int)$row['local_user_id'];
            header('Location: ' . url('/public/dashboard.php'));
            exit;
        }
    }
    $error = "Invalid email or password";
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>PFMS — Login</title>
  <link rel="stylesheet" href="login.css">
</head>
<body>
  <div class="login-card">
    <h1>PFMS Login 🔐</h1>
    <?php if ($error): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <input name="email" type="email" placeholder="Email" required>
      <input name="password" type="password" placeholder="Password" required>
      <button type="submit">Login</button>
    </form>
    <p class="signup-text">Don't have an account? 
      <a href="signup.php" class="signup-btn">Sign Up</a>
    </p>
  </div>

  <footer>
    &copy; <?= date('Y') ?> PFMS — Personal Finance Management System
  </footer>
</body>
</html>
