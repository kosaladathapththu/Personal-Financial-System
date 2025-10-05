<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../common/auth_guard.php';

$pdo = sqlite();
$uid = (int)$_SESSION['uid'];
$id  = (int)($_GET['id'] ?? 0);

$cat = $pdo->prepare("SELECT local_category_id, category_name FROM CATEGORIES_LOCAL WHERE local_category_id=? AND user_local_id=?");
$cat->execute([$id, $uid]);
$c = $cat->fetch(PDO::FETCH_ASSOC);
if (!$c) { http_response_code(404); echo "Not found"; exit; }

$txnCnt = (int)$pdo->query("SELECT COUNT(*) FROM TRANSACTIONS_LOCAL WHERE category_local_id=".$id)->fetchColumn();
$childCnt = (int)$pdo->query("SELECT COUNT(*) FROM CATEGORIES_LOCAL WHERE parent_local_id=".$id)->fetchColumn();

if ($txnCnt > 0 || $childCnt > 0) {
  echo "<p style='color:red'>Cannot delete: has ".($txnCnt>0?"$txnCnt transactions":"").(($txnCnt>0 && $childCnt>0)?" and ":"").($childCnt>0?"$childCnt child categories":"").".</p>";
  echo "<p><a href='".APP_BASE."/app/categories/index.php'>Back</a></p>";
  exit;
}

$del = $pdo->prepare("DELETE FROM CATEGORIES_LOCAL WHERE local_category_id=? AND user_local_id=?");
$del->execute([$id, $uid]);

// 🔌 Oracle: later queue a DELETE for sync
header('Location: ' . APP_BASE . '/app/categories/index.php'); exit;
