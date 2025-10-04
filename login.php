<?php
// public/api/login.php — login using password_verify
require_once __DIR__ . '/../../app/DB.php';
require_once __DIR__ . '/../../app/Json.php';


session_start();
$body = json_body();
$email = trim($body['email'] ?? '');
$pwd = strval($body['password'] ?? '');


if (!$email || !$pwd) json_out(['error'=>'email and password required'], 400);


$pdo = DB::pdo();
$stmt = $pdo->prepare('SELECT id, password_hash, full_name FROM users WHERE email = ?');
$stmt->execute([$email]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) json_out(['error'=>'invalid credentials'], 401);


if (!password_verify($pwd, $row['password_hash'])) {
json_out(['error'=>'invalid credentials'], 401);
}


session_regenerate_id(true);
$_SESSION['user_id'] = (int)$row['id'];
json_out(['user_id'=>(int)$row['id'], 'full_name'=>$row['full_name']]);