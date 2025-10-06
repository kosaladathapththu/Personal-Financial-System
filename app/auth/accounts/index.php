<?php
// go up 3 levels to reach /pfms/config and /pfms/db
require __DIR__ . '/../../../config/env.php';
require __DIR__ . '/../../../db/sqlite.php';
require __DIR__ . '/../common/auth_guard.php';

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);

$stmt = $pdo->prepare("
  SELECT local_account_id, account_name, account_type, currency_code, opening_balance, is_active, created_at, updated_at
  FROM ACCOUNTS_LOCAL
  WHERE user_local_id = ?
  ORDER BY is_active DESC, created_at DESC
");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Accounts</title>
</head>
<body>
<h2>Accounts 💼</h2>

<p>
  <a href="<?= APP_BASE ?>/app/auth/accounts/create.php">+ Add Account</a> |
  <a href="<?= APP_BASE ?>/public/dashboard.php">← Back</a>
</p>

<table border="1" cellpadding="8" cellspacing="0">
  <tr>
    <th>Name</th><th>Type</th><th>Currency</th><th>Opening</th><th>Status</th><th>Actions</th>
  </tr>
  <?php foreach($rows as $r): ?>
  <tr>
    <td><?= htmlspecialchars($r['account_name']) ?></td>
    <td><?= htmlspecialchars($r['account_type']) ?></td>
    <td><?= htmlspecialchars($r['currency_code']) ?></td>
    <td><?= number_format((float)$r['opening_balance'], 2) ?></td>
    <td><?= $r['is_active'] ? 'Active' : 'Inactive' ?></td>
    <td>
      <a href="<?= APP_BASE ?>/app/auth/accounts/edit.php?id=<?= (int)$r['local_account_id'] ?>">Edit</a> |
      <a href="<?= APP_BASE ?>/app/auth/accounts/toggle.php?id=<?= (int)$r['local_account_id'] ?>">
        <?= $r['is_active'] ? 'Deactivate' : 'Activate' ?>
      </a>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</body>
</html>
