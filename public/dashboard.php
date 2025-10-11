<?php
// public/dashboard.php
require __DIR__ . '/../config/env.php';
require __DIR__ . '/../db/sqlite.php';

// ---- include the guard (path-safe) ----
$guard1 = __DIR__ . '/../app/common/auth_guard.php';          // if you put it in app/common
$guard2 = __DIR__ . '/../app/auth/common/auth_guard.php';     // if it's in app/auth/common
$guard3 = __DIR__ . '/../app/auth/auth_guard.php';            // if it's directly in app/auth

if (file_exists($guard1)) {
    require $guard1;
} elseif (file_exists($guard2)) {
    require $guard2;
} elseif (file_exists($guard3)) {
    require $guard3;
} else {
    // Fallback: minimal guard if file not found (prevents fatal error)
    session_start();
    if (!isset($_SESSION['uid'])) {
        header('Location: /pfms/app/auth/login.php');
        exit;
    }
}

// ---- DB and user ----
$pdo = sqlite();
$uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;

// ---- Quick counts (safe even if tables empty) ----
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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PFMS — Dashboard</title>
  
  <!-- Font Awesome 5 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  
  <!-- Custom CSS -->
  <link rel="stylesheet" href="dashboard.css">
</head>
<body>

  <!-- === NAVBAR === -->
  <div class="navbar">
    <h1>PFMS Dashboard</h1>
    <button class="logout-btn" onclick="location.href='<?= APP_BASE ?>/public/logout.php'">
      <i class="fas fa-sign-out-alt"></i> Logout
    </button>
  </div>

  <!-- === MAIN CONTAINER === -->
  <div class="main-container">

    <div class="card" data-card="accounts">
      <h2 data-icon="&#xf53d;">
        <i class="fas fa-wallet"></i> Accounts
      </h2>
      <p>You have <b><?= $acc_count ?></b> accounts.</p>
      <a href="<?= APP_BASE ?>/app/auth/accounts/index.php">
        <i class="fas fa-cog"></i> Manage Accounts
      </a>
    </div>

    <div class="card" data-card="categories">
      <h2 data-icon="&#xf02b;">
        <i class="fas fa-tags"></i> Categories
      </h2>
      <p>You have <b><?= $cat_count ?></b> categories.</p>
      <a href="<?= APP_BASE ?>/app/categories/index.php">
        <i class="fas fa-edit"></i> Manage Categories
      </a>
    </div>

    <div class="card" data-card="transactions">
      <h2 data-icon="&#xf155;">
        <i class="fas fa-money-bill-wave"></i> Transactions
      </h2>
      <p>You have <b><?= $txn_count ?></b> transactions.</p>
      <a href="<?= APP_BASE ?>/app/transactions/index.php">
        <i class="fas fa-list"></i> View Transactions
      </a>
    </div>

    <div class="card" data-card="reports">
      <h2 data-icon="&#xf201;">
        <i class="fas fa-chart-line"></i> Reports
      </h2>
      <p>Generate detailed financial reports.</p>
      <a href="<?= APP_BASE ?>/app/reports/index.php">
        <i class="fas fa-eye"></i> View Reports
      </a>
    </div>

    <div class="card" data-card="sync">
      <h2 data-icon="&#xf2f1;">
        <i class="fas fa-sync-alt"></i> Sync
      </h2>
      <p>Sync your local data with the cloud.</p>
      <a href="<?= APP_BASE ?>/public/sync.php">
        <i class="fas fa-cloud-upload-alt"></i> Start Sync
      </a>
    </div>

  </div>

  <!-- === FOOTER === -->
  <footer>
    <p>
      <i class="fas fa-copyright"></i> <?= date('Y') ?> PFMS — Personal Finance Management System
    </p>
  </footer>

</body>
</html>