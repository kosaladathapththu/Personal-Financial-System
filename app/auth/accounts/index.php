<?php
require __DIR__ . '/../../../config/env.php';
require __DIR__ . '/../../../db/sqlite.php';
require __DIR__ . '/../../../db/oracle.php';
require __DIR__ . '/../common/auth_guard.php';

function h($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }
function arr_keys_lower(array $r): array {
    $o = [];
    foreach ($r as $k=>$v) $o[strtolower((string)$k)] = $v;
    return $o;
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);

// ─────────────────────────────────────────────────────────────
// 1️⃣ Resolve mapping (local → server user id)
// ─────────────────────────────────────────────────────────────
$mapStmt = $pdo->prepare("SELECT server_user_id FROM USERS_LOCAL WHERE local_user_id = ?");
$mapStmt->execute([$uid]);
$serverUid = (int)($mapStmt->fetchColumn() ?: 0);

// ─────────────────────────────────────────────────────────────
// 2️⃣ Try Oracle connection
// ─────────────────────────────────────────────────────────────
$oconn = @oracle_conn();
$use_oracle = false;
if ($oconn && $serverUid > 0) {
    $chk = @oci_parse($oconn, "SELECT 1 FROM DUAL");
    if ($chk && @oci_execute($chk)) $use_oracle = true;
}

// ─────────────────────────────────────────────────────────────
// 3️⃣ Fetch account data
// ─────────────────────────────────────────────────────────────
$rows = [];

if ($use_oracle) {
    // Oracle-first: Accounts + live balance calc
    $sql = "
        SELECT
            a.server_account_id AS local_account_id,
            a.account_name,
            a.account_type,
            NVL(a.currency_code, 'LKR') AS currency_code,
            NVL(a.is_active,1) AS is_active,
            a.created_at,
            a.updated_at,
            NVL(a.opening_balance,0)
              + NVL(SUM(CASE
                  WHEN UPPER(t.txn_type)='INCOME'  THEN t.amount
                  WHEN UPPER(t.txn_type)='EXPENSE' THEN -t.amount
                  ELSE 0 END),0) AS current_balance
        FROM ACCOUNTS_CLOUD a
        LEFT JOIN TRANSACTIONS_CLOUD t
          ON t.account_server_id = a.server_account_id
         AND t.user_server_id = a.user_server_id
        WHERE a.user_server_id = :P_UID
        GROUP BY
          a.server_account_id,
          a.account_name,
          a.account_type,
          a.currency_code,
          a.is_active,
          a.created_at,
          a.updated_at,
          a.opening_balance
        ORDER BY NVL(a.is_active,1) DESC, a.created_at DESC
    ";
    $st = oci_parse($oconn, $sql);
    oci_bind_by_name($st, ':P_UID', $serverUid, -1, SQLT_INT);
    oci_execute($st);
    while ($r = oci_fetch_assoc($st)) $rows[] = arr_keys_lower($r);

} else {
    // SQLite fallback
    $stmt = $pdo->prepare("
      SELECT local_account_id, account_name, account_type, currency_code,
             current_balance, is_active, created_at, updated_at
      FROM V_ACCOUNT_BALANCES
      WHERE user_local_id = ?
      ORDER BY is_active DESC, created_at DESC
    ");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ─────────────────────────────────────────────────────────────
// 4️⃣ Calculate statistics
// ─────────────────────────────────────────────────────────────
$total_accounts   = count($rows);
$active_accounts  = count(array_filter($rows, fn($r) => (int)$r['is_active']));
$inactive_accounts = $total_accounts - $active_accounts;
$total_balance    = array_sum(array_map(fn($r) => (float)$r['current_balance'], $rows));

// Group by account type
$type_data = [];
foreach ($rows as $r) {
    $type = strtoupper($r['account_type']);
    if (!isset($type_data[$type])) $type_data[$type] = ['count' => 0, 'balance' => 0];
    $type_data[$type]['count']++;
    $type_data[$type]['balance'] += (float)$r['current_balance'];
}

// Group by currency
$currency_data = [];
foreach ($rows as $r) {
    $curr = strtoupper($r['currency_code']);
    if (!isset($currency_data[$curr])) $currency_data[$curr] = ['count' => 0, 'balance' => 0];
    $currency_data[$curr]['count']++;
    $currency_data[$curr]['balance'] += (float)$r['current_balance'];
}

$recent = array_slice($rows, 0, 5);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Accounts Management - PFMS</title>
  
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
  <link rel="stylesheet" href="index.css">
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
      <a href="<?= APP_BASE ?>/public/dashboard.php" class="nav-item">
        <i class="fas fa-home"></i>
        <span>Dashboard</span>
      </a>
      <a href="<?= APP_BASE ?>/app/auth/accounts/index.php" class="nav-item active">
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
      <a href="<?= APP_BASE ?>/public/sync.php" class="nav-item active">
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
    
    <div class="top-bar">
      <div class="page-title">
        <h1>Accounts Overview</h1>
        <p>Manage and monitor your financial accounts</p>
      </div>
      <div class="top-actions">
        <a href="<?= APP_BASE ?>/public/sync.php" class="btn-icon" title="Sync Now">
          <i class="fas fa-sync-alt"></i>
        </a>
        <a href="<?= APP_BASE ?>/app/auth/accounts/create.php" class="btn-primary">
          <i class="fas fa-plus"></i>
          <span>New Account</span>
        </a>
      </div>
    </div>

    <div class="stats-grid">
      <div class="stat-box stat-primary">
        <div class="stat-icon"><i class="fas fa-wallet"></i></div>
        <div class="stat-details">
          <span class="stat-label">Total Accounts</span>
          <span class="stat-value"><?= $total_accounts ?></span>
        </div>
      </div>
      <div class="stat-box stat-success">
        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        <div class="stat-details">
          <span class="stat-label">Active Accounts</span>
          <span class="stat-value"><?= $active_accounts ?></span>
        </div>
      </div>
      <div class="stat-box stat-warning">
        <div class="stat-icon"><i class="fas fa-coins"></i></div>
        <div class="stat-details">
          <span class="stat-label">Total Balance</span>
          <span class="stat-value"><?= number_format($total_balance, 2) ?></span>
        </div>
      </div>
      <div class="stat-box stat-info">
        <div class="stat-icon"><i class="fas fa-globe"></i></div>
        <div class="stat-details">
          <span class="stat-label">Currencies</span>
          <span class="stat-value"><?= count($currency_data) ?></span>
        </div>
      </div>
    </div>

    <div class="table-section">
      <div class="section-header">
        <div>
          <h2>All Accounts</h2>
          <p>Complete list of your financial accounts</p>
        </div>
        <div class="search-container">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search by name, type, currency...">
        </div>
      </div>

      <?php if (empty($rows)): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-wallet"></i></div>
        <h3>No Accounts Found</h3>
        <p>Create your first account to start tracking</p>
        <a href="<?= APP_BASE ?>/app/auth/accounts/create.php" class="btn-primary">
          <i class="fas fa-plus"></i>
          <span>Create Account</span>
        </a>
      </div>
      <?php else: ?>
      <div class="accounts-grid">
        <?php foreach($rows as $r): ?>
        <div class="account-card" 
             data-account-name="<?= h($r['account_name']) ?>" 
             data-account-type="<?= h($r['account_type']) ?>" 
             data-currency="<?= h($r['currency_code']) ?>">
          <div class="account-header">
            <div class="account-icon-wrapper"><i class="fas fa-wallet"></i></div>
            <div class="account-status">
              <?php if ($r['is_active']): ?>
                <span class="badge badge-success"><i class="fas fa-circle"></i> Active</span>
              <?php else: ?>
                <span class="badge badge-inactive"><i class="fas fa-circle"></i> Inactive</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="account-body">
            <h3><?= h($r['account_name']) ?></h3>
            <div class="account-meta">
              <span><i class="fas fa-tag"></i> <?= h($r['account_type']) ?></span>
              <span><i class="fas fa-dollar-sign"></i> <?= h($r['currency_code']) ?></span>
            </div>
            <div class="account-balance">
              <span class="balance-label">Current Balance</span>
              <span class="balance-amount"><?= number_format((float)$r['current_balance'], 2) ?></span>
            </div>
          </div>
          <div class="account-footer">
            <a href="<?= APP_BASE ?>/app/auth/accounts/edit.php?id=<?= (int)$r['local_account_id'] ?>" class="action-btn">
              <i class="fas fa-edit"></i> Edit
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </main>
</div>

<script>
document.getElementById('searchInput')?.addEventListener('input', e => {
  const s = e.target.value.toLowerCase();
  document.querySelectorAll('.account-card').forEach(c => {
    const name = c.dataset.accountName.toLowerCase();
    const type = c.dataset.accountType.toLowerCase();
    const cur  = c.dataset.currency.toLowerCase();
    c.style.display = (name.includes(s)||type.includes(s)||cur.includes(s)) ? '' : 'none';
  });
});
</script>

</body>
</html>
