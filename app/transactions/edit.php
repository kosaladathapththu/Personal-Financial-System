<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../auth/common/auth_guard.php'; // ✅ guard path

// ---- utils (try to load, else safe fallbacks) ----
$utilCandidates = [
  __DIR__ . '/../auth/util.php',
  __DIR__ . '/../auth/common/util.php',
  __DIR__ . '/../common/util.php',
];
foreach ($utilCandidates as $u) {
  if (file_exists($u)) { require $u; break; }
}
if (!function_exists('now_iso')) {
  function now_iso(): string { return date('Y-m-d H:i:s'); }
}
if (!function_exists('h')) {
  function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);
$id  = (int)($_GET['id'] ?? 0);

// fetch transaction
$find = $pdo->prepare("
  SELECT local_txn_id, client_txn_uuid, txn_type, amount, txn_date, note, account_local_id, category_local_id
  FROM TRANSACTIONS_LOCAL
  WHERE local_txn_id=? AND user_local_id=?
");
$find->execute([$id, $uid]);
$t = $find->fetch(PDO::FETCH_ASSOC);
if (!$t) { http_response_code(404); echo "Not found"; exit; }

$errors = [];
$typ = $_POST['txn_type'] ?? $t['txn_type'];

// dropdowns
$accounts = $pdo->prepare("
  SELECT local_account_id, account_name
  FROM ACCOUNTS_LOCAL
  WHERE user_local_id=? AND is_active=1
  ORDER BY account_name
");
$accounts->execute([$uid]);
$accounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

$catStmt = $pdo->prepare("
  SELECT local_category_id, category_name
  FROM CATEGORIES_LOCAL
  WHERE user_local_id=? AND category_type=?
  ORDER BY category_name
");
$catStmt->execute([$uid, $typ]);
$catOptions = $catStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
  $date = $_POST['txn_date'] ?? '';
  $type = $_POST['txn_type'] ?? '';
  $acc  = (int)($_POST['account_local_id'] ?? 0);
  $cat  = (int)($_POST['category_local_id'] ?? 0);
  $amt  = (float)($_POST['amount'] ?? 0);
  $note = trim($_POST['note'] ?? '');

  if (!in_array($type, ['INCOME','EXPENSE'], true)) $errors[] = "Invalid type";
  if ($date === '') $errors[] = "Date is required";
  if ($acc <= 0)   $errors[] = "Account is required";
  if ($cat <= 0)   $errors[] = "Category is required";
  if ($amt <= 0)   $errors[] = "Amount must be > 0";

  // validate category type matches
  if ($cat > 0) {
    $chk = $pdo->prepare("SELECT category_type FROM CATEGORIES_LOCAL WHERE local_category_id=? AND user_local_id=?");
    $chk->execute([$cat, $uid]);
    $c = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['category_type'] !== $type) $errors[] = "Category must match the selected type";
  }

  if (!$errors) {
    $now = now_iso();
    $upd = $pdo->prepare("
      UPDATE TRANSACTIONS_LOCAL
      SET txn_type=?, amount=?, txn_date=?, note=?, account_local_id=?, category_local_id=?, updated_at=?, sync_status='PENDING'
      WHERE local_txn_id=? AND user_local_id=?
    ");
    $upd->execute([$type, $amt, $date, $note, $acc, $cat, $now, $id, $uid]);

    // 🔌 mark PENDING so sync can push this change later
    header('Location: ' . APP_BASE . '/app/transactions/index.php');
    exit;
  }

  // if type changed, reload category options
  $catStmt->execute([$uid, $type ?: $typ]);
  $catOptions = $catStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Edit Transaction</title></head>
<body>
<h2>Edit Transaction ✏️💵</h2>
<p><a href="<?= APP_BASE ?>/app/transactions/index.php">← Back</a></p>

<?php if ($errors): ?>
  <div style="color:red"><b>Please fix:</b>
    <ul><?php foreach($errors as $e) echo "<li>".h($e)."</li>"; ?></ul>
  </div>
<?php endif; ?>

<form method="post">
  <label>Date</label><br>
  <input type="date" name="txn_date" value="<?= h($_POST['txn_date'] ?? substr($t['txn_date'],0,10)) ?>"><br><br>

  <label>Type</label><br>
  <select name="txn_type" onchange="this.form.submit()">
    <option value="INCOME"  <?= $typ==='INCOME'?'selected':'' ?>>INCOME</option>
    <option value="EXPENSE" <?= $typ==='EXPENSE'?'selected':'' ?>>EXPENSE</option>
  </select><br><br>

  <label>Account</label><br>
  <select name="account_local_id">
    <?php foreach($accounts as $a): ?>
      <option value="<?= (int)$a['local_account_id'] ?>" <?= (($_POST['account_local_id'] ?? $t['account_local_id'])==$a['local_account_id'])?'selected':'' ?>>
        <?= h($a['account_name']) ?>
      </option>
    <?php endforeach; ?>
  </select><br><br>

  <label>Category</label><br>
  <select name="category_local_id">
    <?php $selCat = $_POST['category_local_id'] ?? $t['category_local_id']; ?>
    <?php foreach($catOptions as $c): ?>
      <option value="<?= (int)$c['local_category_id'] ?>" <?= ($selCat==$c['local_category_id'])?'selected':'' ?>>
        <?= h($c['category_name']) ?>
      </option>
    <?php endforeach; ?>
  </select><br><br>

  <label>Amount</label><br>
  <input type="number" step="0.01" name="amount" value="<?= h($_POST['amount'] ?? $t['amount']) ?>"><br><br>

  <label>Note</label><br>
  <input name="note" value="<?= h($_POST['note'] ?? $t['note']) ?>"><br><br>

  <button name="save" type="submit">Update</button>
</form>
</body>
</html>
