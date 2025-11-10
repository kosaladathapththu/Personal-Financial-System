<?php
// pfms/app/auth/accounts/index.php
declare(strict_types=1);

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
         AND t.user_server_id    = a.user_server_id
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
             current_balance, IFNULL(is_active,1) AS is_active, created_at, updated_at
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
$total_accounts    = count($rows);
$active_accounts   = count(array_filter($rows, fn($r) => (int)$r['is_active']));
$inactive_accounts = $total_accounts - $active_accounts;
$total_balance     = array_sum(array_map(fn($r) => (float)$r['current_balance'], $rows));

// Group by account type
$type_data = [];
foreach ($rows as $r) {
    $type = strtoupper((string)$r['account_type']);
    if (!isset($type_data[$type])) $type_data[$type] = ['count' => 0, 'balance' => 0.0];
    $type_data[$type]['count']++;
    $type_data[$type]['balance'] += (float)$r['current_balance'];
}

// Group by currency
$currency_data = [];
foreach ($rows as $r) {
    $curr = strtoupper((string)$r['currency_code']);
    if (!isset($currency_data[$curr])) $currency_data[$curr] = ['count' => 0, 'balance' => 0.0];
    $currency_data[$curr]['count']++;
    $currency_data[$curr]['balance'] += (float)$r['current_balance'];
}

$recent = array_slice($rows, 0, 5);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Accounts Management - PFMS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- Icons + Charts (same as before) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

  <!-- Keep your old file, but add this inline style layer to make it prettier -->
  <link rel="stylesheet" href="index.css">
  <style>
    /* page hero */
    .page-hero{
      background: linear-gradient(135deg,#6366f1 0%, #8b5cf6 35%, #22c55e 100%);
      padding: 22px;
      border-radius: 18px;
      color:#fff;
      margin-bottom:14px;
      display:flex;justify-content:space-between;align-items:center;gap:12px;
      box-shadow: 0 10px 24px rgba(0,0,0,.08);
    }
    .page-hero h1{margin:0;font-size:1.35rem;font-weight:900}
    .page-hero p{margin:4px 0 0 0;opacity:.95}
    .db-chip{display:inline-flex;gap:8px;align-items:center;background:rgba(255,255,255,.18);
      padding:8px 12px;border-radius:999px;font-weight:800}
    .db-chip .ok{color:#bbf7d0}
    .db-chip .fb{color:#fde68a}

    /* stats */
    .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:14px 0}
    .stat-box{display:flex;gap:12px;align-items:center;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px;box-shadow:0 6px 14px rgba(17,24,39,.04)}
    .stat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center}
    .stat-primary .stat-icon{background:#eef2ff;color:#4f46e5}
    .stat-success .stat-icon{background:#ecfdf5;color:#047857}
    .stat-warning .stat-icon{background:#fffbeb;color:#b45309}
    .stat-info .stat-icon{background:#e0f2fe;color:#0369a1}
    .stat-label{color:#6b7280;font-weight:700;font-size:.85rem}
    .stat-value{font-weight:900;color:#111827;font-size:1.35rem}

    /* table section */
    .table-section{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:16px;box-shadow:0 8px 18px rgba(17,24,39,.05)}
    .section-header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px;flex-wrap:wrap}
    .section-header h2{margin:0;font-size:1.15rem;font-weight:900;color:#111827}
    .section-header p{margin:4px 0 0 0;color:#6b7280}
    .search-container{display:flex;align-items:center;gap:8px;border:2px solid #e5e7eb;border-radius:12px;padding:8px 10px;background:#fff}
    .search-container input{border:0;outline:0;width:220px}
    .search-container:focus-within{border-color:#6366f1;box-shadow:0 0 0 4px rgba(99,102,241,.12)}

    /* cards */
    .accounts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
    .account-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:14px;display:flex;flex-direction:column;gap:10px;transition:.2s ease;position:relative;overflow:hidden}
    .account-card::after{content:'';position:absolute;inset:auto auto 0 0;width:100%;height:4px;background:linear-gradient(90deg,#6366f1,#8b5cf6,#10b981);opacity:.7}
    .account-card:hover{transform:translateY(-2px);box-shadow:0 12px 22px rgba(17,24,39,.08)}
    .account-header{display:flex;justify-content:space-between;align-items:center}
    .account-icon-wrapper{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:#eef2ff;color:#4f46e5}
    .badge{display:inline-flex;gap:6px;align-items:center;padding:6px 10px;border-radius:999px;font-weight:800;font-size:.8rem}
    .badge-success{background:rgba(16,185,129,.14);color:#065f46}
    .badge-inactive{background:rgba(239,68,68,.14);color:#7f1d1d}
    .account-body h3{margin:0 0 4px 0;font-size:1.05rem;font-weight:900;color:#111827}
    .account-meta{display:flex;gap:10px;color:#6b7280;font-size:.9rem}
    .meta-pill{display:inline-flex;gap:6px;align-items:center;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:999px;padding:4px 8px;font-weight:700}
    .meta-curr{background:#eef2ff;color:#4f46e5;border-color:#e5e7eb}
    .account-balance{display:flex;justify-content:space-between;align-items:center;margin-top:6px}
    .balance-label{color:#6b7280;font-weight:700}
    .balance-amount{font-weight:900;color:#111827}
    .account-footer{display:flex;justify-content:flex-end}
    .action-btn{display:inline-flex;gap:8px;align-items:center;padding:8px 12px;border-radius:10px;border:2px solid #e5e7eb;color:#374151;text-decoration:none;font-weight:800}
    .action-btn:hover{background:#f9fafb;border-color:#c7cbe1}

    /* top bar actions */
    .btn-icon{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:12px;border:2px solid #e5e7eb;background:#fff;color:#374151}
    .btn-icon:hover{background:#f9fafb}
    .btn-primary{display:inline-flex;gap:8px;align-items:center;padding:10px 14px;border-radius:12px;color:#fff;text-decoration:none;background:linear-gradient(135deg,#6366f1,#8b5cf6);font-weight:800}
    .btn-primary:hover{filter:brightness(1.03)}

    /* empty */
    .empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:28px;text-align:center;border:2px dashed #e5e7eb;border-radius:14px;background:#f9fafb}
    .empty-icon{font-size:2rem;color:#a3a3a3;margin-bottom:8px}
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
      <a href="<?= APP_BASE ?>/public/dashboard.php" class="nav-item">
        <i class="fas fa-home"></i> <span>Dashboard</span>
      </a>
      <a href="<?= APP_BASE ?>/app/auth/accounts/index.php" class="nav-item active">
        <i class="fas fa-wallet"></i> <span>Accounts</span>
      </a>
      <a href="<?= APP_BASE ?>/app/categories/index.php" class="nav-item">
        <i class="fas fa-tags"></i> <span>Categories</span>
      </a>
      <a href="<?= APP_BASE ?>/app/transactions/index.php" class="nav-item">
        <i class="fas fa-exchange-alt"></i> <span>Transactions</span>
      </a>
      <a href="<?= APP_BASE ?>/app/reports/index_oracle.php" class="nav-item">
        <i class="fas fa-chart-bar"></i> <span>Reports</span>
      </a>
      <a href="<?= APP_BASE ?>/public/sync.php" class="nav-item">
        <i class="fas fa-sync-alt"></i> <span>Sync</span>
      </a>
    </nav>
    <div class="sidebar-footer">
      <a href="<?= APP_BASE ?>/public/logout.php" class="logout-link">
        <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="main-content">

    <!-- Page Hero (kept layout, just prettier) -->
    <div class="page-hero">
      <div>
        <h1><i class="fas fa-wallet"></i> Accounts Overview</h1>
        <p>Manage and monitor your financial accounts</p>
      </div>
      <div class="db-chip">
        <?php if ($use_oracle): ?>
          <i class="fas fa-database ok"></i> Oracle Connected
        <?php else: ?>
          <i class="fas fa-laptop-code fb"></i> SQLite (Fallback)
        <?php endif; ?>
      </div>
    </div>

    <!-- Top actions (same places) -->
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px">
      <div></div>
      <div style="display:flex;gap:10px;align-items:center">
        <a href="<?= APP_BASE ?>/public/sync.php" class="btn-icon" title="Sync Now">
          <i class="fas fa-sync-alt"></i>
        </a>
        <a href="<?= APP_BASE ?>/app/auth/accounts/create.php" class="btn-primary">
          <i class="fas fa-plus"></i><span>New Account</span>
        </a>
      </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-box stat-primary">
        <div class="stat-icon"><i class="fas fa-wallet"></i></div>
        <div>
          <div class="stat-label">Total Accounts</div>
          <div class="stat-value"><?= $total_accounts ?></div>
        </div>
      </div>
      <div class="stat-box stat-success">
        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        <div>
          <div class="stat-label">Active Accounts</div>
          <div class="stat-value"><?= $active_accounts ?></div>
        </div>
      </div>
      <div class="stat-box stat-warning">
        <div class="stat-icon"><i class="fas fa-coins"></i></div>
        <div>
          <div class="stat-label">Total Balance</div>
          <div class="stat-value"><?= number_format($total_balance, 2) ?></div>
        </div>
      </div>
      <div class="stat-box stat-info">
        <div class="stat-icon"><i class="fas fa-globe"></i></div>
        <div>
          <div class="stat-label">Currencies</div>
          <div class="stat-value"><?= count($currency_data) ?></div>
        </div>
      </div>
    </div>

    <!-- Accounts -->
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
          <i class="fas fa-plus"></i> <span>Create Account</span>
        </a>
      </div>
      <?php else: ?>
      <div class="accounts-grid">
        <?php foreach($rows as $r):
          $name = (string)$r['account_name'];
          $type = strtoupper((string)$r['account_type']);
          $curr = (string)$r['currency_code'];
          $bal  = (float)$r['current_balance'];
          $isActive = (int)$r['is_active'] === 1;

          $icon = 'wallet';
          if ($type === 'BANK')   $icon = 'building-columns';
          if ($type === 'CARD')   $icon = 'credit-card';
          if ($type === 'CASH')   $icon = 'money-bill-wave';
          if ($type === 'MOBILE') $icon = 'mobile-screen';
        ?>
        <div class="account-card"
             data-account-name="<?= h($name) ?>"
             data-account-type="<?= h($type) ?>"
             data-currency="<?= h($curr) ?>">
          <div class="account-header">
            <div class="account-icon-wrapper"><i class="fas fa-<?= $icon ?>"></i></div>
            <div class="account-status">
              <?php if ($isActive): ?>
                <span class="badge badge-success"><i class="fas fa-circle"></i> Active</span>
              <?php else: ?>
                <span class="badge badge-inactive"><i class="fas fa-circle"></i> Inactive</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="account-body">
            <h3><?= h($name) ?></h3>
            <div class="account-meta">
              <span class="meta-pill"><i class="fas fa-tag"></i> <?= h($type) ?></span>
              <span class="meta-pill meta-curr"><i class="fas fa-dollar-sign"></i> <?= h($curr) ?></span>
            </div>
            <div class="account-balance">
              <span class="balance-label">Current Balance</span>
              <span class="balance-amount"><?= number_format($bal, 2) ?></span>
            </div>
          </div>

          <div class="account-footer">
            <a href="<?= APP_BASE ?>/app/auth/accounts/edit.php?id=<?= (int)$r['local_account_id'] ?>"
               class="action-btn">
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
  // Smooth search (unchanged logic, nicer feel)
  const si = document.getElementById('searchInput');
  si?.addEventListener('input', e => {
    const s = (e.target.value || '').toLowerCase();
    document.querySelectorAll('.account-card').forEach(c => {
      const name = (c.dataset.accountName || '').toLowerCase();
      const type = (c.dataset.accountType || '').toLowerCase();
      const cur  = (c.dataset.currency   || '').toLowerCase();
      c.style.display = (name.includes(s)||type.includes(s)||cur.includes(s)) ? '' : 'none';
    });
  });
</script>

</body>
</html>
