<?php
// app/reports/index_oracle.php
declare(strict_types=1);

require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/oracle.php'; // oracle_conn(): ?resource
require __DIR__ . '/../../db/sqlite.php';  // sqlite(): PDO
require __DIR__ . '/../auth/common/auth_guard.php';

function h($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }

/* ────────────────────────────────────────────────────────────────────────────
   DATE HELPERS  (Oracle expects DD-MM-YYYY; <input type="date"> is YYYY-MM-DD)
   ──────────────────────────────────────────────────────────────────────────── */
function norm_date(string $s, string $fallback): string {
    $s = trim($s);
    if ($s === '') return $fallback;
    foreach (['d-m-Y','Y-m-d','d/m/Y','m/d/Y','d.m.Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt) return $dt->format('d-m-Y'); // return DD-MM-YYYY
    }
    $ts = strtotime($s);
    return $ts ? date('d-m-Y', $ts) : $fallback;
}
function enforce_ddmmyyyy_or_default(string $s, string $default): string {
    $s = trim($s);
    return preg_match('/^\d{2}-\d{2}-\d{4}$/', $s) ? $s : $default;
}
function ddmmyyyy_plus1(string $ddmmyyyy): string {
    $d = DateTime::createFromFormat('d-m-Y', $ddmmyyyy);
    $d->modify('+1 day');
    return $d->format('d-m-Y');
}
function ddmmyyyy_to_ymd(string $ddmmyyyy): string {
    $d = DateTime::createFromFormat('d-m-Y', $ddmmyyyy);
    return $d ? $d->format('Y-m-d') : '';
}

/* ────────────────────────────────────────────────────────────────────────────
   DB CHOOSER
   ──────────────────────────────────────────────────────────────────────────── */
function try_oracle(): ?array {
    $c = @oracle_conn();
    if (!$c) return null;
    $s = @oci_parse($c, "SELECT 1 FROM DUAL");
    if (!$s || !@oci_execute($s)) return null;
    return ['type'=>'oracle','conn'=>$c];
}
function use_sqlite(): array {
    $pdo = sqlite();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return ['type'=>'sqlite','conn'=>$pdo];
}

/* ────────────────────────────────────────────────────────────────────────────
   QUERY HELPER — keep distinct refs for each bind (OCI8 binds by reference)
   ──────────────────────────────────────────────────────────────────────────── */
function db_query(array $db, string $sql, array $binds = []): array {
    preg_match_all('/:([A-Z0-9_]+)/i', $sql, $m);
    $placeholders = array_change_key_case(array_flip($m[1] ?? []), CASE_UPPER);

    if ($db['type'] === 'oracle') {
        $stmt = oci_parse($db['conn'], $sql);
        if (!$stmt) { $e=oci_error($db['conn']); throw new RuntimeException("Oracle parse error: ".($e['message']??'unknown')); }

        $bindStore = []; // keep variables alive per placeholder
        foreach ($binds as $k => $v) {
            $K = strtoupper(preg_replace('/[^A-Z0-9_]/i','',(string)$k));
            if (!isset($placeholders[$K])) continue;
            $name = ':' . $K;

            if (in_array($K, ['P_FROM','P_TO','P_TO1'], true)) {
                $val = substr(trim((string)$v), 0, 10);
                if (strlen($val) !== 10) throw new RuntimeException("Bad date for $name: '$val' (need DD-MM-YYYY)");
                $bindStore[$name] = $val;
                oci_bind_by_name($stmt, $name, $bindStore[$name], 10, SQLT_CHR);
            } elseif (is_int($v)) {
                $bindStore[$name] = (int)$v; oci_bind_by_name($stmt, $name, $bindStore[$name], -1, SQLT_INT);
            } elseif (is_float($v)) {
                $bindStore[$name] = (float)$v; oci_bind_by_name($stmt, $name, $bindStore[$name], -1, SQLT_FLT);
            } else {
                $bindStore[$name] = trim((string)$v); oci_bind_by_name($stmt, $name, $bindStore[$name]);
            }
        }

        if (!oci_execute($stmt)) { $e=oci_error($stmt); throw new RuntimeException("Oracle execute error: ".($e['message']??'unknown')); }
        $rows=[]; while ($r=oci_fetch_assoc($stmt)) $rows[]=$r; return $rows;
    } else {
        $stmt = $db['conn']->prepare($sql);
        foreach ($binds as $k=>$v) {
            $K = strtoupper(preg_replace('/[^A-Z0-9_]/i','',(string)$k));
            if (!isset($placeholders[$K])) continue;
            $stmt->bindValue(':'.$K, $v);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ────────────────────────────────────────────────────────────────────────────
   CURRENT USER RESOLUTION
   ──────────────────────────────────────────────────────────────────────────── */
$localUid = (int)($_SESSION['uid'] ?? 0);
if ($localUid <= 0) {
    http_response_code(401);
    exit('Not logged in.');
}

// Get mapping to Oracle user (server_user_id) from SQLite USERS_LOCAL
$pdoMap = sqlite();
$mapStmt = $pdoMap->prepare("SELECT server_user_id, email, full_name FROM USERS_LOCAL WHERE local_user_id = ?");
$mapStmt->execute([$localUid]);
$map = $mapStmt->fetch(PDO::FETCH_ASSOC);
$serverUid = (int)($map['server_user_id'] ?? 0);

/* ────────────────────────────────────────────────────────────────────────────
   INPUTS (date range only; user is fixed to logged-in)
   ──────────────────────────────────────────────────────────────────────────── */
$from   = norm_date($_GET['from'] ?? '', date('01-01-Y')); // DD-MM-YYYY
$to     = norm_date($_GET['to']   ?? '', date('d-m-Y'));   // DD-MM-YYYY

$from = enforce_ddmmyyyy_or_default($from, date('01-01-Y'));
$to   = enforce_ddmmyyyy_or_default($to,   date('d-m-Y'));

$fromYmd   = ddmmyyyy_to_ymd($from);
$to_html   = ddmmyyyy_to_ymd($to);
$toPlusYmd = ddmmyyyy_to_ymd(ddmmyyyy_plus1($to));

/* ────────────────────────────────────────────────────────────────────────────
   PICK DB
   ──────────────────────────────────────────────────────────────────────────── */
$db = try_oracle();
if (!$db) { $db = use_sqlite(); }

/* ────────────────────────────────────────────────────────────────────────────
   QUERIES — always filtered to the logged user
   ──────────────────────────────────────────────────────────────────────────── */
$txns = []; $accounts = $overall = [];

if ($db['type'] === 'oracle' && $serverUid > 0) {
    $B = ['P_FROM'=>$from, 'P_TO'=>$to, 'P_UID'=>$serverUid];

    // OVERALL (normalize/trim type)
    $SQL_OVERALL = "
        SELECT
          COUNT(t.server_txn_id) AS CNT,
          NVL(SUM(CASE WHEN TRIM(UPPER(t.txn_type))='INCOME'  THEN t.amount ELSE 0 END),0) AS INCOME,
          NVL(SUM(CASE WHEN TRIM(UPPER(t.txn_type))='EXPENSE' THEN t.amount ELSE 0 END),0) AS EXPENSE,
          MIN(t.txn_date) AS FIRST_TXN,
          MAX(t.txn_date) AS LAST_TXN
        FROM TRANSACTIONS_CLOUD t
        WHERE t.user_server_id = :P_UID
          AND t.txn_date >= TO_DATE(:P_FROM,'DD-MM-YYYY')
          AND t.txn_date <  TO_DATE(:P_TO,'DD-MM-YYYY') + 1
    ";

    // ACCOUNTS (include zero-activity accounts in range)
    $SQL_ACCOUNTS = "
        SELECT
          NVL(a.account_name,'(Unknown)')            AS ACCOUNT_NAME,
          NVL(a.opening_balance,0)                   AS OPENING_BALANCE,
          NVL(SUM(CASE WHEN TRIM(UPPER(t.txn_type))='INCOME'  THEN t.amount ELSE 0 END),0)  AS INC_AMT,
          NVL(SUM(CASE WHEN TRIM(UPPER(t.txn_type))='EXPENSE' THEN t.amount ELSE 0 END),0)  AS EXP_AMT
        FROM TRANSACTIONS_CLOUD t
        LEFT JOIN ACCOUNTS_CLOUD a
          ON a.server_account_id = t.account_server_id
         AND a.user_server_id    = t.user_server_id
        WHERE t.user_server_id = :P_UID
          AND t.txn_date >= TO_DATE(:P_FROM,'DD-MM-YYYY')
          AND t.txn_date <  TO_DATE(:P_TO,'DD-MM-YYYY') + 1
        GROUP BY NVL(a.account_name,'(Unknown)'), NVL(a.opening_balance,0)
        UNION ALL
        SELECT
          a.account_name,
          NVL(a.opening_balance,0),
          0 AS INC_AMT,
          0 AS EXP_AMT
        FROM ACCOUNTS_CLOUD a
        WHERE a.user_server_id = :P_UID
          AND NOT EXISTS (
            SELECT 1 FROM TRANSACTIONS_CLOUD t
            WHERE t.user_server_id = a.user_server_id
              AND t.account_server_id = a.server_account_id
              AND t.txn_date >= TO_DATE(:P_FROM,'DD-MM-YYYY')
              AND t.txn_date <  TO_DATE(:P_TO,'DD-MM-YYYY') + 1
          )
        ORDER BY ACCOUNT_NAME
    ";

    // ALL TRANSACTIONS (this user only)
    $SQL_TXNS = "
        SELECT
          t.server_txn_id,
          t.client_txn_uuid,
          t.user_server_id,
          t.txn_date,
          TRIM(UPPER(t.txn_type)) AS TXN_TYPE,
          t.amount,
          t.note,
          NVL(a.account_name,'Unknown')        AS account_name,
          NVL(c.category_name,'Uncategorized') AS category_name
        FROM TRANSACTIONS_CLOUD t
        LEFT JOIN ACCOUNTS_CLOUD a   ON a.server_account_id   = t.account_server_id
        LEFT JOIN CATEGORIES_CLOUD c ON c.server_category_id  = t.category_server_id
        WHERE t.user_server_id = :P_UID
          AND t.txn_date >= TO_DATE(:P_FROM,'DD-MM-YYYY')
          AND t.txn_date <  TO_DATE(:P_TO,'DD-MM-YYYY') + 1
        ORDER BY t.txn_date DESC, t.server_txn_id DESC
    ";

    $overall  = db_query($db, $SQL_OVERALL,  $B);
    $accounts = db_query($db, $SQL_ACCOUNTS, $B);
    $txns     = db_query($db, $SQL_TXNS,     $B);

} else {
    // SQLITE FALLBACK (uses local ids)
    $B = ['P_FROM'=>$fromYmd, 'P_TO1'=>$toPlusYmd, 'P_UID'=>$localUid];

    $SQL_OVERALL = "
        SELECT
          COUNT(*) AS CNT,
          SUM(CASE WHEN TRIM(UPPER(t.txn_type))='INCOME'  THEN t.amount ELSE 0 END) AS INCOME,
          SUM(CASE WHEN TRIM(UPPER(t.txn_type))='EXPENSE' THEN t.amount ELSE 0 END) AS EXPENSE,
          MIN(substr(t.txn_date,1,10)) AS FIRST_TXN,
          MAX(substr(t.txn_date,1,10)) AS LAST_TXN
        FROM TRANSACTIONS_LOCAL t
        WHERE t.user_local_id = :P_UID
          AND date(substr(t.txn_date,1,10)) >= date(:P_FROM)
          AND date(substr(t.txn_date,1,10)) <  date(:P_TO1)
    ";

    $SQL_ACCOUNTS = "
        SELECT
          IFNULL(a.account_name,'(Unknown)') AS ACCOUNT_NAME,
          IFNULL(a.opening_balance,0)        AS OPENING_BALANCE,
          SUM(CASE WHEN TRIM(UPPER(t.txn_type))='INCOME'  THEN t.amount ELSE 0 END) AS INC_AMT,
          SUM(CASE WHEN TRIM(UPPER(t.txn_type))='EXPENSE' THEN t.amount ELSE 0 END) AS EXP_AMT
        FROM TRANSACTIONS_LOCAL t
        LEFT JOIN ACCOUNTS_LOCAL a
          ON a.local_account_id = t.account_local_id
         AND a.user_local_id    = t.user_local_id
        WHERE t.user_local_id = :P_UID
          AND date(substr(t.txn_date,1,10)) >= date(:P_FROM)
          AND date(substr(t.txn_date,1,10)) <  date(:P_TO1)
        GROUP BY IFNULL(a.account_name,'(Unknown)'), IFNULL(a.opening_balance,0)
        UNION ALL
        SELECT
          a.account_name,
          IFNULL(a.opening_balance,0),
          0 AS INC_AMT,
          0 AS EXP_AMT
        FROM ACCOUNTS_LOCAL a
        WHERE a.user_local_id = :P_UID
          AND NOT EXISTS (
            SELECT 1 FROM TRANSACTIONS_LOCAL t
            WHERE t.user_local_id = a.user_local_id
              AND t.account_local_id = a.local_account_id
              AND date(substr(t.txn_date,1,10)) >= date(:P_FROM)
              AND date(substr(t.txn_date,1,10)) <  date(:P_TO1)
          )
        ORDER BY ACCOUNT_NAME
    ";

    $SQL_TXNS = "
        SELECT
          t.local_txn_id          AS server_txn_id,
          t.client_txn_uuid,
          t.user_local_id         AS user_server_id,
          substr(t.txn_date,1,10) AS txn_date,
          TRIM(UPPER(t.txn_type)) AS TXN_TYPE,
          t.amount,
          t.note,
          IFNULL(a.account_name,'Unknown')        AS account_name,
          IFNULL(c.category_name,'Uncategorized') AS category_name
        FROM TRANSACTIONS_LOCAL t
        LEFT JOIN ACCOUNTS_LOCAL a   ON a.local_account_id   = t.account_local_id
        LEFT JOIN CATEGORIES_LOCAL c ON c.local_category_id  = t.category_local_id
        WHERE t.user_local_id = :P_UID
          AND date(substr(t.txn_date,1,10)) >= date(:P_FROM)
          AND date(substr(t.txn_date,1,10)) <  date(:P_TO1)
        ORDER BY txn_date DESC, server_txn_id DESC
    ";

    $overall  = db_query($db, $SQL_OVERALL,  $B);
    $accounts = db_query($db, $SQL_ACCOUNTS, $B);
    $txns     = db_query($db, $SQL_TXNS,     $B);
}

/* ────────────────────────────────────────────────────────────────────────────
   TOTALS
   ──────────────────────────────────────────────────────────────────────────── */
$totOpen=$totInc=$totExp=$totBal=0.0;
foreach ($accounts as $r) {
    $open=(float)($r['OPENING_BALANCE']??0);
    $inc =(float)($r['INC_AMT']??0);
    $exp =(float)($r['EXP_AMT']??0);
    $bal =$open+$inc-$exp;
    $totOpen+=$open; $totInc+=$inc; $totExp+=$exp; $totBal+=$bal;
}
$ov = [
  'CNT'       => (int)($overall[0]['CNT'] ?? 0),
  'INCOME'    => (float)($overall[0]['INCOME'] ?? 0),
  'EXPENSE'   => (float)($overall[0]['EXPENSE'] ?? 0),
  'FIRST_TXN' => (string)($overall[0]['FIRST_TXN'] ?? ''),
  'LAST_TXN'  => (string)($overall[0]['LAST_TXN'] ?? ''),
];

// HTML date inputs
$from_html = ddmmyyyy_to_ymd($from);
$to_html   = ddmmyyyy_to_ymd($to);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Reports - My PFMS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= APP_BASE ?>/public/dashboard.css">
<style>
  .reports-hero{background:linear-gradient(135deg,#667eea,#764ba2);padding:28px;border-radius:20px;color:#fff;margin-bottom:20px}
  .badge-db{display:inline-flex;gap:8px;padding:6px 12px;border-radius:999px;font-weight:700}
  .oracle{background:rgba(16,185,129,.12);color:#065f46}
  .sqlite{background:rgba(245,158,11,.12);color:#7c2d12}
  .filter-panel{background:#fff;border-radius:16px;padding:20px;border:1px solid #e5e7eb;margin-bottom:20px}
  .filter-row{display:flex;gap:12px;align-items:end;flex-wrap:wrap}
  .filter-group{flex:1;min-width:200px}
  .filter-group label{display:block;margin-bottom:6px;color:#374151;font-weight:600}
  .filter-group input{width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:10px}
  .btn{padding:10px 18px;border-radius:10px;font-weight:700;border:0;cursor:pointer}
  .btn.primary{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
  .btn.outline{background:#fff;color:#667eea;border:2px solid #667eea}
  .btn.success{background:linear-gradient(135deg,#10b981,#059669);color:#fff}
  .report-card{background:#fff;border-radius:16px;padding:20px;border:1px solid #e5e7eb;margin-bottom:20px}
  .report-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
  .report-title{font-size:1.15rem;font-weight:800;color:#111827}
  .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:16px 0}
  .stat{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px}
  .stat .label{font-size:.8rem;color:#6b7280;font-weight:600}
  .stat .value{font-size:1.6rem;font-weight:800;margin-top:6px}
  .data-table{width:100%;border-collapse:separate;border-spacing:0}
  .data-table thead tr{background:linear-gradient(135deg,#667eea,#764ba2)}
  .data-table th{color:#fff;text-align:left;padding:12px;font-weight:800}
  .data-table td{padding:12px;border-bottom:1px solid #e5e7eb}
  .empty{padding:28px;text-align:center;background:#f9fafb;border-radius:12px;border:2px dashed #e5e7eb}
</style>
</head>
<body>
<div class="app-container">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="logo"><i class="fas fa-chart-line"></i><span>PFMS</span></div>
    <nav class="nav-menu">
      <a href="<?= APP_BASE ?>/public/dashboard.php" class="nav-item"><i class="fas fa-home"></i><span>Dashboard</span></a>
      <a href="<?= APP_BASE ?>/app/auth/accounts/index.php" class="nav-item"><i class="fas fa-wallet"></i><span>Accounts</span></a>
      <a href="<?= APP_BASE ?>/app/categories/index.php" class="nav-item"><i class="fas fa-tags"></i><span>Categories</span></a>
      <a href="<?= APP_BASE ?>/app/transactions/index.php" class="nav-item"><i class="fas fa-exchange-alt"></i><span>Transactions</span></a>
      <a href="<?= APP_BASE ?>/app/reports/index_oracle.php" class="nav-item active"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
      <a href="<?= APP_BASE ?>/public/sync.php" class="nav-item"><i class="fas fa-sync-alt"></i><span>Sync</span></a>
    </nav>
    <div class="sidebar-footer">
      <a href="<?= APP_BASE ?>/public/logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
    </div>
  </aside>

  <!-- Main -->
  <main class="main-content">

    <!-- Hero -->
    <div class="reports-hero">
      <h1 style="margin:0 0 6px 0"><i class="fas fa-chart-bar"></i> My Reports</h1>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <span class="badge-db <?= $db['type']==='oracle'?'oracle':'sqlite' ?>">
          <i class="fas fa-<?= $db['type']==='oracle'?'database':'laptop' ?>"></i>
          <?= $db['type']==='oracle' ? 'Oracle' : 'SQLite (Fallback)' ?>
        </span>
        <span><i class="fas fa-user"></i> <?= h($map['full_name'] ?? 'Me') ?> (UID: <?= $db['type']==='oracle' ? $serverUid : $localUid ?>)</span>
        <span><i class="fas fa-calendar-alt"></i> <?= h($from) ?> → <?= h($to) ?></span>
      </div>
    </div>

    <!-- Filters (dates only) -->
    <div class="filter-panel">
      <form method="get" class="filter-row">
        <div class="filter-group">
          <label>From</label>
          <input type="date" name="from" value="<?= h($from_html) ?>" required>
        </div>
        <div class="filter-group">
          <label>To</label>
          <input type="date" name="to" value="<?= h($to_html) ?>" required>
        </div>
        <div style="display:flex;gap:10px;align-items:end">
          <button type="submit" class="btn primary"><i class="fas fa-filter"></i>&nbsp;Apply</button>
          <a class="btn outline" href="<?= APP_BASE ?>/app/reports/index_oracle.php"><i class="fas fa-redo"></i>&nbsp;Reset</a>
          <button type="button" class="btn success" onclick="downloadReport()"><i class="fas fa-download"></i>&nbsp;Export</button>
        </div>
      </form>
    </div>

    <!-- Overall -->
    <div class="report-card">
      <div class="report-header">
        <div class="report-title"><i class="fas fa-chart-line" style="color:#667eea"></i>&nbsp;Overall Summary</div>
      </div>
      <div class="stats-grid">
        <div class="stat"><div class="label">Total Transactions</div><div class="value"><?= number_format($ov['CNT']) ?></div></div>
        <div class="stat"><div class="label">Total Income</div><div class="value" style="color:#059669">$<?= number_format($ov['INCOME'],2) ?></div></div>
        <div class="stat"><div class="label">Total Expense</div><div class="value" style="color:#dc2626">$<?= number_format($ov['EXPENSE'],2) ?></div></div>
        <?php $net = $ov['INCOME']-$ov['EXPENSE']; ?>
        <div class="stat"><div class="label">Net Amount</div><div class="value" style="color:<?= $net>=0?'#059669':'#dc2626' ?>">$<?= number_format($net,2) ?></div></div>
      </div>
      <table class="data-table">
        <thead><tr><th>First Transaction</th><th>Last Transaction</th></tr></thead>
        <tbody><tr><td><?= h($ov['FIRST_TXN']) ?: 'N/A' ?></td><td><?= h($ov['LAST_TXN']) ?: 'N/A' ?></td></tr></tbody>
      </table>
    </div>

    <!-- Accounts -->
    <div class="report-card">
      <div class="report-header">
        <div class="report-title"><i class="fas fa-wallet" style="color:#667eea"></i>&nbsp;Account Balance</div>
        <span style="background:#eef2ff;color:#4f46e5;padding:6px 10px;border-radius:999px;font-weight:700"><?= count($accounts) ?> Accounts</span>
      </div>
      <?php if (empty($accounts)): ?>
        <div class="empty"><i class="fas fa-wallet"></i><p>No accounts in this period.</p></div>
      <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Account</th><th>Opening</th><th>Income</th><th>Expense</th><th>Balance</th></tr></thead>
        <tbody>
          <?php foreach($accounts as $a):
            $open=(float)($a['OPENING_BALANCE']??0);
            $inc =(float)($a['INC_AMT']??0);
            $exp =(float)($a['EXP_AMT']??0);
            $bal =$open+$inc-$exp;
          ?>
          <tr>
            <td><strong><?= h((string)($a['ACCOUNT_NAME'] ?? 'Unknown')) ?></strong></td>
            <td>$<?= number_format($open,2) ?></td>
            <td style="color:#059669">$<?= number_format($inc,2) ?></td>
            <td style="color:#dc2626">$<?= number_format($exp,2) ?></td>
            <td style="color:<?= $bal>=0?'#059669':'#dc2626' ?>"><strong>$<?= number_format($bal,2) ?></strong></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td><strong>TOTAL</strong></td>
            <td><strong>$<?= number_format($totOpen,2) ?></strong></td>
            <td style="color:#059669"><strong>$<?= number_format($totInc,2) ?></strong></td>
            <td style="color:#dc2626"><strong>$<?= number_format($totExp,2) ?></strong></td>
            <td style="color:<?= $totBal>=0?'#059669':'#dc2626' ?>"><strong>$<?= number_format($totBal,2) ?></strong></td>
          </tr>
        </tfoot>
      </table>
      <?php endif; ?>
    </div>

    <!-- All Transactions (no other users visible) -->
    <div class="report-card">
      <div class="report-header">
        <div class="report-title"><i class="fas fa-receipt" style="color:#667eea"></i>&nbsp;All My Transactions (<?= count($txns) ?>)</div>
        <button class="btn success" onclick="downloadTxnsCsv()"><i class="fas fa-file-csv"></i>&nbsp;Export CSV</button>
      </div>
      <?php if (empty($txns)): ?>
        <div class="empty"><i class="fas fa-inbox"></i><p>No transactions for this period.</p></div>
      <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Account</th><th>Category</th><th>Note</th><th>UUID</th></tr></thead>
        <tbody>
          <?php foreach ($txns as $t): ?>
          <tr>
            <td><?= h($t['TXN_DATE']) ?></td>
            <td><?= h($t['TXN_TYPE']) ?></td>
            <td style="color:<?= strtoupper((string)$t['TXN_TYPE'])==='EXPENSE'?'#dc2626':'#059669' ?>"><strong>$<?= number_format((float)$t['AMOUNT'],2) ?></strong></td>
            <td><?= h($t['ACCOUNT_NAME']) ?></td>
            <td><?= h($t['CATEGORY_NAME']) ?></td>
            <td><?= h((string)($t['NOTE'] ?? '')) ?></td>
            <td style="font-family:monospace"><?= h((string)$t['CLIENT_TXN_UUID']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="report-card">
      <div class="report-header"><div class="report-title"><i class="fas fa-bolt" style="color:#667eea"></i>&nbsp;Quick Actions</div></div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button onclick="window.print()" class="btn primary"><i class="fas fa-print"></i>&nbsp;Print</button>
        <button onclick="downloadReport('csv')" class="btn success"><i class="fas fa-file-csv"></i>&nbsp;Export Summary CSV</button>
        <button onclick="downloadReport('pdf')" class="btn success"><i class="fas fa-file-pdf"></i>&nbsp;Export PDF</button>
        <a href="<?= APP_BASE ?>/public/dashboard.php" class="btn outline" style="text-decoration:none"><i class="fas fa-home"></i>&nbsp;Back to Dashboard</a>
      </div>
    </div>

  </main>
</div>

<script>
// Summary CSV / PDF
function downloadReport(format='csv'){
  if(format==='csv'){
    let csv = 'PFMS My Financial Report\\n';
    csv += 'Generated,' + new Date().toLocaleString() + '\\n';
    csv += 'Period,<?= h($from) ?> to <?= h($to) ?>\\n';
    csv += 'Database,<?= $db['type'] ?>\\n\\n';
    csv += 'OVERALL SUMMARY\\n';
    csv += 'Total Transactions,Income,Expense,Net\\n';
    csv += '<?= $ov['CNT'] ?>,<?= number_format($ov['INCOME'],2) ?>,<?= number_format($ov['EXPENSE'],2) ?>,<?= number_format($ov['INCOME']-$ov['EXPENSE'],2) ?>\\n\\n';
    csv += 'ACCOUNT BALANCE\\n';
    csv += 'Account,Opening,Income,Expense,Balance\\n';
    <?php foreach ($accounts as $a):
      $open=(float)($a['OPENING_BALANCE']??0);
      $inc =(float)($a['INC_AMT']??0);
      $exp =(float)($a['EXP_AMT']??0);
      $bal =$open+$inc-$exp;
    ?>
      csv += '<?= h((string)($a['ACCOUNT_NAME'] ?? 'Unknown')) ?>,<?= number_format($open,2) ?>,<?= number_format($inc,2) ?>,<?= number_format($exp,2) ?>,<?= number_format($bal,2) ?>\\n';
    <?php endforeach; ?>
    const blob = new Blob([csv],{type:'text/csv'}), url=URL.createObjectURL(blob), a=document.createElement('a');
    a.href=url; a.download='PFMS_My_Report_<?= date('Ymd') ?>.csv'; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
    alert('Summary exported as CSV');
  } else {
    alert('Use browser Print → Save as PDF'); window.print();
  }
}

// Transactions CSV
function downloadTxnsCsv(){
  let csv = 'Date,Type,Amount,Account,Category,Note,UUID\\n';
  <?php foreach ($txns as $t):
    $note = str_replace(["\r","\n",","],[" "," ",";"], (string)($t['NOTE'] ?? ''));
  ?>
    csv += '<?= h($t['TXN_DATE']) ?>,<?= h($t['TXN_TYPE']) ?>,<?= number_format((float)$t['AMOUNT'],2) ?>,<?= h($t['ACCOUNT_NAME']) ?>,<?= h($t['CATEGORY_NAME']) ?>,<?= $note ?>,<?= h((string)$t['CLIENT_TXN_UUID']) ?>\\n';
  <?php endforeach; ?>
  const blob = new Blob([csv],{type:'text/csv'}), url=URL.createObjectURL(blob), a=document.createElement('a');
  a.href=url; a.download='PFMS_My_Transactions_<?= date('Ymd') ?>.csv'; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
}

// Shortcuts
document.addEventListener('keydown', e=>{
  if((e.ctrlKey||e.metaKey) && e.key==='p'){ e.preventDefault(); window.print(); }
  if((e.ctrlKey||e.metaKey) && e.key==='e'){ e.preventDefault(); downloadReport('csv'); }
});
</script>
</body>
</html>
