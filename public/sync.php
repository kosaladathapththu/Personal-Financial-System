<?php
require __DIR__ . '/../config/env.php';
require __DIR__ . '/../db/sqlite.php';
require __DIR__ . '/../app/auth/common/auth_guard.php'; // guard

// small helper if not defined elsewhere
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);

// -------- Stats (clean) --------
$stmt = $pdo->prepare("SELECT COUNT(*) FROM TRANSACTIONS_LOCAL WHERE user_local_id=? AND sync_status='PENDING'");
$stmt->execute([$uid]);
$pendingTxn = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM TRANSACTIONS_LOCAL WHERE user_local_id=? AND sync_status='SYNCED'");
$stmt->execute([$uid]);
$syncedTxn = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM ACCOUNTS_LOCAL WHERE user_local_id=? AND (server_account_id IS NULL OR server_account_id='')");
$stmt->execute([$uid]);
$accNoSrv = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM CATEGORIES_LOCAL WHERE user_local_id=? AND (server_category_id IS NULL OR server_category_id='')");
$stmt->execute([$uid]);
$catNoSrv = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT MAX(last_sync_at) FROM TRANSACTIONS_LOCAL WHERE user_local_id=?");
$stmt->execute([$uid]);
$lastSync = $stmt->fetchColumn();

$msg = $_GET['msg'] ?? '';
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Sync Center</title>
    <link rel="stylesheet" href="<?= APP_BASE ?>/app/sync/sync.css">
    <link rel="stylesheet" href="sync.css">
</head>
<body>
<h2>Sync Center 🔁</h2>
<p><a href="<?= APP_BASE ?>/public/dashboard.php">← Back to Dashboard</a></p>

<?php if ($msg): ?>
    <div class="msg">✅ <?= h($msg) ?></div>
<?php endif; ?>

<h3>Status 📈</h3>
<ul>
    <li>Pending Transactions: <b><?= $pendingTxn ?></b></li>
    <li>Synced Transactions: <b><?= $syncedTxn ?></b></li>
    <li>Accounts without Server ID: <b><?= $accNoSrv ?></b></li>
    <li>Categories without Server ID: <b><?= $catNoSrv ?></b></li>
    <li>Last Sync: <b><?= $lastSync ?: '— never —' ?></b></li>
</ul>

<h3>Actions ⚙️</h3>
<form method="post" action="<?= APP_BASE ?>/api/transactions_push.php" style="display:inline-block; margin-right:8px;">
    <button type="submit">📤 Push Transactions ➜ Oracle (simulate)</button>
</form>

<form method="post" action="<?= APP_BASE ?>/api/accounts_push.php" style="display:inline-block; margin-right:8px;">
    <button type="submit">📤 Push Accounts ➜ Oracle (simulate)</button>
</form>

<form method="post" action="<?= APP_BASE ?>/api/categories_pull.php" style="display:inline-block;">
    <button type="submit">📥 Pull Categories ⬅ Oracle (simulate)</button>
</form>

<h3>Recent Pending (debug) 🧪</h3>
<table>
    <tr><th>Date</th><th>Type</th><th>Amount</th><th>Client UUID</th></tr>
    <?php
    $dbg = $pdo->prepare("
        SELECT txn_date, txn_type, amount, client_txn_uuid
        FROM TRANSACTIONS_LOCAL
        WHERE user_local_id=? AND sync_status='PENDING'
        ORDER BY datetime(txn_date) DESC, local_txn_id DESC
        LIMIT 10
    ");
    $dbg->execute([$uid]);
    foreach ($dbg->fetchAll(PDO::FETCH_ASSOC) as $d):
    ?>
        <tr>
            <td><?= h($d['txn_date']) ?></td>
            <td><?= h($d['txn_type']) ?></td>
            <td style="text-align:right"><?= number_format((float)$d['amount'],2) ?></td>
            <td><?= h($d['client_txn_uuid']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>
</body>
</html>
