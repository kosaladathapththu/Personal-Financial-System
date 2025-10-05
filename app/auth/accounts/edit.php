<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../common/auth_guard.php';
require __DIR__ . '/../common/util.php';

$pdo = sqlite();
$uid = (int)$_SESSION['uid'];
$id  = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
  SELECT local_account_id, account_name, account_type, currency_code, opening_balance, is_active
  FROM ACCOUNTS_LOCAL
  WHERE local_account_id = ? AND user_local_id = ?
");
$stmt->execute([$id, $uid]);
$acc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$acc) { http_response_code(404); echo "Not found"; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['account_name'] ?? '');
  $type = $_POST['account_type'] ?? '';
  $currency = $_POST['currency_code'] ?? 'LKR';
  $opening = (float)($_POST['opening_balance'] ?? 0);
  $active  = isset($_POST['is_active']) ? 1 : 0;

  if ($name === '' || !in_array($type, ['CASH','BANK','CARD','MOBILE'])) {
    http_response_code(422);
    echo "Invalid input"; exit;
  }

  $now = now_iso();
  $upd = $pdo->prepare("
    UPDATE ACCOUNTS_LOCAL
    SET account_name=?, account_type=?, currency_code=?, opening_balance=?, is_active=?, updated_at=?
    WHERE local_account_id=? AND user_local_id=?
  ");
  $upd->execute([$name, $type, $currency, $opening, $active, $now, $id, $uid]);

  // 🔌 Oracle sync touchpoint (later):
  // - Queue update to Oracle or set a 'dirty' flag to push at next sync

  header('Location: /app/accounts/index.php'); exit;
}
?>
<h2>Edit Account</h2>
<form method="post">
  <label>Name</label><br>
  <input name="account_name" value="<?=htmlspecialchars($acc['account_name'])?>"><br><br>

  <label>Type</label><br>
  <select name="account_type">
    <?php foreach (['CASH','BANK','CARD','MOBILE'] as $t): ?>
      <option value="<?=$t?>" <?=$acc['account_type']===$t?'selected':''?>><?=$t?></option>
    <?php endforeach; ?>
  </select><br><br>

  <label>Currency</label><br>
  <input name="currency_code" value="<?=htmlspecialchars($acc['currency_code'])?>"><br><br>

  <label>Opening Balance</label><br>
  <input name="opening_balance" type="number" step="0.01" value="<?=htmlspecialchars($acc['opening_balance'])?>"><br><br>

  <label><input type="checkbox" name="is_active" <?=$acc['is_active']?'checked':''?>> Active</label><br><br>

  <button type="submit">Update</button>
  <a href="/app/accounts/index.php">Cancel</a>
</form>
