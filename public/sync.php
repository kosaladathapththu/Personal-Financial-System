<?php
// pfms/public/sync.php

require __DIR__ . '/../config/env.php';
require __DIR__ . '/../db/sqlite.php';
require __DIR__ . '/../db/oracle.php';

// Auth guard (starts session if needed)
$guardFiles = [
    __DIR__ . '/../app/auth/common/auth_guard.php',
    __DIR__ . '/../app/common/auth_guard.php',
];
foreach ($guardFiles as $g) {
    if (file_exists($g)) { require $g; break; }
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// ---- DB handles & current user ----
$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
    header('Location: ' . APP_BASE . '/public/login.php?err=login_required');
    exit;
}

// ---- Stats (SQLite) ----
$pendingTxn = (int)$pdo->query("SELECT COUNT(*) FROM TRANSACTIONS_LOCAL WHERE user_local_id={$uid} AND sync_status='PENDING'")->fetchColumn();
$syncedTxn = (int)$pdo->query("SELECT COUNT(*) FROM TRANSACTIONS_LOCAL WHERE user_local_id={$uid} AND sync_status='SYNCED'")->fetchColumn();
$conflictTxn = (int)$pdo->query("SELECT COUNT(*) FROM TRANSACTIONS_LOCAL WHERE user_local_id={$uid} AND sync_status='CONFLICT'")->fetchColumn();

$accNoSrv = (int)$pdo->query("SELECT COUNT(*) FROM ACCOUNTS_LOCAL WHERE user_local_id={$uid} AND (server_account_id IS NULL OR server_account_id='')")->fetchColumn();
$accSynced = (int)$pdo->query("SELECT COUNT(*) FROM ACCOUNTS_LOCAL WHERE user_local_id={$uid} AND server_account_id IS NOT NULL AND server_account_id!=''")->fetchColumn();

$catNoSrv = (int)$pdo->query("SELECT COUNT(*) FROM CATEGORIES_LOCAL WHERE user_local_id={$uid} AND (server_category_id IS NULL OR server_category_id='')")->fetchColumn();
$catSynced = (int)$pdo->query("SELECT COUNT(*) FROM CATEGORIES_LOCAL WHERE user_local_id={$uid} AND server_category_id IS NOT NULL AND server_category_id!=''")->fetchColumn();

$lastSync = $pdo->query("SELECT MAX(last_sync_at) FROM TRANSACTIONS_LOCAL WHERE user_local_id={$uid}")->fetchColumn();

$totalItems = (int)$pdo->query("SELECT COUNT(*) FROM TRANSACTIONS_LOCAL WHERE user_local_id={$uid}")->fetchColumn();
$syncHealth = $totalItems > 0 ? round(($syncedTxn / $totalItems) * 100) : 100;

// recent pending list
$recentPending = $pdo->query("
  SELECT t.txn_date, t.txn_type, t.amount, c.category_name, a.account_name
  FROM TRANSACTIONS_LOCAL t
  LEFT JOIN CATEGORIES_LOCAL c ON c.local_category_id = t.category_local_id
  LEFT JOIN ACCOUNTS_LOCAL a   ON a.local_account_id  = t.account_local_id
  WHERE t.user_local_id = {$uid} AND t.sync_status='PENDING'
  ORDER BY datetime(t.txn_date) DESC, t.local_txn_id DESC
  LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// conflict list
$conflicts = $pdo->query("
  SELECT t.txn_date, t.txn_type, t.amount, c.category_name, a.account_name, t.client_txn_uuid
  FROM TRANSACTIONS_LOCAL t
  LEFT JOIN CATEGORIES_LOCAL c ON c.local_category_id = t.category_local_id
  LEFT JOIN ACCOUNTS_LOCAL a   ON a.local_account_id  = t.account_local_id
  WHERE t.user_local_id = {$uid} AND t.sync_status='CONFLICT'
  ORDER BY datetime(t.txn_date) DESC
  LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// oracle status
$oracleStatus = oracle_get_status();
$canSync = $oracleStatus['can_connect'];

// flash message
$msg = $_GET['msg'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Sync Center - PFMS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
  <link rel="stylesheet" href="<?= APP_BASE ?>/public/dashboard.css">
  <style>
    /* Enhanced Styles with Sidebar */
    .hero{background:linear-gradient(135deg,#667eea,#764ba2);padding:32px;border-radius:20px;color:#fff;box-shadow:0 20px 60px rgba(102,126,234,.3);position:relative;overflow:hidden;margin-bottom:24px}
    .hero::before{content:'';position:absolute;top:-50%;right:-10%;width:500px;height:500px;background:radial-gradient(circle,rgba(255,255,255,.1) 0%,transparent 70%);animation:pulse 8s ease-in-out infinite}
    .hero-content{position:relative;z-index:2}
    .pill{display:inline-flex;gap:8px;align-items:center;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);padding:10px 16px;border-radius:14px;margin-right:12px;margin-bottom:8px;backdrop-filter:blur(10px)}
    
    .control-panel{background:#fff;border-radius:20px;padding:24px;box-shadow:0 10px 40px rgba(0,0,0,.05);border:1px solid #e5e7eb;margin-bottom:24px}
    .control-header{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
    .connection-badge{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:100px;font-weight:700;font-size:.875rem;animation:fadeIn .5s}
    .connection-badge.connected{background:rgba(16,185,129,.1);color:#10b981;border:2px solid rgba(16,185,129,.3)}
    .connection-badge.disconnected{background:rgba(239,68,68,.1);color:#ef4444;border:2px solid rgba(239,68,68,.3)}
    .connection-badge i{animation:pulse 2s ease-in-out infinite}
    
    .btn{border:none;border-radius:12px;padding:14px 24px;font-weight:700;cursor:pointer;transition:all .3s;display:inline-flex;align-items:center;gap:8px}
    .btn-primary{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;box-shadow:0 10px 30px rgba(102,126,234,.3)}
    .btn-primary:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 15px 40px rgba(102,126,234,.4)}
    .btn-primary:disabled{opacity:.6;cursor:not-allowed}
    .btn-ghost{background:#fff;color:#667eea;border:2px solid #667eea}
    .btn-ghost:hover{background:#667eea;color:#fff}
    
    .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;margin:24px 0}
    .stat-card{background:#fff;border-radius:20px;padding:24px;box-shadow:0 4px 20px rgba(0,0,0,.06);border:1px solid #e5e7eb;transition:all .3s;position:relative;overflow:hidden}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#667eea,#764ba2)}
    .stat-card:hover{transform:translateY(-5px);box-shadow:0 12px 40px rgba(0,0,0,.1)}
    .stat-icon{width:64px;height:64px;border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:1.75rem;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;box-shadow:0 10px 30px rgba(102,126,234,.3);margin-bottom:16px}
    .stat-value{font-size:2.5rem;font-weight:800;color:#111827;line-height:1;margin-bottom:8px}
    .stat-label{color:#6b7280;font-size:.875rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
    .stat-trend{display:flex;align-items:center;gap:6px;margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb;font-weight:600;font-size:.875rem}
    
    .charts-section{display:grid;grid-template-columns:repeat(auto-fit,minmax(400px,1fr));gap:20px;margin:24px 0}
    .chart-card{background:#fff;border-radius:20px;padding:24px;box-shadow:0 4px 20px rgba(0,0,0,.06);border:1px solid #e5e7eb;height:400px}
    .chart-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
    .chart-badge{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:6px 12px;border-radius:100px;font-size:.75rem;font-weight:700;text-transform:uppercase}
    
    .content-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin:24px 0}
    .transaction-card{background:#fff;border-radius:16px;padding:16px;margin-bottom:12px;border:2px solid #f3f4f6;transition:all .3s;cursor:pointer}
    .transaction-card:hover{border-color:#667eea;transform:translateX(5px);box-shadow:0 4px 20px rgba(102,126,234,.15)}
    .txn-content{display:flex;justify-content:space-between;align-items:center;gap:16px}
    .txn-icon-circle{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
    .txn-icon-circle.income{background:linear-gradient(135deg,#10b981,#059669);color:#fff}
    .txn-icon-circle.expense{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff}
    .txn-icon-circle.conflict{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff}
    .txn-info{flex:1}
    .txn-info h4{font-size:1rem;font-weight:700;color:#111827;margin:0 0 4px 0}
    .txn-info p{font-size:.875rem;color:#6b7280;margin:0}
    .txn-amount h4{font-size:1.25rem;font-weight:800;color:#111827;margin:0 0 4px 0;text-align:right}
    .txn-amount p{font-size:.75rem;color:#6b7280;margin:0;text-align:right}
    
    .card{background:#fff;border-radius:20px;padding:24px;box-shadow:0 4px 20px rgba(0,0,0,.06);border:1px solid #e5e7eb}
    .card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
    .badge{display:inline-block;padding:8px 14px;border-radius:100px;font-weight:700;font-size:.8rem}
    .badge.ok{background:rgba(16,185,129,.12);color:#059669;border:1px solid rgba(16,185,129,.3)}
    .badge.warn{background:rgba(245,158,11,.12);color:#b45309;border:1px solid rgba(245,158,11,.35)}
    .badge.err{background:rgba(239,68,68,.12);color:#b91c1c;border:1px solid rgba(239,68,68,.3)}
    
    .tips-card{background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:20px;padding:24px;border:2px solid #fbbf24}
    .tip-item{display:flex;align-items:flex-start;gap:12px;padding:12px;background:#fff;border-radius:12px;margin-bottom:10px;transition:all .3s}
    .tip-item:hover{transform:translateX(5px);box-shadow:0 4px 15px rgba(251,191,36,.2)}
    .tip-item i{color:#10b981;font-size:1.125rem;margin-top:2px}
    
    .empty-state{text-align:center;padding:48px 24px;background:#f9fafb;border-radius:16px;border:2px dashed #e5e7eb}
    .empty-state i{font-size:4rem;color:#d1d5db;margin-bottom:16px}
    
    .msg{margin:16px 0;padding:16px 20px;border-radius:12px;display:flex;gap:12px;align-items:center;animation:slideDown .5s}
    .msg.success{background:linear-gradient(135deg,#d1fae5,#a7f3d0);border:2px solid #10b981}
    .msg.error{background:linear-gradient(135deg,#fee2e2,#fecaca);border:2px solid #ef4444}
    .msg-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0}
    .msg.success .msg-icon{background:#10b981;color:#fff}
    .msg.error .msg-icon{background:#ef4444;color:#fff}
    
    .detail-row{display:flex;justify-content:space-between;align-items:center;padding:14px;background:linear-gradient(135deg,#f3f4f6,#e5e7eb);border-radius:12px;margin-bottom:10px}
    
    .progress-wave{margin-top:20px;background:#f3f4f6;border-radius:100px;height:12px;overflow:hidden;position:relative;display:none}
    .progress-wave-fill{height:100%;background:linear-gradient(90deg,#667eea,#764ba2,#667eea);background-size:200% 100%;animation:wave 2s ease-in-out infinite;border-radius:100px}
    
    @keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.8;transform:scale(1.05)}}
    @keyframes wave{0%{background-position:0% 50%}100%{background-position:200% 50%}}
    @keyframes fadeIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
    @keyframes slideDown{from{opacity:0;transform:translateY(-20px)}to{opacity:1;transform:translateY(0)}}
    @keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
    
    @media(max-width:880px){
      .content-grid{grid-template-columns:1fr}
      .charts-section{grid-template-columns:1fr}
    }
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
      <a href="<?= APP_BASE ?>/app/reports/index.php" class="nav-item">
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

    <!-- Hero Section -->
    <div class="hero">
      <div class="hero-content">
        <h1 style="margin:0 0 8px 0;font-size:2.5rem;font-weight:800;text-shadow:0 2px 10px rgba(0,0,0,.1)">
          <i class="fas fa-cloud"></i> Cloud Synchronization
        </h1>
        <p style="margin:0 0 16px 0;opacity:.95;font-size:1.125rem">
          Sync your PFMS data with Oracle (schema: <b><?= h($oracleStatus['session_user'] ?? 'n/a') ?></b> on <b><?= h($oracleStatus['con_name'] ?? 'n/a') ?></b>)
        </p>
        <div>
          <span class="pill"><i class="fas fa-check-double"></i> <b><?= $syncedTxn ?></b> Synced</span>
          <span class="pill"><i class="fas fa-clock"></i> <b><?= $pendingTxn ?></b> Pending</span>
          <?php if ($conflictTxn>0): ?>
            <span class="pill"><i class="fas fa-exclamation-triangle"></i> <b><?= $conflictTxn ?></b> Conflicts</span>
          <?php endif; ?>
          <span class="pill" style="float:right">
            <?= $canSync ? '<i class="fas fa-wifi"></i> Oracle Connected' : '<i class="fas fa-exclamation-triangle"></i> Oracle Offline' ?>
          </span>
        </div>
      </div>
    </div>

    <!-- Flash Message -->
    <?php if ($msg): ?>
    <div class="msg success">
      <div class="msg-icon">
        <i class="fas fa-check-circle"></i>
      </div>
      <div style="flex:1">
        <h4 style="margin:0 0 4px 0;font-weight:700;color:#065f46">Synchronization Completed!</h4>
        <p style="margin:0;color:#374151"><?= h($msg) ?></p>
      </div>
    </div>
    <?php endif; ?>

    <!-- Control Panel -->
    <div class="control-panel">
      <div class="control-header">
        <div>
          <h2 style="margin:0 0 8px 0;font-size:1.5rem;font-weight:700;color:#111827">
            <i class="fas fa-rocket" style="color:#667eea"></i> Sync Control Center
          </h2>
          <div class="connection-badge <?= $canSync ? 'connected' : 'disconnected' ?>">
            <i class="fas fa-<?= $canSync ? 'wifi' : 'exclamation-triangle' ?>"></i>
            <span><?= $canSync ? 'Oracle Connected' : 'Connection Failed' ?></span>
          </div>
        </div>
        <div style="display:flex;gap:12px">
          <form method="post" action="<?= APP_BASE ?>/api/run_sync.php" id="syncForm">
            <button class="btn btn-primary" id="syncBtn" <?= $canSync ? '' : 'disabled' ?>>
              <i class="fas fa-sync-alt" id="syncIcon"></i>
              <span id="syncText">Start Cloud Sync</span>
            </button>
          </form>
          <button class="btn btn-ghost" onclick="location.reload()">
            <i class="fas fa-redo"></i> Refresh
          </button>
        </div>
      </div>
      <div class="progress-wave" id="progressWave">
        <div class="progress-wave-fill"></div>
      </div>
      <p id="progressText" style="display:none;text-align:center;margin-top:12px;color:#667eea;font-weight:600">
        <i class="fas fa-spinner fa-spin"></i> Synchronizing with Oracle Cloud...
      </p>
    </div>

    <!-- Statistics Grid -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fas fa-check-double"></i>
        </div>
        <div class="stat-value"><?= $syncedTxn ?></div>
        <div class="stat-label">Synced Transactions</div>
        <div class="stat-trend" style="color:#10b981">
          <i class="fas fa-arrow-up"></i>
          <span>Successfully synchronized</span>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 10px 30px rgba(245,158,11,.3)">
          <i class="fas fa-hourglass-half"></i>
        </div>
        <div class="stat-value" style="color:#f59e0b"><?= $pendingTxn ?></div>
        <div class="stat-label">Pending Sync</div>
        <div class="stat-trend" style="color:#f59e0b">
          <i class="fas fa-clock"></i>
          <span>Waiting for sync</span>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 10px 30px rgba(239,68,68,.3)">
          <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-value" style="color:#ef4444"><?= $conflictTxn ?></div>
        <div class="stat-label">Sync Conflicts</div>
        <div class="stat-trend" style="color:#ef4444">
          <i class="fas fa-exclamation-circle"></i>
          <span>Require attention</span>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb);box-shadow:0 10px 30px rgba(59,130,246,.3)">
          <i class="fas fa-database"></i>
        </div>
        <div class="stat-value" style="color:#3b82f6"><?= $totalItems ?></div>
        <div class="stat-label">Total Items</div>
        <div class="stat-trend" style="color:#3b82f6">
          <i class="fas fa-list"></i>
          <span>Health: <?= $syncHealth ?>%</span>
        </div>
      </div>
    </div>

    <!-- Charts Section -->
    <div class="charts-section">
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <h3 style="margin:0;font-size:1.25rem;font-weight:700">Sync Status Distribution</h3>
            <p style="margin:4px 0 0 0;color:#6b7280;font-size:.875rem">Visual breakdown of all transactions</p>
          </div>
          <span class="chart-badge">Live Data</span>
        </div>
        <div style="height:280px;position:relative">
          <canvas id="syncChart"></canvas>
        </div>
      </div>
      
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <h3 style="margin:0;font-size:1.25rem;font-weight:700">Data Sync Progress</h3>
            <p style="margin:4px 0 0 0;color:#6b7280;font-size:.875rem">Accounts and Categories status</p>
          </div>
          <span class="chart-badge">Updated</span>
        </div>
        <div style="height:280px;position:relative">
          <canvas id="dataChart"></canvas>
        </div>
      </div>
    </div>

    <!-- Content Grid -->
    <div class="content-grid">
      
      <!-- Left: Pending & Conflicts -->
      <div style="display:flex;flex-direction:column;gap:20px">
        
        <!-- Pending Transactions -->
        <div class="card">
          <div class="card-header">
            <div>
              <h3 style="margin:0 0 4px 0;font-size:1.25rem;font-weight:700">
                <i class="fas fa-clock" style="color:#f59e0b"></i> Recent Pending
              </h3>
              <p style="margin:0;color:#6b7280;font-size:.875rem">Items waiting to be synchronized</p>
            </div>
            <span class="badge warn"><?= count($recentPending) ?> items</span>
          </div>
          
          <?php if (empty($recentPending)): ?>
            <div class="empty-state">
              <i class="fas fa-check-circle"></i>
              <h4 style="color:#6b7280;margin-bottom:8px">All Clear!</h4>
              <p style="color:#9ca3af;font-size:.875rem">No pending transactions to sync 🎉</p>
            </div>
          <?php else: ?>
            <?php foreach($recentPending as $row): ?>
            <div class="transaction-card">
              <div class="txn-content">
                <div class="txn-icon-circle <?= strtolower($row['txn_type']) ?>">
                  <i class="fas fa-<?= $row['txn_type'] === 'INCOME' ? 'arrow-down' : 'arrow-up' ?>"></i>
                </div>
                <div class="txn-info">
                  <h4><?= h($row['category_name'] ?? 'Uncategorized') ?></h4>
                  <p><i class="fas fa-wallet"></i> <?= h($row['account_name'] ?? 'Unknown') ?></p>
                </div>
                <div class="txn-amount">
                  <h4>$<?= number_format((float)$row['amount'], 2) ?></h4>
                  <p><?= date('M d, Y', strtotime($row['txn_date'])) ?></p>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        
        <!-- Conflicts -->
        <?php if (!empty($conflicts)): ?>
        <div class="card">
          <div class="card-header">
            <div>
              <h3 style="margin:0 0 4px 0;font-size:1.25rem;font-weight:700">
                <i class="fas fa-exclamation-triangle" style="color:#ef4444"></i> Sync Conflicts
              </h3>
              <p style="margin:0;color:#6b7280;font-size:.875rem">Items with synchronization issues</p>
            </div>
            <span class="badge err"><?= count($conflicts) ?> conflicts</span>
          </div>
          
          <?php foreach($conflicts as $row): ?>
          <div class="transaction-card" style="border-color:rgba(239,68,68,.3)">
            <div class="txn-content">
              <div class="txn-icon-circle conflict">
                <i class="fas fa-exclamation-triangle"></i>
              </div>
              <div class="txn-info">
                <h4><?= h($row['category_name'] ?? 'Uncategorized') ?></h4>
                <p><i class="fas fa-wallet"></i> <?= h($row['account_name'] ?? 'Unknown') ?></p>
              </div>
              <div class="txn-amount">
                <h4 style="color:#ef4444">$<?= number_format((float)$row['amount'], 2) ?></h4>
                <p><?= date('M d, Y', strtotime($row['txn_date'])) ?></p>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      
      <!-- Right: Details & Tips -->
      <div style="display:flex;flex-direction:column;gap:20px">
        
        <!-- Sync Details -->
        <div class="card">
          <h3 style="margin:0 0 20px 0;font-size:1.25rem;font-weight:700">
            <i class="fas fa-info-circle" style="color:#667eea"></i> Sync Details
          </h3>
          
          <div class="detail-row">
            <span style="color:#374151;font-weight:600"><i class="fas fa-wallet"></i> Accounts</span>
            <span style="font-weight:800;color:#111827"><?= $accSynced ?> / <?= $accSynced + $accNoSrv ?></span>
          </div>
          
          <div class="detail-row">
            <span style="color:#374151;font-weight:600"><i class="fas fa-tags"></i> Categories</span>
            <span style="font-weight:800;color:#111827"><?= $catSynced ?> / <?= $catSynced + $catNoSrv ?></span>
          </div>
          
          <div class="detail-row">
            <span style="color:#374151;font-weight:600"><i class="fas fa-exchange-alt"></i> Transactions</span>
            <span style="font-weight:800;color:#111827"><?= $syncedTxn ?> / <?= $totalItems ?></span>
          </div>
          
          <div style="display:flex;justify-content:space-between;align-items:center;padding:14px;background:linear-gradient(135deg,#667eea,#764ba2);border-radius:12px;margin-bottom:10px">
            <span style="color:#fff;font-weight:600"><i class="fas fa-clock"></i> Last Sync</span>
            <span style="font-weight:800;color:#fff"><?= $lastSync ? date('M d, H:i', strtotime($lastSync)) : 'Never' ?></span>
          </div>
          
          <div style="padding:14px;background:#f9fafb;border-radius:12px;margin-bottom:10px">
            <p style="margin:0 0 6px 0;color:#6b7280;font-size:.875rem">
              <i class="fas fa-database"></i> Oracle Version
            </p>
            <p style="margin:0;font-weight:700;color:#111827">
              <?= h($oracleStatus['oracle_version'] ?? 'n/a') ?>
            </p>
          </div>
          
          <div style="padding:14px;background:#f9fafb;border-radius:12px">
            <p style="margin:0 0 6px 0;color:#6b7280;font-size:.875rem">
              <i class="fas fa-server"></i> Connection Info
            </p>
            <p style="margin:0;font-weight:700;color:#111827;font-size:.875rem">
              DB: <?= h($oracleStatus['db_name'] ?? 'n/a') ?><br>
              Container: <?= h($oracleStatus['con_name'] ?? 'n/a') ?><br>
              User: <?= h($oracleStatus['session_user'] ?? 'n/a') ?>
            </p>
          </div>
        </div>
        
        <!-- Account & Category Stats -->
        <div class="card">
          <h3 style="margin:0 0 20px 0;font-size:1.25rem;font-weight:700">
            <i class="fas fa-chart-pie" style="color:#667eea"></i> Sync Overview
          </h3>
          
          <div style="margin-bottom:20px">
            <div style="display:flex;justify-content:space-between;margin-bottom:8px">
              <span style="color:#6b7280;font-weight:600">Accounts Synced</span>
              <span style="font-weight:700"><?= $accSynced + $accNoSrv > 0 ? round(($accSynced / ($accSynced + $accNoSrv)) * 100) : 0 ?>%</span>
            </div>
            <div style="width:100%;height:8px;background:#f3f4f6;border-radius:100px;overflow:hidden">
              <div style="width:<?= $accSynced + $accNoSrv > 0 ? round(($accSynced / ($accSynced + $accNoSrv)) * 100) : 0 ?>%;height:100%;background:linear-gradient(90deg,#10b981,#059669);border-radius:100px"></div>
            </div>
          </div>
          
          <div style="margin-bottom:20px">
            <div style="display:flex;justify-content:space-between;margin-bottom:8px">
              <span style="color:#6b7280;font-weight:600">Categories Synced</span>
              <span style="font-weight:700"><?= $catSynced + $catNoSrv > 0 ? round(($catSynced / ($catSynced + $catNoSrv)) * 100) : 0 ?>%</span>
            </div>
            <div style="width:100%;height:8px;background:#f3f4f6;border-radius:100px;overflow:hidden">
              <div style="width:<?= $catSynced + $catNoSrv > 0 ? round(($catSynced / ($catSynced + $catNoSrv)) * 100) : 0 ?>%;height:100%;background:linear-gradient(90deg,#3b82f6,#2563eb);border-radius:100px"></div>
            </div>
          </div>
          
          <div>
            <div style="display:flex;justify-content:space-between;margin-bottom:8px">
              <span style="color:#6b7280;font-weight:600">Overall Health</span>
              <span style="font-weight:700;color:<?= $syncHealth >= 80 ? '#10b981' : ($syncHealth >= 50 ? '#f59e0b' : '#ef4444') ?>"><?= $syncHealth ?>%</span>
            </div>
            <div style="width:100%;height:8px;background:#f3f4f6;border-radius:100px;overflow:hidden">
              <div style="width:<?= $syncHealth ?>%;height:100%;background:linear-gradient(90deg,<?= $syncHealth >= 80 ? '#10b981,#059669' : ($syncHealth >= 50 ? '#f59e0b,#d97706' : '#ef4444,#dc2626') ?>);border-radius:100px"></div>
            </div>
          </div>
        </div>
        
        <!-- Tips Card -->
        <div class="tips-card">
          <h3 style="margin:0 0 16px 0;color:#92400e;display:flex;align-items:center;gap:8px">
            <i class="fas fa-lightbulb"></i> Pro Tips
          </h3>
          
          <div class="tip-item">
            <i class="fas fa-check"></i>
            <span>Sync regularly to prevent data loss and ensure backup</span>
          </div>
          
          <div class="tip-item">
            <i class="fas fa-check"></i>
            <span>Resolve conflicts immediately to maintain data integrity</span>
          </div>
          
          <div class="tip-item">
            <i class="fas fa-check"></i>
            <span>Ensure stable internet connection before starting sync</span>
          </div>
          
          <div class="tip-item">
            <i class="fas fa-check"></i>
            <span>Monitor Oracle connection status regularly</span>
          </div>
          
          <div class="tip-item">
            <i class="fas fa-check"></i>
            <span>Check sync logs for any warnings or errors</span>
          </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="card">
          <h3 style="margin:0 0 16px 0;font-size:1.25rem;font-weight:700">
            <i class="fas fa-bolt" style="color:#667eea"></i> Quick Actions
          </h3>
          
          <a href="<?= APP_BASE ?>/public/dashboard.php" class="badge ok" style="display:block;text-align:center;padding:12px;margin-bottom:10px;text-decoration:none;cursor:pointer">
            <i class="fas fa-home"></i> Back to Dashboard
          </a>
          
          <a href="<?= APP_BASE ?>/app/transactions/index.php" class="badge warn" style="display:block;text-align:center;padding:12px;margin-bottom:10px;text-decoration:none;cursor:pointer">
            <i class="fas fa-exchange-alt"></i> View Transactions
          </a>
          
          <?php if ($conflictTxn > 0): ?>
          <a href="javascript:void(0)" class="badge err" style="display:block;text-align:center;padding:12px;text-decoration:none;cursor:pointer" onclick="alert('Conflict resolution feature coming soon!')">
            <i class="fas fa-exclamation-triangle"></i> Resolve Conflicts
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </main>
</div>

<script>
// Sync form handling
document.getElementById('syncForm')?.addEventListener('submit', function(e) {
  const btn = document.getElementById('syncBtn');
  const icon = document.getElementById('syncIcon');
  const text = document.getElementById('syncText');
  const progress = document.getElementById('progressWave');
  const progressText = document.getElementById('progressText');
  
  if (btn && !btn.disabled) {
    btn.disabled = true;
    btn.style.opacity = '0.7';
    icon.style.animation = 'spin 1s linear infinite';
    text.textContent = 'Synchronizing...';
    
    if (progress) progress.style.display = 'block';
    if (progressText) progressText.style.display = 'block';
  }
});

// Chart.js configurations
const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom',
      labels: { 
        padding: 20, 
        font: { size: 13, weight: '600' },
        usePointStyle: true,
        pointStyle: 'circle'
      }
    },
    tooltip: {
      backgroundColor: 'rgba(15, 23, 42, 0.95)',
      padding: 16,
      borderRadius: 12,
      titleFont: { size: 14, weight: '700' },
      bodyFont: { size: 13 }
    }
  }
};

// Sync Status Chart
new Chart(document.getElementById('syncChart'), {
  type: 'doughnut',
  data: {
    labels: ['Synced', 'Pending', 'Conflicts'],
    datasets: [{
      data: [<?= $syncedTxn ?>, <?= $pendingTxn ?>, <?= $conflictTxn ?>],
      backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
      borderWidth: 0,
      borderRadius: 8,
      spacing: 6,
      hoverOffset: 15
    }]
  },
  options: {
    ...chartOptions,
    cutout: '70%',
    animation: {
      animateScale: true,
      animateRotate: true
    }
  }
});

// Data Sync Chart
new Chart(document.getElementById('dataChart'), {
  type: 'bar',
  data: {
    labels: ['Accounts', 'Categories'],
    datasets: [
      {
        label: 'Synced',
        data: [<?= $accSynced ?>, <?= $catSynced ?>],
        backgroundColor: '#10b981',
        borderRadius: 10,
        borderSkipped: false
      },
      {
        label: 'Pending',
        data: [<?= $accNoSrv ?>, <?= $catNoSrv ?>],
        backgroundColor: '#f59e0b',
        borderRadius: 10,
        borderSkipped: false
      }
    ]
  },
  options: {
    ...chartOptions,
    scales: {
      y: {
        beginAtZero: true,
        ticks: { 
          stepSize: 1,
          font: { size: 12, weight: '600' }
        },
        grid: { 
          color: 'rgba(0,0,0,0.04)',
          drawBorder: false
        },
        border: { display: false }
      },
      x: {
        grid: { display: false },
        ticks: {
          font: { size: 13, weight: '600' }
        },
        border: { display: false }
      }
    },
    animation: {
      duration: 1500,
      easing: 'easeInOutQuart'
    }
  }
});

// Transaction card interactions
document.querySelectorAll('.transaction-card').forEach(card => {
  card.addEventListener('click', function() {
    this.style.transform = 'translateX(10px)';
    setTimeout(() => {
      this.style.transform = 'translateX(5px)';
    }, 200);
  });
});

// Console branding
console.log('%c🚀 PFMS Sync Center', 'color: #667eea; font-size: 20px; font-weight: bold;');
console.log('%cSync Health: <?= $syncHealth ?>%', 'color: <?= $syncHealth >= 80 ? '#10b981' : '#f59e0b' ?>; font-size: 14px;');

// Auto-refresh notification (optional)
let autoRefreshTimer;
function scheduleAutoRefresh() {
  autoRefreshTimer = setTimeout(() => {
    if (<?= $pendingTxn ?> > 0 && navigator.onLine) {
      console.log('💡 Consider refreshing to check sync status');
    }
  }, 30000);
}
scheduleAutoRefresh();

// Smooth scroll for quick actions
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
  anchor.addEventListener('click', function (e) {
    e.preventDefault();
    const target = document.querySelector(this.getAttribute('href'));
    if (target) {
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });
});
</script>

</body>
</html>