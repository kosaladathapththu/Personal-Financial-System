<?php
session_start();
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $name  = trim($_POST['full_name'] ?? '');
  $pass  = $_POST['password'] ?? '';

  if ($email === '' || $name === '' || strlen($pass) < 6) {
    http_response_code(422);
    echo "Invalid input"; exit;
  }

  $hash = password_hash($pass, PASSWORD_DEFAULT);
  $pdo = sqlite();

  $stmt = $pdo->prepare("INSERT INTO USERS_LOCAL(email, password_hash, full_name) VALUES(?,?,?)");
  try {
    $stmt->execute([$email, $hash, $name]);
    $_SESSION['uid'] = (int)$pdo->lastInsertId();
    header('Location: ' . url('/public/dashboard.php'));
  } catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE')) {
      http_response_code(409);
      echo "Email already exists";
    } else {
      throw $e;
    }
  }
  exit;
}
?>
<!-- simple form (structure only) -->
<form method="post">
  <input name="full_name" placeholder="Full Name">
  <input name="email" type="email" placeholder="Email">
  <input name="password" type="password" placeholder="Password (min 6)">
  <button type="submit">Sign up</button>
</form>
