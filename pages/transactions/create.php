<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/util.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $guid = guidv4();
  $stmt = $sqlite->prepare('INSERT INTO transactions (user_id,account_id,category_id,guid,txn_date,amount,kind,note) VALUES (?,?,?,?,?,?,?,?)');
  $stmt->execute([
    1,                         // our seeded user
    1,                         // Main Wallet
    $_POST['category_id'],     // choose from dropdown
    $guid,
    $_POST['txn_date'],        // e.g. 2025-10-02T12:30:00
    $_POST['amount'],
    $_POST['kind'],            // INCOME or EXPENSE
    $_POST['note'] ?? null
  ]);
  echo 'Saved locally with GUID: ' . htmlspecialchars($guid) . '<br>';

  // show what landed in change_log for learning
  $log = $sqlite->query("SELECT change_id, table_name, row_guid, op_type, substr(payload,1,120) AS payload_preview
                          FROM change_log ORDER BY change_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  echo '<pre>'.print_r($log, true).'</pre>';
  exit;
}

// fetch categories for the dropdown (by kind)
$cats = $sqlite->query("SELECT category_id, name, kind FROM categories ORDER BY kind, name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Add Transaction</title>
  <style>
    body{font-family:Inter,system-ui,Segoe UI,Arial;margin:2rem;max-width:720px}
    label{display:block;margin:.5rem 0 .2rem}
    input,select,textarea{width:100%;padding:.6rem;border:1px solid #ccc;border-radius:.5rem}
    button{margin-top:1rem;padding:.6rem 1rem;border:0;border-radius:.5rem;background:#111;color:#fff;cursor:pointer}
  </style>
</head>
<body>
  <h2>Add Transaction (goes to SQLite first)</h2>
  <form method="post">
    <label>Type</label>
    <select name="kind" required>
      <option value="INCOME">INCOME</option>
      <option value="EXPENSE">EXPENSE</option>
    </select>

    <label>Category</label>
    <select name="category_id" required>
      <?php foreach($cats as $c): ?>
        <option value="<?= htmlspecialchars($c['category_id']) ?>">
          <?= htmlspecialchars($c['kind'].' — '.$c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>Date & Time (ISO8601)</label>
    <input type="datetime-local" name="txn_date" required>

    <label>Amount (LKR)</label>
    <input type="number" name="amount" min="0" step="0.01" required>

    <label>Note (optional)</label>
    <textarea name="note" rows="3"></textarea>

    <button type="submit">Save to SQLite</button>
  </form>
</body>
</html>
