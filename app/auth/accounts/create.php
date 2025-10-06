<?php
// pfms/app/auth/accounts/create.php
require __DIR__ . '/../../../config/env.php';
require __DIR__ . '/../../../db/sqlite.php';
require __DIR__ . '/../common/auth_guard.php';

// ---- utils (load if exists; otherwise provide fallbacks) ----
$util = __DIR__ . '/../util.php';            // you have app/auth/util.php in your tree
if (file_exists($util)) {
    require $util;
}
if (!function_exists('now_iso')) {
    function now_iso(): string { return date('Y-m-d H:i:s'); }
}
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['account_name'] ?? '');
    $type     = $_POST['account_type'] ?? '';
    $currency = trim($_POST['currency_code'] ?? 'LKR');
    $opening  = isset($_POST['opening_balance']) ? (float)$_POST['opening_balance'] : 0.0;

    if ($name === '') { $errors[] = 'Name is required'; }
    if (!in_array($type, ['CASH','BANK','CARD','MOBILE'], true)) { $errors[] = 'Invalid type'; }

    if (!$errors) {
        $now  = now_iso();
        $stmt = $pdo->prepare("
            INSERT INTO ACCOUNTS_LOCAL
                (user_local_id, account_name, account_type, currency_code, opening_balance, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 1, ?, ?)
        ");
        $stmt->execute([$uid, $name, $type, $currency, $opening, $now, $now]);

        // Redirect back to Accounts list
        header('Location: ' . APP_BASE . '/app/auth/accounts/index.php');
        exit;
    }
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Create Account</title></head>
<body>
<h2>Create Account 💼➕</h2>
<p><a href="<?= APP_BASE ?>/app/auth/accounts/index.php">← Back</a></p>

<?php if (!empty($errors)): ?>
  <div style="color:#b00020">
    <b>Please fix:</b>
    <ul>
      <?php foreach ($errors as $e): ?>
        <li><?= h($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post">
  <label>Name</label><br>
  <input name="account_name" placeholder="e.g., Cash Wallet" value="<?= h($_POST['account_name'] ?? '') ?>" required><br><br>

  <label>Type</label><br>
  <select name="account_type" required>
    <option value="CASH"   <?= (($_POST['account_type'] ?? '')==='CASH')?'selected':'' ?>>CASH</option>
    <option value="BANK"   <?= (($_POST['account_type'] ?? '')==='BANK')?'selected':'' ?>>BANK</option>
    <option value="CARD"   <?= (($_POST['account_type'] ?? '')==='CARD')?'selected':'' ?>>CARD</option>
    <option value="MOBILE" <?= (($_POST['account_type'] ?? '')==='MOBILE')?'selected':'' ?>>MOBILE</option>
  </select><br><br>

  <label>Currency</label><br>
  <input name="currency_code" value="<?= h($_POST['currency_code'] ?? 'LKR') ?>"><br><br>

  <label>Opening Balance</label><br>
  <input name="opening_balance" type="number" step="0.01" value="<?= h($_POST['opening_balance'] ?? '0') ?>"><br><br>

  <button type="submit">Save</button>
  <a href="<?= APP_BASE ?>/app/auth/accounts/index.php">Cancel</a>
</form>
</body>
</html>
