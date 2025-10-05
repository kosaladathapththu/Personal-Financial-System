<?php
session_start();
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';
  $pdo = sqlite();
  $stmt = $pdo->prepare("SELECT local_user_id, password_hash FROM USERS_LOCAL WHERE email=?");
  $stmt->execute([$email]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row && password_verify($pass, $row['password_hash'])) {
    $_SESSION['uid'] = (int)$row['local_user_id'];
    header('Location: ' . url('/public/dashboard.php'));
 exit;
  }
  http_response_code(401);
  echo "Invalid email or password"; exit;
}
?>
<form method="post">
  <input name="email" type="email" placeholder="Email">
  <input name="password" type="password" placeholder="Password">
  <button type="submit">Login</button>
</form>
