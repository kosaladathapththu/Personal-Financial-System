<?php
// public/dashboard.php
require __DIR__ . '/../config/env.php';
require __DIR__ . '/../db/sqlite.php';

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

// ---- DB and user ----
$pdo = sqlite();
$uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;

// ---- Quick counts ----
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

// Get financial summary
$summary = $pdo->prepare("
  SELECT 
    SUM(CASE WHEN txn_type='INCOME' THEN amount ELSE 0 END) as total_income,
    SUM(CASE WHEN txn_type='EXPENSE' THEN amount ELSE 0 END) as total_expense
  FROM TRANSACTIONS_LOCAL 
  WHERE user_local_id=?
");
$summary->execute([$uid]);
$fin = $summary->fetch(PDO::FETCH_ASSOC);
$total_income = (float)($fin['total_income'] ?? 0);
$total_expense = (float)($fin['total_expense'] ?? 0);
$net_balance = $total_income - $total_expense;

// Get recent transactions
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

// Get monthly data for chart (last 6 months)
$monthly = $pdo->prepare("
  SELECT 
    strftime('%Y-%m', txn_date) as month,
    SUM(CASE WHEN txn_type='INCOME' THEN amount ELSE 0 END) as income,
    SUM(CASE WHEN txn_type='EXPENSE' THEN amount ELSE 0 END) as expense
  FROM TRANSACTIONS_LOCAL
  WHERE user_local_id = ?
  GROUP BY strftime('%Y-%m', txn_date)
  ORDER BY month DESC
  LIMIT 6
");
$monthly->execute([$uid]);
$monthly_data = array_reverse($monthly->fetchAll(PDO::FETCH_ASSOC));

// Get category breakdown
$cat_breakdown = $pdo->prepare("
  SELECT c.category_name, c.category_type, SUM(t.amount) as total
  FROM TRANSACTIONS_LOCAL t
  JOIN CATEGORIES_LOCAL c ON t.category_local_id = c.local_category_id
  WHERE t.user_local_id = ?
  GROUP BY c.local_category_id
  ORDER BY total DESC
  LIMIT 5
");
$cat_breakdown->execute([$uid]);
$top_categories = $cat_breakdown->fetchAll(PDO::FETCH_ASSOC);

// ✅ Account balances (use live view)
$acc_balances = $pdo->prepare("
  SELECT account_name, current_balance, account_type
  FROM V_ACCOUNT_BALANCES
  WHERE user_local_id = ? AND is_active = 1
  ORDER BY current_balance DESC
  LIMIT 5
");
$acc_balances->execute([$uid]);
$accounts = $acc_balances->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PFMS Dashboard</title>
  
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
  <link rel="stylesheet" href="dashboard.css">
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
      <a href="<?= APP_BASE ?>/app/reports/index.php" class="nav-item">
        <i class="fas fa-chart-bar"></i>
        <span>Reports</span>
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
      <div class="welcome-text">
        <h1>Welcome Back!</h1>
        <p>Here's your financial overview for today</p>
      </div>
      <div class="header-actions">
        <button class="btn-icon" title="Notifications">
          <i class="fas fa-bell"></i>
          <span class="notification-badge">3</span>
        </button>
        <button class="btn-icon" title="Settings">
          <i class="fas fa-cog"></i>
        </button>
        <a href="<?= APP_BASE ?>/public/sync.php" class="btn-sync">
          <i class="fas fa-sync-alt"></i>
          <span>Sync Now</span>
        </a>
      </div>
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
          <span class="overview-label">Net Balance</span>
          <span class="overview-amount <?= $net_balance >= 0 ? 'positive' : 'negative' ?>">
            <?= number_format($net_balance, 2) ?>
          </span>
          <div class="overview-trend <?= $net_balance >= 0 ? 'positive' : '' ?>">
            <i class="fas fa-<?= $net_balance >= 0 ? 'check' : 'exclamation' ?>-circle"></i>
            <span><?= $net_balance >= 0 ? 'Healthy' : 'Deficit' ?></span>
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
        <a href="<?= APP_BASE ?>/app/reports/index.php" class="stat-link">
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
            <?php foreach($recent_transactions as $txn): ?>
            <div class="transaction-item <?= strtolower($txn['txn_type']) ?>">
              <div class="txn-icon">
                <i class="fas fa-<?= $txn['txn_type'] === 'INCOME' ? 'arrow-down' : 'arrow-up' ?>"></i>
              </div>
              <div class="txn-details">
                <span class="txn-category"><?= htmlspecialchars($txn['category_name'] ?? 'Uncategorized') ?></span>
                <span class="txn-account"><?= htmlspecialchars($txn['account_name'] ?? 'Unknown') ?></span>
              </div>
              <div class="txn-right">
                <span class="txn-amount"><?= number_format((float)$txn['amount'], 2) ?></span>
                <span class="txn-date"><?= date('M d', strtotime($txn['txn_date'])) ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ✅ Account Balances (live) -->
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
                  // Map your account types to icons
                  $icon = 'wallet';
                  if ($acc['account_type'] === 'BANK')   $icon = 'building-columns';
                  if ($acc['account_type'] === 'CARD')   $icon = 'credit-card';
                  if ($acc['account_type'] === 'CASH')   $icon = 'money-bill-wave';
                  if ($acc['account_type'] === 'MOBILE') $icon = 'mobile-screen';
                ?>
                <i class="fas fa-<?= $icon ?>"></i>
              </div>
              <div class="acc-details">
                <span class="acc-name"><?= htmlspecialchars($acc['account_name']) ?></span>
                <span class="acc-type"><?= htmlspecialchars($acc['account_type']) ?></span>
              </div>
              <!-- show current balance from the view -->
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
            <a href="<?= APP_BASE ?>/app/reports/index.php" class="action-btn action-orange">
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
                <circle cx="60" cy="60" r="54" fill="none" stroke="url(#gradient)" stroke-width="8" 
                        stroke-dasharray="339.292" stroke-dashoffset="<?= 339.292 * (1 - min(1, max(0, $net_balance / ($total_income > 0 ? $total_income : 1)))) ?>" 
                        stroke-linecap="round" transform="rotate(-90 60 60)"/>
                <defs>
                  <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#6366f1;stop-opacity:1" />
                    <stop offset="100%" style="stop-color:#8b5cf6;stop-opacity:1" />
                  </linearGradient>
                </defs>
              </svg>
              <div class="score-text">
                <span class="score-value"><?= $total_income > 0 ? round(($net_balance / $total_income) * 100) : 0 ?>%</span>
                <span class="score-label">Savings Rate</span>
              </div>
            </div>
            <div class="health-tips">
              <div class="tip-item">
                <i class="fas fa-check-circle"></i>
                <span><?= $net_balance >= 0 ? 'Great job!' : 'Need improvement' ?></span>
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
  return date('M Y', strtotime($d['month'] . '-01')); 
}, $monthly_data)) ?>;
const incomeData = <?= json_encode(array_column($monthly_data, 'income')) ?>;
const expenseData = <?= json_encode(array_column($monthly_data, 'expense')) ?>;

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
const categoryLabels = <?= json_encode(array_column($top_categories, 'category_name')) ?>;
const categoryData = <?= json_encode(array_column($top_categories, 'total')) ?>;
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
