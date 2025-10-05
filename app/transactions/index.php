<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../auth/common/auth_guard.php'; // <-- fixed path
require __DIR__ . '/../auth/common/util.php';       // <-- fixed path (if you have util.php)

// If util.php doesn't exist, this fallback keeps things working.
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);

// filters
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';
$acc  = $_GET['account'] ?? '';
$cat  = $_GET['category'] ?? '';
$typ  = $_GET['type'] ?? '';

// dropdown data
$accounts = $pdo->prepare("
  SELECT local_account_id, account_name
  FROM ACCOUNTS_LOCAL
  WHERE user_local_id=? AND is_active=1
  ORDER BY account_name
");
$accounts->execute([$uid]);
$accounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->prepare("
  SELECT local_category_id, category_name
  FROM CATEGORIES_LOCAL
  WHERE user_local_id=?
  ORDER BY category_type, category_name
");
$categories->execute([$uid]);
$categories = $categories->fetchAll(PDO::FETCH_ASSOC);

// build WHERE
$where = ["t.user_local_id = ?"];
$args  = [$uid];

if ($from !== '') { $where[] = "date(t.txn_date) >= date(?)"; $args[] = $from; }
if ($to   !== '') { $where[] = "date(t.txn_date) <= date(?)"; $args[] = $to; }
if ($acc  !== '') { $where[] = "t.account_local_id = ?";      $args[] = (int)$acc; }
if ($cat  !== '') { $where[] = "t.category_local_id = ?";     $args[] = (int)$cat; }
if ($typ  !== '' && in_array($typ, ['INCOME','EXPENSE'], true)) {
  $where[] = "t.txn_type = ?"; $args[] = $typ;
}

$sql = "
SELECT
  t.local_txn_id, t.client_txn_uuid, t.txn_type, t.amount, t.txn_date, t.note, t.sync_status,
  a.account_name,
  c.category_name
FROM TRANSACTIONS_LOCAL t
JOIN ACCOUNTS_LOCAL a ON a.local_account_id = t.account_local_id
JOIN CATEGORIES_LOCAL c ON c.local_category_id = t.category_local_id
WHERE " . implode(' AND ', $where) . "
ORDER BY datetime(t.txn_date) DESC, t.local_txn_id DESC
LIMIT 200
";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// totals
$totSql = "
SELECT
  SUM(CASE WHEN t.txn_type='INCOME'  THEN t.amount ELSE 0 END) AS total_income,
  SUM(CASE WHEN t.txn_type='EXPENSE' THEN t.amount ELSE 0 END) AS total_expense
FROM TRANSACTIONS_LOCAL t
WHERE " . implode(' AND ', $where) . "
";
$sum = $pdo->prepare($totSql);
$sum->execute($args);
$tot = $sum->fetch(PDO::FETCH_ASSOC);
$income  = (float)($tot['total_income'] ?? 0);
$expense = (float)($tot['total_expense'] ?? 0);
$net     = $income - $expense;
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Transactions</title>
</head>
<body>
<h2>Transactions 💵</h2>
<p>
  <a href="<?= APP_BASE ?>/app/transactions/create.php">+ Add Transaction</a> |
  <a href="<?= APP_BASE ?>/public/dashboard.php">← Back</a>
</p>

<form method="get" style="margin:10px 0; padding:8px; border:1px solid #ccc;">
  <b>Filters 🔎</b><br>
  <label>From</label>
  <input type="date" name="from" value="<?= h($from) ?>">
  <label>To</label>
  <input type="date" name="to" value="<?= h($to) ?>">
  <label>Type</label>
  <select name="type">
    <option value="">(All)</option>
    <option value="INCOME"  <?= $typ==='INCOME'?'selected':'' ?>>INCOME</option>
    <option value="EXPENSE" <?= $typ==='EXPENSE'?'selected':'' ?>>EXPENSE</option>
  </select>
  <label>Account</label>
  <select name="account">
    <option value="">(All)</option>
    <?php foreach($accounts as $a): ?>
      <option value="<?= (int)$a['local_account_id'] ?>" <?= $acc==$a['local_account_id']?'selected':'' ?>>
        <?= h($a['account_name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <label>Category</label>
  <select name="category">
    <option value="">(All)</option>
    <?php foreach($categories as $c): ?>
      <option value="<?= (int)$c['local_category_id'] ?>" <?= $cat==$c['local_category_id']?'selected':'' ?>>
        <?= h($c['category_name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <button type="submit">Apply</button>
  <a href="<?= APP_BASE ?>/app/transactions/index.php">Reset</a>
</form>

<h3>Totals 📊</h3>
<ul>
  <li>Income: <b><?= number_format($income,2) ?></b></li>
  <li>Expense: <b><?= number_format($expense,2) ?></b></li>
  <li>Net: <b><?= number_format($net,2) ?></b></li>
</ul>

<table border="1" cellpadding="6">
  <tr>
    <th>Date</th><th>Type</th><th>Amount</th><th>Account</th><th>Category</th><th>Note</th><th>Sync</th><th>Actions</th>
  </tr>
  <?php foreach($rows as $r): ?>
  <tr>
    <td><?= h($r['txn_date']) ?></td>
    <td><?= h($r['txn_type']) ?></td>
    <td style="text-align:right"><?= number_format((float)$r['amount'],2) ?></td>
    <td><?= h($r['account_name']) ?></td>
    <td><?= h($r['category_name']) ?></td>
    <td><?= h($r['note']) ?></td>
    <td><?= h($r['sync_status']) ?></td>
    <td>
      <a href="<?= APP_BASE ?>/app/transactions/edit.php?id=<?= (int)$r['local_txn_id'] ?>">Edit</a> |
      <a href="<?= APP_BASE ?>/app/transactions/delete.php?id=<?= (int)$r['local_txn_id'] ?>" onclick="return confirm('Delete this transaction?');">Delete</a>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</body>
</html>
