<?php
// public/api/signup.php — create user with password hashing
require_once __DIR__ . '/../../app/DB.php';
require_once __DIR__ . '/../../app/Json.php';


$body = json_body();
$email = trim($body['email'] ?? '');
$pwd = strval($body['password'] ?? '');
$name = trim($body['full_name'] ?? '');


if (!$email || !$pwd || !$name) {
json_out(['error' => 'full_name, email, password required'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
json_out(['error' => 'invalid email'], 400);
}
if (strlen($pwd) < 8) {
json_out(['error' => 'password must be at least 8 characters'], 400);
}


try {
$pdo = DB::pdo();
// check duplicate
$stmt = $pdo->prepare('SELECT id FROM users WHERE email=?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
json_out(['error' => 'email already exists'], 409);
}


$hash = password_hash($pwd, PASSWORD_BCRYPT);
$stmt = $pdo->prepare('INSERT INTO users (email, password_hash, full_name) VALUES (?,?,?)');
$stmt->execute([$email, $hash, $name]);
$id = (int)$pdo->lastInsertId();


// start session immediately (auto-login after signup)
session_start();
session_regenerate_id(true);
$_SESSION['user_id'] = $id;


json_out(['user_id' => $id, 'full_name' => $name]);
} catch (Throwable $e) {
json_out(['error' => 'server error'], 500);
}