<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../common/auth_guard.php';
require __DIR__ . '/../common/util.php';

$pdo = sqlite();
$uid = (int)$_SESSION['uid'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['account_name'] ?? '');
  $type = $_POST['account_type'] ?? '';
  $currency = $_POST['currency_code'] ?? 'LKR';
  $opening = (float)($_POST['opening_balance'] ?? 0);

  if ($name === '' || !in_array($type, ['CASH','BANK','CARD','MOBILE'])) {
    http_response_code(422);
    echo "Invalid input"; exit;
  }

  $now = now_iso();
  $stmt = $pdo->prepare("
    INSERT INTO ACCOUNTS_LOCAL
      (user_local_id, account_name, account_type, currency_code, opening_balance, is_active, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, 1, ?, ?)
  ");
  $stmt->execute([$uid, $name, $type, $currency, $opening, $now, $now]);

  // 🔌 Oracle sync touchpoint (later):
  // - Mark a SYNC_OUTBOX row or call an API to push account to Oracle

  header('Location: /app/accounts/index.php'); exit;
}
?>
<h2>Create Account</h2>
<form method="post">
  <label>Name</label><br>
  <input name="account_name" placeholder="e.g., Cash Wallet"><br><br>

  <label>Type</label><br>
  <select name="account_type">
    <option value="CASH">CASH</option>
    <option value="BANK">BANK</option>
    <option value="CARD">CARD</option>
    <option value="MOBILE">MOBILE</option>
  </select><br><br>

  <label>Currency</label><br>
  <input name="currency_code" value="LKR"><br><br>

  <label>Opening Balance</label><br>
  <input name="opening_balance" type="number" step="0.01" value="0"><br><br>

  <button type="submit">Save</button>
  <a href="/app/accounts/index.php">Cancel</a>
</form>
