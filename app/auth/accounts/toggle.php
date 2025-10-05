<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../common/auth_guard.php';
require __DIR__ . '/../common/util.php';

$pdo = sqlite();
$uid = (int)$_SESSION['uid'];
$id  = (int)($_GET['id'] ?? 0);

// read current status
$s = $pdo->prepare("SELECT is_active FROM ACCOUNTS_LOCAL WHERE local_account_id=? AND user_local_id=?");
$s->execute([$id, $uid]);
$row = $s->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); echo "Not found"; exit; }
$new = $row['is_active'] ? 0 : 1;

// toggle
$u = $pdo->prepare("UPDATE ACCOUNTS_LOCAL SET is_active=?, updated_at=? WHERE local_account_id=? AND user_local_id=?");
$u->execute([$new, now_iso(), $id, $uid]);

// 🔌 Oracle sync touchpoint (later):
// - Queue status change for Oracle

header('Location: /app/accounts/index.php');
