<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../auth/common/auth_guard.php'; // ✅ Auth guard

$pdo = sqlite();
$uid = (int)$_SESSION['uid'];

function h($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }

// ── Inputs ──────────────────────────────────────────────────────────────
$view   = $_GET['view'] ?? 'monthly'; // monthly | category | accounts
$from   = $_GET['from'] ?? date('Y-01-01');
$to     = $_GET['to']   ?? date('Y-m-d');
$export = isset($_GET['export']) ? 1 : 0;

// ── Shared WHERE & args for date range ─────────────────────────────────
$where = "t.user_local_id = ? AND date(t.txn_date) BETWEEN date(?) AND date(?)";
$args  = [$uid, $from, $to];

// ── View: Monthly Summary ──────────────────────────────────────────────
if ($view === 'monthly') {
    $sql = "
        SELECT
            strftime('%Y-%m', t.txn_date) AS ym,
            SUM(CASE WHEN t.txn_type='INCOME'  THEN t.amount ELSE 0 END) AS income,
            SUM(CASE WHEN t.txn_type='EXPENSE' THEN t.amount ELSE 0 END) AS expense
        FROM TRANSACTIONS_LOCAL t
        WHERE $where
        GROUP BY ym
        ORDER BY ym
    ";
    $st = $pdo->prepare($sql); 
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($export) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="pfms_monthly_summary.csv"');
        echo "Month,Income,Expense,Net\n";
        foreach($rows as $r){
            $net = (float)$r['income'] - (float)$r['expense'];
            echo $r['ym'].",".number_format((float)$r['income'],2).",".number_format((float)$r['expense'],2).",".number_format($net,2)."\n";
        }
        exit;
    }
}

// ── View: By Category ──────────────────────────────────────────────────
if ($view === 'category') {
    $sql = "
        SELECT
            c.category_type,
            c.category_name,
            SUM(t.amount) AS total_amount
        FROM TRANSACTIONS_LOCAL t
        JOIN CATEGORIES_LOCAL c ON c.local_category_id = t.category_local_id
        WHERE $where
        GROUP BY c.category_type, c.category_name
        ORDER BY c.category_type, total_amount DESC, c.category_name
    ";
    $st = $pdo->prepare($sql); 
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($export) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="pfms_category_breakdown.csv"');
        echo "Type,Category,Total\n";
        foreach($rows as $r){
            echo $r['category_type'].",".str_replace(',', ' ', $r['category_name']).",".number_format((float)$r['total_amount'],2)."\n";
        }
        exit;
    }
}

// ── View: Account Balances ─────────────────────────────────────────────
if ($view === 'accounts') {
    $accSql = "
        SELECT
            a.local_account_id,
            a.account_name,
            a.opening_balance,
            COALESCE(SUM(CASE WHEN t.txn_type='INCOME'  THEN t.amount END),0)  AS inc_amt,
            COALESCE(SUM(CASE WHEN t.txn_type='EXPENSE' THEN t.amount END),0)  AS exp_amt
        FROM ACCOUNTS_LOCAL a
        LEFT JOIN TRANSACTIONS_LOCAL t
            ON t.account_local_id = a.local_account_id
           AND $where
        WHERE a.user_local_id = ?
        GROUP BY a.local_account_id, a.account_name, a.opening_balance
        ORDER BY a.account_name
    ";
    $st = $pdo->prepare($accSql); 
    $st->execute([$uid,$from,$to,$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($export) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="pfms_account_balances.csv"');
        echo "Account,Opening,Income,Expense,Balance\n";
        foreach($rows as $r){
            $bal = (float)$r['opening_balance'] + (float)$r['inc_amt'] - (float)$r['exp_amt'];
            echo str_replace(',', ' ', $r['account_name']).",".number_format((float)$r['opening_balance'],2).",".number_format((float)$r['inc_amt'],2).",".number_format((float)$r['exp_amt'],2).",".number_format($bal,2)."\n";
        }
        exit;
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reports 📊</title>
    <link rel="stylesheet" href="<?= APP_BASE ?>/app/reports/index.css">
</head>
<body>
<h2>Reports 📊</h2>
<p><a href="<?= APP_BASE ?>/public/dashboard.php">← Back</a></p>

<form method="get" class="filter-form">
    <b>Filters ⏱️</b>
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <label>From</label>
    <input type="date" name="from" value="<?= h($from) ?>">
    <label>To</label>
    <input type="date" name="to" value="<?= h($to) ?>">
    <button type="submit">Apply</button>
    <a href="<?= APP_BASE ?>/app/reports/index.php?view=<?= h($view) ?>">Reset</a>
</form>

<nav class="view-nav">
    <b>Views:</b>
    <a href="<?= APP_BASE ?>/app/reports/index.php?view=monthly&from=<?= h($from) ?>&to=<?= h($to) ?>">📆 Monthly</a> |
    <a href="<?= APP_BASE ?>/app/reports/index.php?view=category&from=<?= h($from) ?>&to=<?= h($to) ?>">🏷️ Category</a> |
    <a href="<?= APP_BASE ?>/app/reports/index.php?view=accounts&from=<?= h($from) ?>&to=<?= h($to) ?>">💼 Accounts</a>
</nav>

<?php if ($view === 'monthly'): ?>
    <h3>Monthly Summary (<?= h($from) ?> → <?= h($to) ?>)</h3>
    <p><a href="<?= APP_BASE ?>/app/reports/index.php?view=monthly&from=<?= h($from) ?>&to=<?= h($to) ?>&export=1">⬇️ Export CSV</a></p>
    <table class="report-table">
        <tr><th>Month</th><th>Income</th><th>Expense</th><th>Net</th></tr>
        <?php $sumInc=0; $sumExp=0; foreach($rows as $r): $sumInc+=(float)$r['income']; $sumExp+=(float)$r['expense']; ?>
            <tr>
                <td><?= h($r['ym']) ?></td>
                <td><?= number_format((float)$r['income'],2) ?></td>
                <td><?= number_format((float)$r['expense'],2) ?></td>
                <td><?= number_format((float)$r['income'] - (float)$r['expense'],2) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr><th>Total</th><th><?= number_format($sumInc,2) ?></th><th><?= number_format($sumExp,2) ?></th><th><?= number_format($sumInc-$sumExp,2) ?></th></tr>
    </table>
<?php endif; ?>

<?php if ($view === 'category'): ?>
    <h3>By Category (<?= h($from) ?> → <?= h($to) ?>)</h3>
    <p><a href="<?= APP_BASE ?>/app/reports/index.php?view=category&from=<?= h($from) ?>&to=<?= h($to) ?>&export=1">⬇️ Export CSV</a></p>
    <table class="report-table">
        <tr><th>Type</th><th>Category</th><th>Total</th></tr>
        <?php $sum=0; foreach($rows as $r): $sum+=(float)$r['total_amount']; ?>
            <tr>
                <td><?= h($r['category_type']) ?></td>
                <td><?= h($r['category_name']) ?></td>
                <td><?= number_format((float)$r['total_amount'],2) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr><th colspan="2">Grand Total</th><th><?= number_format($sum,2) ?></th></tr>
    </table>
<?php endif; ?>

<?php if ($view === 'accounts'): ?>
    <h3>Account Balances (<?= h($from) ?> → <?= h($to) ?>)</h3>
    <p><a href="<?= APP_BASE ?>/app/reports/index.php?view=accounts&from=<?= h($from) ?>&to=<?= h($to) ?>&export=1">⬇️ Export CSV</a></p>
    <table class="report-table">
        <tr><th>Account</th><th>Opening</th><th>Income</th><th>Expense</th><th>Balance</th></tr>
        <?php $totOpen=0;$totInc=0;$totExp=0;$totBal=0; foreach($rows as $r):
            $bal = (float)$r['opening_balance'] + (float)$r['inc_amt'] - (float)$r['exp_amt'];
            $totOpen+=(float)$r['opening_balance']; $totInc+=(float)$r['inc_amt']; $totExp+=(float)$r['exp_amt']; $totBal+=$bal; ?>
            <tr>
                <td><?= h($r['account_name']) ?></td>
                <td><?= number_format((float)$r['opening_balance'],2) ?></td>
                <td><?= number_format((float)$r['inc_amt'],2) ?></td>
                <td><?= number_format((float)$r['exp_amt'],2) ?></td>
                <td><?= number_format($bal,2) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr><th>Total</th><th><?= number_format($totOpen,2) ?></th><th><?= number_format($totInc,2) ?></th><th><?= number_format($totExp,2) ?></th><th><?= number_format($totBal,2) ?></th></tr>
    </table>
<?php endif; ?>
</body>
</html>
