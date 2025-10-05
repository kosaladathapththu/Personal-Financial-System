<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';

// ---- Auth guard (corrected path) ----
require __DIR__ . '/../auth/common/auth_guard.php';

// ---- Util helpers (try typical locations, else fallback helpers) ----
$utilCandidates = [
    __DIR__ . '/../auth/util.php',          // app/auth/util.php (as in your tree)
    __DIR__ . '/../auth/common/util.php',   // app/auth/common/util.php (if you move it later)
    __DIR__ . '/../common/util.php',        // legacy/path if you ever create it
];
foreach ($utilCandidates as $utilPath) {
    if (file_exists($utilPath)) {
        require $utilPath;
        break;
    }
}
// Fallbacks if util.php not found
if (!function_exists('now_iso')) {
    function now_iso(): string { return date('Y-m-d H:i:s'); }
}
if (!function_exists('uuidv4')) {
    function uuidv4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);

$errors = [];
$typ = $_POST['txn_type'] ?? 'EXPENSE'; // default EXPENSE

// dropdowns
$accounts = $pdo->prepare("
  SELECT local_account_id, account_name
  FROM ACCOUNTS_LOCAL
  WHERE user_local_id = ? AND is_active = 1
  ORDER BY account_name
");
$accounts->execute([$uid]);
$accounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

// categories filtered by type (reload on change)
$catStmt = $pdo->prepare("
  SELECT local_category_id, category_name
  FROM CATEGORIES_LOCAL
  WHERE user_local_id = ? AND category_type = ?
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
    $chk = $pdo->prepare("
      SELECT category_type
      FROM CATEGORIES_LOCAL
      WHERE local_category_id = ? AND user_local_id = ?
    ");
    $chk->execute([$cat, $uid]);
    $c = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['category_type'] !== $type) {
        $errors[] = "Category must match the selected type";
    }
  }

  if (!$errors) {
    $now  = now_iso();
    $uuid = uuidv4();

    $ins = $pdo->prepare("
      INSERT INTO TRANSACTIONS_LOCAL
        (client_txn_uuid, user_local_id, account_local_id, category_local_id, txn_type, amount, txn_date, note, sync_status, created_at, updated_at)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?, ?)
    ");
    $ins->execute([$uuid, $uid, $acc, $cat, $type, $amt, $date, $note, $now, $now]);

    header('Location: ' . APP_BASE . '/app/transactions/index.php');
    exit;
  }

  // If POSTed type changed, refresh category options
  $catStmt->execute([$uid, $type ?: $typ]);
  $catOptions = $catStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Add Transaction</title></head>
<body>
<h2>Add Transaction ➕💵</h2>
<p><a href="<?= APP_BASE ?>/app/transactions/index.php">← Back</a></p>

<?php if ($errors): ?>
  <div style="color:red"><b>Please fix:</b>
    <ul>
      <?php foreach($errors as $e): ?>
        <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post">
  <label>Date</label><br>
  <input type="date" name="txn_date" value="<?= htmlspecialchars($_POST['txn_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>"><br><br>

  <label>Type</label><br>
  <select name="txn_type" onchange="this.form.submit()">
    <option value="INCOME"  <?= $typ==='INCOME'  ? 'selected' : '' ?>>INCOME</option>
    <option value="EXPENSE" <?= $typ==='EXPENSE' ? 'selected' : '' ?>>EXPENSE</option>
  </select>
  <small>Changing type reloads categories</small><br><br>

  <label>Account</label><br>
  <select name="account_local_id">
    <option value="0">(select)</option>
    <?php foreach($accounts as $a): ?>
      <option value="<?= (int)$a['local_account_id'] ?>" <?= (($_POST['account_local_id'] ?? '') == $a['local_account_id']) ? 'selected' : '' ?>>
        <?= htmlspecialchars($a['account_name'], ENT_QUOTES, 'UTF-8') ?>
      </option>
    <?php endforeach; ?>
  </select><br><br>

  <label>Category</label><br>
  <select name="category_local_id">
    <option value="0">(select)</option>
    <?php foreach($catOptions as $c): ?>
      <option value="<?= (int)$c['local_category_id'] ?>" <?= (($_POST['category_local_id'] ?? '') == $c['local_category_id']) ? 'selected' : '' ?>>
        <?= htmlspecialchars($c['category_name'], ENT_QUOTES, 'UTF-8') ?>
      </option>
    <?php endforeach; ?>
  </select><br><br>

  <label>Amount</label><br>
  <input type="number" step="0.01" name="amount" value="<?= htmlspecialchars($_POST['amount'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><br><br>

  <label>Note (optional)</label><br>
  <input name="note" value="<?= htmlspecialchars($_POST['note'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><br><br>

  <button name="save" type="submit">Save</button>
</form>
</body>
</html>
