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
<html>
<head>
  <meta charset="utf-8">
  <title>PFMS — Dashboard</title>
  <link rel="stylesheet" href="dashboard.css">

</head>
<body>
  <h1>PFMS Dashboard 🚀</h1>

  <!-- Removed url() helper; use absolute paths -->
  <nav>
    <a href="/pfms/app/accounts/index.php">💼 Accounts</a> |
    <a href="/pfms/app/categories/index.php">🏷️ Categories</a> |
    <a href="/pfms/app/transactions/index.php">💵 Transactions</a> |
    <a href="/pfms/app/reports/index.php">📊 Reports</a> |
    <a href="/pfms/public/sync.php">🔁 Sync</a> |
    <a href="/pfms/public/logout.php">🚪 Logout</a>
  </nav>

  <hr>

  <h3>Quick Stats 📈</h3>
  <ul>
    <li>Accounts: <b><?= $acc_count ?></b></li>
    <li>Categories: <b><?= $cat_count ?></b></li>
    <li>Transactions: <b><?= $txn_count ?></b></li>
  </ul>

  <p>Tip: Start by creating <a href="/pfms/app/categories/create.php">your first account</a> 🧱</p>
</body>
</html>
