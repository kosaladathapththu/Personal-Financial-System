<?php
// public/dashboard.php
declare(strict_types=1);

require __DIR__ . '/../config/env.php';
require __DIR__ . '/../db/sqlite.php';
require __DIR__ . '/../db/oracle.php'; // oracle_conn(): ?resource

// ---- include the guard (path-safe) ----
$guard1 = __DIR__ . '/../app/common/auth_guard.php';
$guard2 = __DIR__ . '/../app/auth/common/auth_guard.php';
$guard3 = __DIR__ . '/../app/auth/auth_guard.php';

if (file_exists($guard1)) {
    require $guard1;
} elseif (file_exists($guard2)) {
    require $guard2;
} elseif (file_exists($guard3)) {
    require $guard3;
} else {
    session_start();
    if (!isset($_SESSION['uid'])) {
        header('Location: /pfms/app/auth/login.php');
        exit;
    }
}

function h($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }
function arr_keys_lower(array $row): array {
    $o = [];
    foreach ($row as $k=>$v) { $o[strtolower((string)$k)] = $v; }
    return $o;
}

// ---- DB and user ----
$pdo = sqlite();
$uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;

// ---- Quick counts (stay on SQLite; these are local objects) ----
$acc = $pdo->prepare("SELECT COUNT(*) FROM ACCOUNTS_LOCAL WHERE user_local_id=?");
$acc->execute([$uid]);
$acc_count = (int)$acc->fetchColumn();

$cat = $pdo->prepare("SELECT COUNT(*) FROM CATEGORIES_LOCAL WHERE user_local_id=?");
try { 
    $cat->execute([$uid]); 
    $cat_count = (int)$cat->fetchColumn(); 
} catch (Exception $e) { 
    $cat_count = 0; 
}

$txn = $pdo->prepare("SELECT COUNT(*) FROM TRANSACTIONS_LOCAL WHERE user_local_id=?");
try { 
    $txn->execute([$uid]); 
    $txn_count = (int)$txn->fetchColumn(); 
} catch (Exception $e) { 
    $txn_count = 0; 
}

// ─────────────────────────────────────────────────────────────
// ORACLE-FIRST ANALYSIS (summary, recent, monthly, categories, balances)
// Falls back to SQLite if Oracle not available or not mapped
// ─────────────────────────────────────────────────────────────

// 1) resolve server_user_id from USERS_LOCAL
$mapStmt = $pdo->prepare("SELECT server_user_id, full_name FROM USERS_LOCAL WHERE local_user_id = ?");
$mapStmt->execute([$uid]);
$mapRow = $mapStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$serverUid = (int)($mapRow['server_user_id'] ?? 0);
$fullName  = (string)($mapRow['full_name'] ?? 'Me');

// 2) try oracle
$oconn = null;
$use_oracle = false;
if ($serverUid > 0) {
    $oconn = @oracle_conn();
    if ($oconn) {
        $probe = @oci_parse($oconn, "SELECT 1 FROM DUAL");
        if ($probe && @oci_execute($probe)) {
            $use_oracle = true;
        }
    }
}

// -------------------- defaults --------------------
$total_income   = 0.0;
$total_expense  = 0.0;
$net_balance    = 0.0; // txn-only
$total_balance  = 0.0; // opening + income - expense across all active accounts
$recent_transactions = [];
$monthly_data = [];
$top_categories = [];
$accounts = [];

// -------------------- ORACLE path --------------------
if ($use_oracle) {
    // Summary (txn totals across all time)
    $sql = "
      SELECT
        NVL(SUM(CASE WHEN UPPER(txn_type)='INCOME'  THEN amount ELSE 0 END),0) AS total_income,
        NVL(SUM(CASE WHEN UPPER(txn_type)='EXPENSE' THEN amount ELSE 0 END),0) AS total_expense
      FROM TRANSACTIONS_CLOUD
      WHERE user_server_id = :P_UID
    ";
    $st = oci_parse($oconn, $sql);
    oci_bind_by_name($st, ':P_UID', $serverUid, -1, SQLT_INT);
    oci_execute($st);
    if ($row = oci_fetch_assoc($st)) {
        $row = arr_keys_lower($row);
        $total_income  = (float)$row['total_income'];
        $total_expense = (float)$row['total_expense'];
        $net_balance   = $total_income - $total_expense;
    }

    // Recent transactions (last 5)
    $sql = "
      SELECT
        t.txn_type,
        t.amount,
        TO_CHAR(t.txn_date, 'YYYY-MM-DD') AS txn_date,
        NVL(c.category_name,'Uncategorized') AS category_name,
        NVL(a.account_name,'Unknown')       AS account_name
      FROM TRANSACTIONS_CLOUD t
      LEFT JOIN CATEGORIES_CLOUD c ON c.server_category_id = t.category_server_id
      LEFT JOIN ACCOUNTS_CLOUD   a ON a.server_account_id  = t.account_server_id
      WHERE t.user_server_id = :P_UID
      ORDER BY t.txn_date DESC, t.server_txn_id DESC
      FETCH FIRST 5 ROWS ONLY
    ";
    $st = oci_parse($oconn, $sql);
    oci_bind_by_name($st, ':P_UID', $serverUid, -1, SQLT_INT);
    oci_execute($st);
    while ($r = oci_fetch_assoc($st)) { $recent_transactions[] = arr_keys_lower($r); }

    // Monthly (last 6 non-empty months)
    $sql = "
      SELECT
        TO_CHAR(t.txn_date,'YYYY-MM') AS month,
        NVL(SUM(CASE WHEN UPPER(t.txn_type)='INCOME'  THEN t.amount ELSE 0 END),0) AS income,
        NVL(SUM(CASE WHEN UPPER(t.txn_type)='EXPENSE' THEN t.amount ELSE 0 END),0) AS expense
      FROM TRANSACTIONS_CLOUD t
      WHERE t.user_server_id = :P_UID
      GROUP BY TO_CHAR(t.txn_date,'YYYY-MM')
      ORDER BY month DESC
      FETCH FIRST 6 ROWS ONLY
    ";
    $st = oci_parse($oconn, $sql);
    oci_bind_by_name($st, ':P_UID', $serverUid, -1, SQLT_INT);
    oci_execute($st);
    $tmp = [];
    while ($r = oci_fetch_assoc($st)) { $tmp[] = arr_keys_lower($r); }
    $monthly_data = array_reverse($tmp);

    // Category breakdown (top 5 by amount)
    $sql = "
      SELECT
        NVL(c.category_name,'Uncategorized') AS category_name,
        NVL(SUM(t.amount),0)                 AS total
      FROM TRANSACTIONS_CLOUD t
      LEFT JOIN CATEGORIES_CLOUD c ON c.server_category_id = t.category_server_id
      WHERE t.user_server_id = :P_UID
      GROUP BY NVL(c.category_name,'Uncategorized')
      ORDER BY total DESC
      FETCH FIRST 5 ROWS ONLY
    ";
    $st = oci_parse($oconn, $sql);
    oci_bind_by_name($st, ':P_UID', $serverUid, -1, SQLT_INT);
    oci_execute($st);
    while ($r = oci_fetch_assoc($st)) { $top_categories[] = arr_keys_lower($r); }

    // Account balances (top 5)
    $sql = "
      SELECT
        a.account_name,
        a.account_type,
        NVL(a.opening_balance,0)
          + NVL(SUM(CASE WHEN UPPER(t.txn_type)='INCOME'  THEN t.amount
                         WHEN UPPER(t.txn_type)='EXPENSE' THEN -t.amount
                         ELSE 0 END),0) AS current_balance
      FROM ACCOUNTS_CLOUD a
      LEFT JOIN TRANSACTIONS_CLOUD t
        ON t.account_server_id = a.server_account_id
       AND t.user_server_id    = a.user_server_id
      WHERE a.user_server_id = :P_UID
        AND NVL(a.is_active,1) = 1
      GROUP BY a.account_name, a.account_type, NVL(a.opening_balance,0)
      ORDER BY current_balance DESC
      FETCH FIRST 5 ROWS ONLY
    ";
    $st = oci_parse($oconn, $sql);
    oci_bind_by_name($st, ':P_UID', $serverUid, -1, SQLT_INT);
    oci_execute($st);
    while ($r = oci_fetch_assoc($st)) { $accounts[] = arr_keys_lower($r); }

    // TOTAL BALANCE across ALL accounts (Opening + Income - Expense)
    $sql = "
      SELECT SUM(opening_balance + NVL(inc,0) - NVL(exp,0)) AS total_balance
      FROM (
        SELECT
          a.server_account_id,
          NVL(a.opening_balance,0) AS opening_balance,
          SUM(CASE WHEN UPPER(t.txn_type)='INCOME'  THEN t.amount ELSE 0 END) AS inc,
          SUM(CASE WHEN UPPER(t.txn_type)='EXPENSE' THEN t.amount ELSE 0 END) AS exp
        FROM ACCOUNTS_CLOUD a
        LEFT JOIN TRANSACTIONS_CLOUD t
          ON t.account_server_id = a.server_account_id
         AND t.user_server_id    = a.user_server_id
        WHERE a.user_server_id = :P_UID
          AND NVL(a.is_active,1) = 1
        GROUP BY a.server_account_id, NVL(a.opening_balance,0)
      )
    ";
    $st = oci_parse($oconn, $sql);
    oci_bind_by_name($st, ':P_UID', $serverUid, -1, SQLT_INT);
    oci_execute($st);
    if ($r = oci_fetch_assoc($st)) {
      $total_balance = (float)$r['TOTAL_BALANCE'];
    }

} else {
    // -------------------- SQLITE fallback --------------------

    // Financial summary (txn totals)
    $summary = $pdo->prepare("
      SELECT 
        SUM(CASE WHEN UPPER(txn_type)='INCOME' THEN amount ELSE 0 END) as total_income,
        SUM(CASE WHEN UPPER(txn_type)='EXPENSE' THEN amount ELSE 0 END) as total_expense
      FROM TRANSACTIONS_LOCAL 
      WHERE user_local_id=?
    ");
    $summary->execute([$uid]);
    $fin = $summary->fetch(PDO::FETCH_ASSOC) ?: [];
    $total_income  = (float)($fin['total_income'] ?? 0);
    $total_expense = (float)($fin['total_expense'] ?? 0);
    $net_balance   = $total_income - $total_expense;

    // Recent transactions
    $recent_txn = $pdo->prepare("
      SELECT t.txn_type, t.amount, t.txn_date, c.category_name, a.account_name
      FROM TRANSACTIONS_LOCAL t
      LEFT JOIN CATEGORIES_LOCAL c ON t.category_local_id = c.local_category_id
      LEFT JOIN ACCOUNTS_LOCAL a ON t.account_local_id = a.local_account_id
      WHERE t.user_local_id = ?
      ORDER BY t.txn_date DESC, t.local_txn_id DESC
      LIMIT 5
    ");
    $recent_txn->execute([$uid]);
    $recent_transactions = $recent_txn->fetchAll(PDO::FETCH_ASSOC);

    // Monthly (last 6)
    $monthly = $pdo->prepare("
      SELECT 
        strftime('%Y-%m', txn_date) as month,
        SUM(CASE WHEN UPPER(txn_type)='INCOME' THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN UPPER(txn_type)='EXPENSE' THEN amount ELSE 0 END) as expense
      FROM TRANSACTIONS_LOCAL
      WHERE user_local_id = ?
      GROUP BY strftime('%Y-%m', txn_date)
      ORDER BY month DESC
      LIMIT 6
    ");
    $monthly->execute([$uid]);
    $monthly_data = array_reverse($monthly->fetchAll(PDO::FETCH_ASSOC));

    // Category breakdown
    $cat_breakdown = $pdo->prepare("
      SELECT c.category_name, SUM(t.amount) as total
      FROM TRANSACTIONS_LOCAL t
      JOIN CATEGORIES_LOCAL c ON t.category_local_id = c.local_category_id
      WHERE t.user_local_id = ?
      GROUP BY c.local_category_id
      ORDER BY total DESC
      LIMIT 5
    ");
    $cat_breakdown->execute([$uid]);
    $top_categories = $cat_breakdown->fetchAll(PDO::FETCH_ASSOC);

    // Account balances (top 5) — using local aggregate
    $acc_balances = $pdo->prepare("
      SELECT a.account_name, a.account_type,
        IFNULL(a.opening_balance,0)
        + IFNULL((
            SELECT SUM(CASE WHEN UPPER(t2.txn_type)='INCOME' THEN t2.amount ELSE 0 END)
            FROM TRANSACTIONS_LOCAL t2
            WHERE t2.account_local_id = a.local_account_id AND t2.user_local_id = a.user_local_id
          ),0)
        - IFNULL((
            SELECT SUM(CASE WHEN UPPER(t3.txn_type)='EXPENSE' THEN t3.amount ELSE 0 END)
            FROM TRANSACTIONS_LOCAL t3
            WHERE t3.account_local_id = a.local_account_id AND t3.user_local_id = a.user_local_id
          ),0) AS current_balance
      FROM ACCOUNTS_LOCAL a
      WHERE a.user_local_id = ? AND a.is_active = 1
      ORDER BY current_balance DESC
      LIMIT 5
    ");
    $acc_balances->execute([$uid]);
    $accounts = $acc_balances->fetchAll(PDO::FETCH_ASSOC);

    // TOTAL BALANCE across ALL accounts (Opening + Income - Expense)
    $tb = $pdo->prepare("
      SELECT SUM(opening_balance + IFNULL(inc,0) - IFNULL(exp,0)) AS total_balance
      FROM (
        SELECT
          a.local_account_id,
          IFNULL(a.opening_balance,0) AS opening_balance,
          (SELECT SUM(CASE WHEN UPPER(t.txn_type)='INCOME'  THEN t.amount ELSE 0 END)
           FROM TRANSACTIONS_LOCAL t
           WHERE t.account_local_id = a.local_account_id AND t.user_local_id = a.user_local_id) AS inc,
          (SELECT SUM(CASE WHEN UPPER(t.txn_type)='EXPENSE' THEN t.amount ELSE 0 END)
           FROM TRANSACTIONS_LOCAL t
           WHERE t.account_local_id = a.local_account_id AND t.user_local_id = a.user_local_id) AS exp
        FROM ACCOUNTS_LOCAL a
        WHERE a.user_local_id = ? AND a.is_active = 1
      )
    ");
    $tb->execute([$uid]);
    $total_balance = (float)($tb->fetchColumn() ?? 0);
}

// DB badge & name
$db_badge_class = $use_oracle ? 'oracle' : 'sqlite';
$db_badge_icon  = $use_oracle ? 'database' : 'laptop';
$db_badge_text  = $use_oracle ? 'Oracle' : 'SQLite (Fallback)';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PFMS Dashboard</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
  <link rel="stylesheet" href="<?= APP_BASE ?>/public/dashboard.css">
  <style>
    .badge-db{display:inline-flex;gap:8px;padding:6px 12px;border-radius:999px;font-weight:700}
    .oracle{background:rgba(16,185,129,.12);color:#065f46}
    .sqlite{background:rgba(245,158,11,.12);color:#7c2d12}
    .welcome-header{display:flex;justify-content:space-between;align-items:center;margin:0 0 16px 0}
    .welcome-header .who{display:flex;gap:10px;align-items:center;color:#374151}
    .btn-sync{display:inline-flex;gap:10px;align-items:center;background:linear-gradient(135deg,#10b981,#059669);color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:700}
  </style>
</head>
<body>

<div class="app-container">
  
  <!-- Sidebar Navigation -->
  <aside class="sidebar">
    <div class="logo">
      <i class="fas fa-chart-line"></i>
      <span>PFMS</span>
    </div>
    
    <nav class="nav-menu">
      <a href="<?= APP_BASE ?>/public/dashboard.php" class="nav-item active">
        <i class="fas fa-home"></i>
        <span>Dashboard</span>
      </a>
      <a href="<?= APP_BASE ?>/app/auth/accounts/index.php" class="nav-item">
        <i class="fas fa-wallet"></i>
        <span>Accounts</span>
      </a>
      <a href="<?= APP_BASE ?>/app/categories/index.php" class="nav-item">
        <i class="fas fa-tags"></i>
        <span>Categories</span>
      </a>
      <a href="<?= APP_BASE ?>/app/transactions/index.php" class="nav-item">
        <i class="fas fa-exchange-alt"></i>
        <span>Transactions</span>
      </a>
      <a href="<?= APP_BASE ?>/app/reports/index_oracle.php" class="nav-item">
        <i class="fas fa-chart-bar"></i>
        <span>Reports</span>
      </a>
      <a href="<?= APP_BASE ?>/public/sync.php" class="nav-item">
        <i class="fas fa-sync-alt"></i>
        <span>Sync</span>
      </a>
    </nav>
    
    <div class="sidebar-footer">
      <a href="<?= APP_BASE ?>/public/logout.php" class="logout-link">
        <i class="fas fa-sign-out-alt"></i>
        <span>Logout</span>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="main-content">
    
    <!-- Welcome Header -->
    <div class="welcome-header">
      <div class="who">
        <span class="badge-db <?= $db_badge_class ?>">
          <i class="fas fa-<?= $db_badge_icon ?>"></i> <?= $db_badge_text ?>
        </span>
        <div>
          <h1 style="margin:0;font-size:1.5rem;font-weight:800;color:#111827">Welcome back, <?= h($fullName) ?>!</h1>
          <div style="color:#6b7280">Here’s your latest financial overview.</div>
        </div>
      </div>
      <a href="<?= APP_BASE ?>/public/sync.php" class="btn-sync">
        <i class="fas fa-sync-alt"></i>
        <span>Sync Now</span>
      </a>
    </div>

    <!-- Financial Overview Cards -->
    <div class="financial-overview">
      <div class="overview-card income-card">
        <div class="overview-icon">
          <i class="fas fa-arrow-down"></i>
        </div>
        <div class="overview-content">
          <span class="overview-label">Total Income</span>
          <span class="overview-amount"><?= number_format($total_income, 2) ?></span>
          <div class="overview-trend positive">
            <i class="fas fa-arrow-up"></i>
            <span>All time earnings</span>
          </div>
        </div>
      </div>

      <div class="overview-card expense-card">
        <div class="overview-icon">
          <i class="fas fa-arrow-up"></i>
        </div>
        <div class="overview-content">
          <span class="overview-label">Total Expense</span>
          <span class="overview-amount"><?= number_format($total_expense, 2) ?></span>
          <div class="overview-trend">
            <i class="fas fa-minus"></i>
            <span>All time spending</span>
          </div>
        </div>
      </div>

      <div class="overview-card balance-card">
        <div class="overview-icon">
          <i class="fas fa-balance-scale"></i>
        </div>
        <div class="overview-content">
          <span class="overview-label">Total Balance</span>
          <span class="overview-amount <?= $total_balance >= 0 ? 'positive' : 'negative' ?>">
            <?= number_format($total_balance, 2) ?>
          </span>
          <div class="overview-trend <?= $total_balance >= 0 ? 'positive' : '' ?>">
            <i class="fas fa-<?= $total_balance >= 0 ? 'check' : 'exclamation' ?>-circle"></i>
            <span><?= $total_balance >= 0 ? 'Healthy' : 'Deficit' ?></span>
          </div>
          <div style="margin-top:6px;font-size:12px;color:#6b7280">
            Txn Net (Income − Expense): <?= number_format($net_balance, 2) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Quick Stats -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon blue">
          <i class="fas fa-wallet"></i>
        </div>
        <div class="stat-info">
          <span class="stat-value"><?= $acc_count ?></span>
          <span class="stat-label">Accounts</span>
        </div>
        <a href="<?= APP_BASE ?>/app/auth/accounts/index.php" class="stat-link">
          <i class="fas fa-arrow-right"></i>
        </a>
      </div>

      <div class="stat-card">
        <div class="stat-icon purple">
          <i class="fas fa-tags"></i>
        </div>
        <div class="stat-info">
          <span class="stat-value"><?= $cat_count ?></span>
          <span class="stat-label">Categories</span>
        </div>
        <a href="<?= APP_BASE ?>/app/categories/index.php" class="stat-link">
          <i class="fas fa-arrow-right"></i>
        </a>
      </div>

      <div class="stat-card">
        <div class="stat-icon green">
          <i class="fas fa-receipt"></i>
        </div>
        <div class="stat-info">
          <span class="stat-value"><?= $txn_count ?></span>
          <span class="stat-label">Transactions</span>
        </div>
        <a href="<?= APP_BASE ?>/app/transactions/index.php" class="stat-link">
          <i class="fas fa-arrow-right"></i>
        </a>
      </div>

      <div class="stat-card">
        <div class="stat-icon orange">
          <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-info">
          <span class="stat-value"><?= count($monthly_data) ?></span>
          <span class="stat-label">Active Months</span>
        </div>
        <a href="<?= APP_BASE ?>/app/reports/index_oracle.php" class="stat-link">
          <i class="fas fa-arrow-right"></i>
        </a>
      </div>
    </div>

    <!-- Charts and Data Section -->
    <div class="dashboard-grid">
      
      <!-- Income vs Expense Chart -->
      <?php if (!empty($monthly_data)): ?>
      <div class="dashboard-card chart-card-large">
        <div class="card-header">
          <div>
            <h3>Income vs Expense Trend</h3>
            <p>Monthly comparison over last 6 months</p>
          </div>
          <button class="btn-card-action">
            <i class="fas fa-ellipsis-v"></i>
          </button>
        </div>
        <div class="card-body">
          <canvas id="incomeExpenseChart"></canvas>
        </div>
      </div>
      <?php endif; ?>

      <!-- Category Breakdown -->
      <?php if (!empty($top_categories)): ?>
      <div class="dashboard-card">
        <div class="card-header">
          <div>
            <h3>Top Categories</h3>
            <p>Your spending by category</p>
          </div>
        </div>
        <div class="card-body">
          <canvas id="categoryChart"></canvas>
        </div>
      </div>
      <?php endif; ?>

      <!-- Recent Transactions -->
      <?php if (!empty($recent_transactions)): ?>
      <div class="dashboard-card">
        <div class="card-header">
          <div>
            <h3>Recent Transactions</h3>
            <p>Latest 5 transactions</p>
          </div>
          <a href="<?= APP_BASE ?>/app/transactions/index.php" class="btn-view-all">
            View All <i class="fas fa-arrow-right"></i>
          </a>
        </div>
        <div class="card-body">
          <div class="transaction-list">
            <?php foreach($recent_transactions as $t): ?>
            <div class="transaction-item <?= strtolower((string)$t['txn_type']) ?>">
              <div class="txn-icon">
                <i class="fas fa-<?= strtoupper((string)$t['txn_type']) === 'INCOME' ? 'arrow-down' : 'arrow-up' ?>"></i>
              </div>
              <div class="txn-details">
                <span class="txn-category"><?= h($t['category_name'] ?? 'Uncategorized') ?></span>
                <span class="txn-account"><?= h($t['account_name'] ?? 'Unknown') ?></span>
              </div>
              <div class="txn-right">
                <span class="txn-amount"><?= number_format((float)$t['amount'], 2) ?></span>
                <span class="txn-date"><?= date('M d', strtotime((string)$t['txn_date'])) ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Account Balances -->
      <?php if (!empty($accounts)): ?>
      <div class="dashboard-card">
        <div class="card-header">
          <div>
            <h3>Account Balances</h3>
            <p>Your top accounts</p>
          </div>
        </div>
        <div class="card-body">
          <div class="account-list">
            <?php foreach($accounts as $acc): ?>
            <div class="account-item">
              <div class="acc-icon">
                <?php
                  $icon = 'wallet';
                  $t = strtoupper((string)($acc['account_type'] ?? ''));
                  if ($t === 'BANK')   $icon = 'building-columns';
                  if ($t === 'CARD')   $icon = 'credit-card';
                  if ($t === 'CASH')   $icon = 'money-bill-wave';
                  if ($t === 'MOBILE') $icon = 'mobile-screen';
                ?>
                <i class="fas fa-<?= $icon ?>"></i>
              </div>
              <div class="acc-details">
                <span class="acc-name"><?= h($acc['account_name']) ?></span>
                <span class="acc-type"><?= h($acc['account_type'] ?? '') ?></span>
              </div>
              <span class="acc-balance"><?= number_format((float)$acc['current_balance'], 2) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Quick Actions -->
      <div class="dashboard-card quick-actions-card">
        <div class="card-header">
          <div>
            <h3>Quick Actions</h3>
            <p>Manage your finances</p>
          </div>
        </div>
        <div class="card-body">
          <div class="quick-actions">
            <a href="<?= APP_BASE ?>/app/transactions/create.php" class="action-btn action-primary">
              <i class="fas fa-plus-circle"></i>
              <span>Add Transaction</span>
            </a>
            <a href="<?= APP_BASE ?>/app/auth/accounts/create.php" class="action-btn action-blue">
              <i class="fas fa-wallet"></i>
              <span>New Account</span>
            </a>
            <a href="<?= APP_BASE ?>/app/categories/create.php" class="action-btn action-purple">
              <i class="fas fa-tag"></i>
              <span>New Category</span>
            </a>
            <a href="<?= APP_BASE ?>/app/reports/index_oracle.php" class="action-btn action-orange">
              <i class="fas fa-chart-bar"></i>
              <span>View Reports</span>
            </a>
          </div>
        </div>
      </div>

      <!-- Financial Health Score -->
      <div class="dashboard-card health-card">
        <div class="card-header">
          <div>
            <h3>Financial Health</h3>
            <p>Your financial status</p>
          </div>
        </div>
        <div class="card-body">
          <div class="health-score">
            <div class="score-circle">
              <svg width="120" height="120">
                <circle cx="60" cy="60" r="54" fill="none" stroke="#e5e7eb" stroke-width="8"/>
                <?php
                  $ratio = $total_income > 0 ? max(0, min(1, $net_balance / $total_income)) : 0;
                  $circ = 2 * M_PI * 54; // ~339.292
                  $offset = $circ * (1 - $ratio);
                ?>
                <circle cx="60" cy="60" r="54" fill="none" stroke="url(#gradient)" stroke-width="8" 
                        stroke-dasharray="<?= $circ ?>" stroke-dashoffset="<?= $offset ?>" 
                        stroke-linecap="round" transform="rotate(-90 60 60)"/>
                <defs>
                  <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#6366f1;stop-opacity:1" />
                    <stop offset="100%" style="stop-color:#8b5cf6;stop-opacity:1" />
                  </linearGradient>
                </defs>
              </svg>
              <div class="score-text">
                <span class="score-value"><?= $total_income > 0 ? round($ratio * 100) : 0 ?>%</span>
                <span class="score-label">Savings Rate</span>
              </div>
            </div>
            <div class="health-tips">
              <div class="tip-item">
                <i class="fas fa-check-circle"></i>
                <span><?= $net_balance >= 0 ? 'Great job!' : 'Needs improvement' ?></span>
              </div>
              <div class="tip-item">
                <i class="fas fa-lightbulb"></i>
                <span><?= $txn_count > 10 ? 'Active tracker' : 'Start tracking more' ?></span>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>

  </main>
</div>

<script>
<?php if (!empty($monthly_data)): ?>
// Income vs Expense Chart
const monthlyLabels = <?= json_encode(array_map(function($d) { 
  return date('M Y', strtotime(($d['month'] ?? '') . '-01')); 
}, $monthly_data)) ?>;
const incomeData = <?= json_encode(array_map(fn($d)=>(float)$d['income'], $monthly_data)) ?>;
const expenseData = <?= json_encode(array_map(fn($d)=>(float)$d['expense'], $monthly_data)) ?>;

new Chart(document.getElementById('incomeExpenseChart'), {
  type: 'line',
  data: {
    labels: monthlyLabels,
    datasets: [
      {
        label: 'Income',
        data: incomeData,
        borderColor: '#10b981',
        backgroundColor: 'rgba(16, 185, 129, 0.1)',
        tension: 0.4,
        fill: true,
        borderWidth: 3,
        pointRadius: 4,
        pointHoverRadius: 6
      },
      {
        label: 'Expense',
        data: expenseData,
        borderColor: '#ef4444',
        backgroundColor: 'rgba(239, 68, 68, 0.1)',
        tension: 0.4,
        fill: true,
        borderWidth: 3,
        pointRadius: 4,
        pointHoverRadius: 6
      }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'bottom',
        labels: { padding: 15, font: { size: 12, weight: '600' } }
      },
      tooltip: {
        backgroundColor: 'rgba(15, 23, 42, 0.95)',
        padding: 12,
        borderRadius: 8
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        grid: { color: 'rgba(0,0,0,0.05)' },
        ticks: { font: { size: 11 } }
      },
      x: {
        grid: { display: false },
        ticks: { font: { size: 11 } }
      }
    }
  }
});
<?php endif; ?>

<?php if (!empty($top_categories)): ?>
// Category Chart
const categoryLabels = <?= json_encode(array_map(fn($r)=>$r['category_name'], $top_categories)) ?>;
const categoryData = <?= json_encode(array_map(fn($r)=>(float)$r['total'], $top_categories)) ?>;
const categoryColors = ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'];

new Chart(document.getElementById('categoryChart'), {
  type: 'doughnut',
  data: {
    labels: categoryLabels,
    datasets: [{
      data: categoryData,
      backgroundColor: categoryColors,
      borderWidth: 0,
      borderRadius: 6,
      spacing: 3
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'bottom',
        labels: { padding: 12, font: { size: 11, weight: '600' } }
      }
    },
    cutout: '65%'
  }
});
<?php endif; ?>
</script>

</body>
</html>
