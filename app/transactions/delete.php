<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../auth/common/auth_guard.php'; // ✅ fixed path

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);
$id  = (int)($_GET['id'] ?? 0);

// ensure it's user's record
$chk = $pdo->prepare("SELECT local_txn_id FROM TRANSACTIONS_LOCAL WHERE local_txn_id=? AND user_local_id=?");
$chk->execute([$id, $uid]);
if (!$chk->fetch()) {
  http_response_code(404);
  echo "Not found";
  exit;
}

$del = $pdo->prepare("DELETE FROM TRANSACTIONS_LOCAL WHERE local_txn_id=? AND user_local_id=?");
$del->execute([$id, $uid]);

// 🔌 Oracle: later queue a DELETE by client_txn_uuid
header('Location: ' . APP_BASE . '/app/transactions/index.php');
exit;
