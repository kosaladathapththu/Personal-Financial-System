<?php
// app/transactions/index.php
declare(strict_types=1);

require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../../db/oracle.php';
require __DIR__ . '/../auth/common/auth_guard.php';
require __DIR__ . '/../auth/common/util.php';

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
function klower(array $r): array { $o=[]; foreach($r as $k=>$v){ $o[strtolower((string)$k)]=$v; } return $o; }

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);

// --- Map local -> server user id (Oracle scope) -----------------------------
$st = $pdo->prepare("SELECT server_user_id FROM USERS_LOCAL WHERE local_user_id = ?");
$st->execute([$uid]);
$serverUid = (int)($st->fetchColumn() ?: 0);

// --- Inputs (filters) -------------------------------------------------------
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');
$acc  = trim($_GET['account'] ?? '');
$cat  = trim($_GET['category'] ?? '');
$typ  = trim($_GET['type'] ?? ''); // INCOME | EXPENSE | ''

// --- Oracle first -----------------------------------------------------------
$oconn = @oracle_conn();
$use_oracle = false;
if ($oconn && $serverUid > 0) {
    $probe = @oci_parse($oconn, "SELECT 1 FROM DUAL");
    if ($probe && @oci_execute($probe)) $use_oracle = true;
}

// ---------- Helper: call Oracle totals via procedure/function ---------------
function oracle_overall_summary($oconn, int $serverUid, string $from, string $to): ?array {
    // Normalize dates (allow empty => open range)
    $p_from = $from !== '' ? $from : '1900-01-01';
    $p_to   = $to   !== '' ? $to   : date('Y-m-d');

    // 1) Try packaged procedure: PKG_REPORTS.PR_OVERALL_SUMMARY
    $sql = "BEGIN PKG_REPORTS.PR_OVERALL_SUMMARY(:p_uid, TO_DATE(:p_from,'YYYY-MM-DD'), TO_DATE(:p_to,'YYYY-MM-DD'), :o_income, :o_expense); END;";
    $stid = @oci_parse($oconn, $sql);
    if ($stid) {
        @oci_bind_by_name($stid, ':p_uid', $serverUid, -1, SQLT_INT);
        @oci_bind_by_name($stid, ':p_from', $p_from);
        @oci_bind_by_name($stid, ':p_to',   $p_to);
        $o_income = $o_expense = "0";
        @oci_bind_by_name($stid, ':o_income',  $o_income,  40);
        @oci_bind_by_name($stid, ':o_expense', $o_expense, 40);
        if (@oci_execute($stid)) {
            return ['income' => (float)$o_income, 'expense' => (float)$o_expense];
        }
    }

    // 2) Try standalone procedure: PR_OVERALL_SUMMARY
    $sql = "BEGIN PR_OVERALL_SUMMARY(:p_uid, TO_DATE(:p_from,'YYYY-MM-DD'), TO_DATE(:p_to,'YYYY-MM-DD'), :o_income, :o_expense); END;";
    $stid = @oci_parse($oconn, $sql);
    if ($stid) {
        @oci_bind_by_name($stid, ':p_uid', $serverUid, -1, SQLT_INT);
        @oci_bind_by_name($stid, ':p_from', $p_from);
        @oci_bind_by_name($stid, ':p_to',   $p_to);
        $o_income = $o_expense = "0";
        @oci_bind_by_name($stid, ':o_income',  $o_income,  40);
        @oci_bind_by_name($stid, ':o_expense', $o_expense, 40);
        if (@oci_execute($stid)) {
            return ['income' => (float)$o_income, 'expense' => (float)$o_expense];
        }
    }

    // 3) Try functions on DUAL (packaged)
    $sql = "
      SELECT 
        PKG_REPORTS.FN_TOTAL_INCOME(:p_uid, TO_DATE(:p_from,'YYYY-MM-DD'), TO_DATE(:p_to,'YYYY-MM-DD')) AS INCOME,
        PKG_REPORTS.FN_TOTAL_EXPENSE(:p_uid, TO_DATE(:p_from,'YYYY-MM-DD'), TO_DATE(:p_to,'YYYY-MM-DD')) AS EXPENSE
      FROM DUAL";
    $stid = @oci_parse($oconn, $sql);
    if ($stid) {
        @oci_bind_by_name($stid, ':p_uid',  $serverUid, -1, SQLT_INT);
        @oci_bind_by_name($stid, ':p_from', $p_from);
        @oci_bind_by_name($stid, ':p_to',   $p_to);
        if (@oci_execute($stid)) {
            $r = @oci_fetch_assoc($stid);
            if ($r) return ['income' => (float)$r['INCOME'], 'expense' => (float)$r['EXPENSE']];
        }
    }

    // 4) Try functions without package (FN_TOTAL_INCOME / FN_TOTAL_EXPENSE)
    $sql = "
      SELECT 
        FN_TOTAL_INCOME(:p_uid, TO_DATE(:p_from,'YYYY-MM-DD'), TO_DATE(:p_to,'YYYY-MM-DD')) AS INCOME,
        FN_TOTAL_EXPENSE(:p_uid, TO_DATE(:p_from,'YYYY-MM-DD'), TO_DATE(:p_to,'YYYY-MM-DD')) AS EXPENSE
      FROM DUAL";
    $stid = @oci_parse($oconn, $sql);
    if ($stid) {
        @oci_bind_by_name($stid, ':p_uid',  $serverUid, -1, SQLT_INT);
        @oci_bind_by_name($stid, ':p_from', $p_from);
        @oci_bind_by_name($stid, ':p_to',   $p_to);
        if (@oci_execute($stid)) {
            $r = @oci_fetch_assoc($stid);
            if ($r) return ['income' => (float)$r['INCOME'], 'expense' => (float)$r['EXPENSE']];
        }
    }

    return null; // caller will fallback
}

// --- Dropdowns (same as before) --------------------------------------------
$accounts = [];
$categories = [];

if ($use_oracle) {
    $SQL_A = "SELECT server_account_id AS local_account_id, account_name
              FROM ACCOUNTS_CLOUD
              WHERE user_server_id = :P_UID AND NVL(is_active,1)=1
              ORDER BY account_name";
    $sa = oci_parse($oconn, $SQL_A);
    oci_bind_by_name($sa, ':P_UID', $serverUid, -1, SQLT_INT);
    oci_execute($sa);
    while ($r = oci_fetch_assoc($sa)) $accounts[] = klower($r);

    $SQL_C = "SELECT server_category_id AS local_category_id, category_name
              FROM CATEGORIES_CLOUD
              WHERE user_server_id = :P_UID
              ORDER BY category_type, category_name";
    $sc = oci_parse($oconn, $SQL_C);
    oci_bind_by_name($sc, ':P_UID', $serverUid, -1, SQLT_INT);
    oci_execute($sc);
    while ($r = oci_fetch_assoc($sc)) $categories[] = klower($r);
} else {
    $qa = $pdo->prepare("SELECT local_account_id, account_name FROM ACCOUNTS_LOCAL WHERE user_local_id=? AND is_active=1 ORDER BY account_name");
    $qa->execute([$uid]);
    $accounts = $qa->fetchAll(PDO::FETCH_ASSOC);

    $qc = $pdo->prepare("SELECT local_category_id, category_name FROM CATEGORIES_LOCAL WHERE user_local_id=? ORDER BY category_type, category_name");
    $qc->execute([$uid]);
    $categories = $qc->fetchAll(PDO::FETCH_ASSOC);
}

// --- Transactions + totals --------------------------------------------------
$rows = [];
$income = $expense = 0.0;

if ($use_oracle) {
    // Build WHERE
    $wh = ["t.user_server_id = :P_UID"];
    $binds = [':P_UID' => $serverUid];

    if ($from !== '') { $wh[] = "t.txn_date >= TO_DATE(:P_FROM,'YYYY-MM-DD')"; $binds[':P_FROM']=$from; }
    if ($to   !== '') { $wh[] = "t.txn_date <  TO_DATE(:P_TO,'YYYY-MM-DD') + 1"; $binds[':P_TO']=$to; }
    if ($acc  !== '') { $wh[] = "t.account_server_id = :P_ACC"; $binds[':P_ACC']=(int)$acc; }
    if ($cat  !== '') { $wh[] = "t.category_server_id = :P_CAT"; $binds[':P_CAT']=(int)$cat; }
    if ($typ  !== '' && in_array($typ, ['INCOME','EXPENSE'], true)) {
        $wh[] = "UPPER(t.txn_type) = :P_TYP"; $binds[':P_TYP']=strtoupper($typ);
    }

    // Transactions
    $SQL_TX = "
      SELECT
        t.server_txn_id                 AS local_txn_id,
        t.client_txn_uuid               AS client_txn_uuid,
        UPPER(t.txn_type)               AS txn_type,
        t.amount                        AS amount,
        t.txn_date                      AS txn_date,
        t.note                          AS note,
        NVL(a.account_name,'Unknown')   AS account_name,
        NVL(c.category_name,'Uncategorized') AS category_name
      FROM TRANSACTIONS_CLOUD t
      LEFT JOIN ACCOUNTS_CLOUD a   ON a.server_account_id  = t.account_server_id
      LEFT JOIN CATEGORIES_CLOUD c ON c.server_category_id = t.category_server_id
      WHERE " . implode(' AND ', $wh) . "
      ORDER BY t.txn_date DESC, t.server_txn_id DESC
      FETCH FIRST 200 ROWS ONLY
    ";
    $stx = oci_parse($oconn, $SQL_TX);
    foreach ($binds as $k=>$v) {
        if (in_array($k, [':P_UID',':P_ACC',':P_CAT'], true)) {
            oci_bind_by_name($stx, $k, $binds[$k], -1, SQLT_INT);
        } else {
            $s = (string)$v; oci_bind_by_name($stx, $k, $s);
        }
    }
    oci_execute($stx);
    while ($r = oci_fetch_assoc($stx)) {
        $r = klower($r);
        $r['sync_status'] = 'SYNCED';
        $r['txn_date'] = date('Y-m-d H:i:s', is_numeric($r['txn_date']) ? (int)$r['txn_date'] : strtotime((string)$r['txn_date']));
        $rows[] = $r;
    }

    // --- Totals: use procedures/functions first ----------------------------
    $tot = oracle_overall_summary($oconn, $serverUid, $from, $to);
    if ($tot !== null) {
        $income  = (float)$tot['income'];
        $expense = (float)$tot['expense'];
    } else {
        // Final fallback: inline SUM
        $SQL_SUM = "
          SELECT
            NVL(SUM(CASE WHEN UPPER(t.txn_type)='INCOME'  THEN t.amount ELSE 0 END),0) AS total_income,
            NVL(SUM(CASE WHEN UPPER(t.txn_type)='EXPENSE' THEN t.amount ELSE 0 END),0) AS total_expense
          FROM TRANSACTIONS_CLOUD t
          WHERE " . implode(' AND ', $wh) . "
        ";
        $ss = oci_parse($oconn, $SQL_SUM);
        foreach ($binds as $k=>$v) {
            if (in_array($k, [':P_UID',':P_ACC',':P_CAT'], true)) oci_bind_by_name($ss, $k, $binds[$k], -1, SQLT_INT);
            else { $s = (string)$v; oci_bind_by_name($ss, $k, $s); }
        }
        oci_execute($ss);
        $rr = oci_fetch_assoc($ss);
        $income  = (float)($rr['TOTAL_INCOME'] ?? 0);
        $expense = (float)($rr['TOTAL_EXPENSE'] ?? 0);
    }

} else {
    // SQLite fallback (unchanged)
    $where = ["t.user_local_id = ?"];
    $args  = [$uid];

    if ($from !== '') { $where[] = "date(t.txn_date) >= date(?)"; $args[] = $from; }
    if ($to   !== '') { $where[] = "date(t.txn_date) <= date(?)"; $args[] = $to; }
    if ($acc  !== '') { $where[] = "t.account_local_id = ?";      $args[] = (int)$acc; }
    if ($cat  !== '') { $where[] = "t.category_local_id = ?";     $args[] = (int)$cat; }
    if ($typ  !== '' && in_array($typ, ['INCOME','EXPENSE'], true)) { $where[] = "t.txn_type = ?"; $args[] = $typ; }

    $sql = "
      SELECT
        t.local_txn_id, t.client_txn_uuid, t.txn_type, t.amount, t.txn_date, t.note, t.sync_status,
        a.account_name, c.category_name
      FROM TRANSACTIONS_LOCAL t
      JOIN ACCOUNTS_LOCAL a   ON a.local_account_id   = t.account_local_id
      JOIN CATEGORIES_LOCAL c ON c.local_category_id  = t.category_local_id
      WHERE " . implode(' AND ', $where) . "
      ORDER BY datetime(t.txn_date) DESC, t.local_txn_id DESC
      LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totSql = "
      SELECT
        SUM(CASE WHEN t.txn_type='INCOME'  THEN t.amount ELSE 0 END) AS total_income,
        SUM(CASE WHEN t.txn_type='EXPENSE' THEN t.amount ELSE 0 END) AS total_expense
      FROM TRANSACTIONS_LOCAL t
      WHERE " . implode(' AND ', $where);
    $sum = $pdo->prepare($totSql);
    $sum->execute($args);
    $tot = $sum->fetch(PDO::FETCH_ASSOC);
    $income  = (float)($tot['total_income'] ?? 0);
    $expense = (float)($tot['total_expense'] ?? 0);
}

$net = $income - $expense;

// --- Stats & charts (same as before) ---------------------------------------
$total_transactions = count($rows);
$synced  = $use_oracle ? $total_transactions : count(array_filter($rows, fn($r) => ($r['sync_status'] ?? '') === 'SYNCED'));
$pending = $total_transactions - $synced;

// Monthly series from fetched rows
$monthly_data = [];
foreach ($rows as $r) {
    $month = date('M Y', strtotime($r['txn_date']));
    if (!isset($monthly_data[$month])) $monthly_data[$month] = ['income'=>0,'expense'=>0];
    if (strtoupper((string)$r['txn_type']) === 'INCOME') $monthly_data[$month]['income'] += (float)$r['amount'];
    else $monthly_data[$month]['expense'] += (float)$r['amount'];
}
$months = array_keys($monthly_data);
usort($months, fn($a,$b)=>strtotime('01 '.$b) <=> strtotime('01 '.$a));
$months = array_slice($months, 0, 6);
$monthly_data_sorted = [];
foreach (array_reverse($months) as $m) $monthly_data_sorted[$m] = $monthly_data[$m];

// Recent
$recent = array_slice($rows, 0, 5);

// -------------- render (UI unchanged; your pretty CSS applies) -------------
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Transactions - PFMS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
  <link rel="stylesheet" href="index.css">
</head>
<body>
<div class="app-container">
  <aside class="sidebar">
    <div class="logo"><i class="fas fa-chart-line"></i><span>PFMS</span></div>
    <nav class="nav-menu">
      <a href="<?= APP_BASE ?>/public/dashboard.php" class="nav-item"><i class="fas fa-home"></i><span>Dashboard</span></a>
      <a href="<?= APP_BASE ?>/app/auth/accounts/index.php" class="nav-item"><i class="fas fa-wallet"></i><span>Accounts</span></a>
      <a href="<?= APP_BASE ?>/app/categories/index.php" class="nav-item"><i class="fas fa-tags"></i><span>Categories</span></a>
      <a href="<?= APP_BASE ?>/app/transactions/index.php" class="nav-item active"><i class="fas fa-exchange-alt"></i><span>Transactions</span></a>
      <a href="<?= APP_BASE ?>/app/reports/index_oracle.php" class="nav-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
      <a href="<?= APP_BASE ?>/public/sync.php" class="nav-item"><i class="fas fa-sync-alt"></i><span>Sync</span></a>
    </nav>
    <div class="sidebar-footer">
      <a href="<?= APP_BASE ?>/public/logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
    </div>
  </aside>

  <main class="main-content">
    <div class="top-bar">
      <div class="page-title"><h1>Transactions</h1><p>Track and manage your financial transactions</p></div>
      <div class="top-actions">
        <button class="btn-icon" title="Refresh" onclick="location.reload()"><i class="fas fa-sync-alt"></i></button>
        <button class="btn-icon" title="Export"><i class="fas fa-download"></i></button>
        <a href="<?= APP_BASE ?>/app/transactions/create.php" class="btn-primary"><i class="fas fa-plus"></i><span>New Transaction</span></a>
      </div>
    </div>

    <?php $incomeFmt = number_format($income,2); $expenseFmt = number_format($expense,2); $netFmt = number_format($net,2); ?>

    <!-- Overview (now powered by Oracle procs/functions when available) -->
    <div class="financial-overview">
      <div class="overview-card income-card">
        <div class="overview-icon"><i class="fas fa-arrow-down"></i></div>
        <div class="overview-content">
          <span class="overview-label">Total Income</span>
          <span class="overview-amount"><?= $incomeFmt ?></span>
          <div class="overview-bar"><div class="bar-fill income-fill" style="width: <?= $income>0? '100':'0' ?>%"></div></div>
        </div>
      </div>
      <div class="overview-card expense-card">
        <div class="overview-icon"><i class="fas fa-arrow-up"></i></div>
        <div class="overview-content">
          <span class="overview-label">Total Expense</span>
          <span class="overview-amount"><?= $expenseFmt ?></span>
          <div class="overview-bar"><div class="bar-fill expense-fill" style="width: <?= $expense>0? '100':'0' ?>%"></div></div>
        </div>
      </div>
      <div class="overview-card net-card">
        <div class="overview-icon"><i class="fas fa-balance-scale"></i></div>
        <div class="overview-content">
          <span class="overview-label">Net Balance</span>
          <span class="overview-amount <?= $net>=0?'positive':'negative' ?>"><?= $netFmt ?></span>
          <span class="overview-subtitle"><?= $net>=0?'Surplus':'Deficit' ?></span>
        </div>
      </div>
    </div>

    <!-- Quick Stats -->
    <?php
      $total_transactions = (int)$total_transactions;
      $synced = (int)$synced; $pending = (int)$pending;
    ?>
    <div class="stats-grid">
      <div class="stat-box stat-primary">
        <div class="stat-icon"><i class="fas fa-receipt"></i></div>
        <div class="stat-details">
          <span class="stat-label">Total Transactions</span>
          <span class="stat-value"><?= $total_transactions ?></span>
          <span class="stat-change"><i class="fas fa-list"></i> Showing latest</span>
        </div>
      </div>
      <div class="stat-box stat-success">
        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        <div class="stat-details">
          <span class="stat-label"><?= $use_oracle ? 'Cloud' : 'Synced' ?></span>
          <span class="stat-value"><?= $synced ?></span>
          <span class="stat-change <?= $use_oracle ? 'positive':'' ?>"><i class="fas fa-cloud"></i> <?= $use_oracle ? 'Oracle' : 'Local OK' ?></span>
        </div>
      </div>
      <div class="stat-box stat-warning">
        <div class="stat-icon"><i class="fas fa-clock"></i></div>
        <div class="stat-details">
          <span class="stat-label">Pending Sync</span>
          <span class="stat-value"><?= $pending ?></span>
          <span class="stat-change"><i class="fas fa-hourglass-half"></i> Awaiting sync</span>
        </div>
      </div>
      <div class="stat-box stat-info">
        <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
        <div class="stat-details">
          <span class="stat-label">Period</span>
          <span class="stat-value"><?= $from && $to ? date('M d', strtotime($from)).' - '.date('M d', strtotime($to)) : 'All Time' ?></span>
          <span class="stat-change"><i class="fas fa-filter"></i> Date range</span>
        </div>
      </div>
    </div>

    <!-- Charts -->
    <?php if ($total_transactions > 0 && !empty($monthly_data_sorted)): ?>
    <div class="charts-section">
      <div class="chart-container chart-large">
        <div class="chart-header"><div><h3>Income vs Expense Trend</h3><p>Monthly comparison</p></div></div>
        <div class="chart-body"><canvas id="trendChart"></canvas></div>
      </div>
      <div class="chart-container">
        <div class="chart-header"><div><h3>Transaction Types</h3><p>Income vs Expense</p></div></div>
        <div class="chart-body"><canvas id="typeChart"></canvas></div>
      </div>
    </div>

    <!-- Recent -->
    <div class="recent-section">
      <div class="section-header">
        <h3>Recent Transactions</h3>
        <p>Latest 5 transactions</p>
      </div>
      <div class="recent-list">
        <?php foreach($recent as $txn): ?>
        <div class="recent-item <?= strtolower($txn['txn_type']) ?>">
          <div class="recent-icon"><i class="fas fa-<?= strtoupper($txn['txn_type'])==='INCOME' ? 'arrow-down' : 'arrow-up' ?>"></i></div>
          <div class="recent-content">
            <span class="recent-category"><?= h($txn['category_name']) ?></span>
            <span class="recent-account"><?= h($txn['account_name']) ?></span>
          </div>
          <div class="recent-right">
            <span class="recent-amount"><?= number_format((float)$txn['amount'], 2) ?></span>
            <span class="recent-date"><?= date('M d, Y', strtotime($txn['txn_date'])) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="filter-section">
      <div class="filter-header">
        <h3><i class="fas fa-filter"></i> Advanced Filters</h3>
        <button class="btn-reset" onclick="location.href='<?= APP_BASE ?>/app/transactions/index.php'"><i class="fas fa-redo"></i> Reset All</button>
      </div>
      <form method="get" class="filter-form">
        <div class="filter-grid">
          <div class="filter-group">
            <label><i class="fas fa-calendar-day"></i> From Date</label>
            <input type="date" name="from" value="<?= h($from) ?>">
          </div>
          <div class="filter-group">
            <label><i class="fas fa-calendar-day"></i> To Date</label>
            <input type="date" name="to" value="<?= h($to) ?>">
          </div>
          <div class="filter-group">
            <label><i class="fas fa-tag"></i> Type</label>
            <select name="type">
              <option value="">All Types</option>
              <option value="INCOME"  <?= $typ==='INCOME'?'selected':'' ?>>Income</option>
              <option value="EXPENSE" <?= $typ==='EXPENSE'?'selected':'' ?>>Expense</option>
            </select>
          </div>
          <div class="filter-group">
            <label><i class="fas fa-wallet"></i> Account</label>
            <select name="account">
              <option value="">All Accounts</option>
              <?php foreach ($accounts as $a): ?>
                <option value="<?= (int)$a['local_account_id'] ?>" <?= $acc==$a['local_account_id']?'selected':'' ?>>
                  <?= h($a['account_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-group">
            <label><i class="fas fa-tags"></i> Category</label>
            <select name="category">
              <option value="">All Categories</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['local_category_id'] ?>" <?= $cat==$c['local_category_id']?'selected':'' ?>>
                  <?= h($c['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-actions">
            <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Apply Filters</button>
          </div>
        </div>
      </form>
    </div>

    <!-- Table -->
    <div class="table-section">
      <div class="section-header">
        <div>
          <h2>All Transactions</h2>
          <p>Showing <?= count($rows) ?> transactions (limited to 200)</p>
        </div>
        <div class="search-container">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search transactions...">
        </div>
      </div>

      <?php if (empty($rows)): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-receipt"></i></div>
        <h3>No Transactions Found</h3>
        <p>Start by adding your first transaction</p>
        <a href="<?= APP_BASE ?>/app/transactions/create.php" class="btn-primary"><i class="fas fa-plus"></i><span>Add First Transaction</span></a>
      </div>
      <?php else: ?>
      <div class="transactions-table">
        <table>
          <thead>
            <tr>
              <th><i class="fas fa-calendar"></i> Date</th>
              <th><i class="fas fa-tag"></i> Type</th>
              <th><i class="fas fa-dollar-sign"></i> Amount</th>
              <th><i class="fas fa-wallet"></i> Account</th>
              <th><i class="fas fa-tags"></i> Category</th>
              <th><i class="fas fa-sticky-note"></i> Note</th>
              <th><i class="fas fa-sync"></i> Status</th>
              <th><i class="fas fa-cog"></i> Actions</th>
            </tr>
          </thead>
          <tbody id="transactionsBody">
            <?php foreach($rows as $r): ?>
            <tr data-search="<?= h(strtolower(($r['account_name'] ?? '').' '.($r['category_name'] ?? '').' '.($r['note'] ?? '').' '.($r['txn_type'] ?? ''))) ?>">
              <td><div class="date-cell"><i class="fas fa-calendar-day"></i><?= date('M d, Y', strtotime($r['txn_date'])) ?></div></td>
              <td><span class="badge badge-<?= strtolower($r['txn_type']) ?>"><i class="fas fa-<?= strtoupper($r['txn_type'])==='INCOME'?'arrow-down':'arrow-up' ?>"></i><?= h($r['txn_type']) ?></span></td>
              <td><span class="amount-cell <?= strtolower($r['txn_type']) ?>"><?= number_format((float)$r['amount'], 2) ?></span></td>
              <td><div class="account-cell"><i class="fas fa-wallet"></i><?= h($r['account_name']) ?></div></td>
              <td><div class="category-cell"><i class="fas fa-tag"></i><?= h($r['category_name']) ?></div></td>
              <td class="note-cell"><?= h((string)($r['note'] ?? '')) ?: '-' ?></td>
              <td>
                <span class="sync-badge <?= strtolower($r['sync_status'] ?? 'synced') ?>">
                  <i class="fas fa-<?= ($r['sync_status'] ?? 'SYNCED')==='SYNCED' ? 'check-circle' : 'clock' ?>"></i>
                  <?= h($r['sync_status'] ?? 'SYNCED') ?>
                </span>
              </td>
              <td>
                <div class="action-buttons">
                  <?php if ($use_oracle): ?>
                    <span class="muted">Cloud</span>
                  <?php else: ?>
                    <a href="<?= APP_BASE ?>/app/transactions/edit.php?id=<?= (int)$r['local_txn_id'] ?>" class="btn-icon-small" title="Edit"><i class="fas fa-edit"></i></a>
                    <a href="<?= APP_BASE ?>/app/transactions/delete.php?id=<?= (int)$r['local_txn_id'] ?>" class="btn-icon-small delete" title="Delete" onclick="return confirm('Delete this transaction?')"><i class="fas fa-trash"></i></a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<script>
// Search
document.getElementById('searchInput')?.addEventListener('input', function(e) {
  const search = (e.target.value || '').toLowerCase();
  document.querySelectorAll('#transactionsBody tr').forEach(row => {
    const txt = row.dataset.search || '';
    row.style.display = txt.includes(search) ? '' : 'none';
  });
});

<?php if ($total_transactions > 0 && !empty($monthly_data_sorted)): ?>
// Charts
const chartCfg = {
  responsive: true, maintainAspectRatio: false,
  plugins: {
    legend: { position: 'bottom', labels: { padding: 15, font: { size: 12, weight: '600' } } },
    tooltip:{ backgroundColor:'rgba(15,23,42,.95)', padding:12, borderRadius:8 }
  }
};
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_keys($monthly_data_sorted)) ?>,
    datasets: [
      { label:'Income',  data: <?= json_encode(array_column($monthly_data_sorted,'income')) ?>,  borderColor:'#10b981', backgroundColor:'rgba(16,185,129,.1)', tension:.4, fill:true, borderWidth:3, pointRadius:3 },
      { label:'Expense', data: <?= json_encode(array_column($monthly_data_sorted,'expense')) ?>, borderColor:'#ef4444', backgroundColor:'rgba(239,68,68,.1)', tension:.4, fill:true, borderWidth:3, pointRadius:3 }
    ]
  },
  options: { ...chartCfg, scales: { y:{beginAtZero:true, grid:{color:'rgba(0,0,0,.05)'}}, x:{grid:{display:false}} } }
});
new Chart(document.getElementById('typeChart'), {
  type:'doughnut',
  data:{ labels:['Income','Expense'], datasets:[{ data:[<?= $income ?>, <?= $expense ?>], backgroundColor:['#10b981','#ef4444'], borderWidth:0, borderRadius:8, spacing:4 }]},
  options:{ ...chartCfg, cutout:'65%' }
});
<?php endif; ?>
</script>
</body>
</html>
