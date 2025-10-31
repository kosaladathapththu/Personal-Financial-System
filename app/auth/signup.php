<?php
session_start();
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');   // 🆕
    $email     = trim($_POST['email'] ?? '');
    $pass      = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    if ($full_name && $email && $pass && $confirm) {
        if ($pass !== $confirm) {
            $error = "Passwords do not match";
        } else {
            $pdo = sqlite();

            // Check if email exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM USERS_LOCAL WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Email already registered";
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);

                // 🆕 include full_name in INSERT
                $stmt = $pdo->prepare(
                    "INSERT INTO USERS_LOCAL (full_name, email, password_hash) VALUES (?, ?, ?)"
                );
                $stmt->execute([$full_name, $email, $hash]);

                $success = "Account created successfully! You can now <a href='login.php'>login</a>.";
            }
        }
    } else {
        $error = "All fields are required";
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>PFMS — Sign Up</title>
  <link rel="stylesheet" href="login.css">
</head>
<body>
  <div class="login-card">
    <h1>PFMS Sign Up ✍️</h1>

    <?php if ($error): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="success"><?= $success ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <!-- 🆕 new field -->
      <input name="full_name" type="text" placeholder="Full name" required>
      <input name="email" type="email" placeholder="Email" required>
      <input name="password" type="password" placeholder="Password" required>
      <input name="confirm_password" type="password" placeholder="Confirm Password" required>
      <button type="submit">Sign Up</button>
    </form>
    <p class="signup-text">Already have an account? 
      <a href="login.php" class="signup-btn">Login</a>
    </p>
  </div>

  <footer>
    &copy; <?= date('Y') ?> PFMS — Personal Finance Management System
  </footer>
</body>
</html>
