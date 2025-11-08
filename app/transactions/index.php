<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../auth/common/auth_guard.php'; 
require __DIR__ . '/../auth/common/util.php';       

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);

// Filters
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';
$acc  = $_GET['account'] ?? '';
$cat  = $_GET['category'] ?? '';
$typ  = $_GET['type'] ?? '';

// Dropdown data
$accounts = $pdo->prepare("
  SELECT local_account_id, account_name
  FROM ACCOUNTS_LOCAL
  WHERE user_local_id=? AND is_active=1
  ORDER BY account_name
");
$accounts->execute([$uid]);
$accounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->prepare("
  SELECT local_category_id, category_name
  FROM CATEGORIES_LOCAL
  WHERE user_local_id=?
  ORDER BY category_type, category_name
");
$categories->execute([$uid]);
$categories = $categories->fetchAll(PDO::FETCH_ASSOC);

// Build WHERE
$where = ["t.user_local_id = ?"];
$args  = [$uid];

if ($from !== '') { $where[] = "date(t.txn_date) >= date(?)"; $args[] = $from; }
if ($to   !== '') { $where[] = "date(t.txn_date) <= date(?)"; $args[] = $to; }
if ($acc  !== '') { $where[] = "t.account_local_id = ?";      $args[] = (int)$acc; }
if ($cat  !== '') { $where[] = "t.category_local_id = ?";     $args[] = (int)$cat; }
if ($typ  !== '' && in_array($typ, ['INCOME','EXPENSE'], true)) {
  $where[] = "t.txn_type = ?"; $args[] = $typ;
}

// Fetch transactions
$sql = "
SELECT
  t.local_txn_id, t.client_txn_uuid, t.txn_type, t.amount, t.txn_date, t.note, t.sync_status,
  a.account_name,
  c.category_name
FROM TRANSACTIONS_LOCAL t
JOIN ACCOUNTS_LOCAL a ON a.local_account_id = t.account_local_id
JOIN CATEGORIES_LOCAL c ON c.local_category_id = t.category_local_id
WHERE " . implode(' AND ', $where) . "
ORDER BY datetime(t.txn_date) DESC, t.local_txn_id DESC
LIMIT 200
";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals
$totSql = "
SELECT
  SUM(CASE WHEN t.txn_type='INCOME'  THEN t.amount ELSE 0 END) AS total_income,
  SUM(CASE WHEN t.txn_type='EXPENSE' THEN t.amount ELSE 0 END) AS total_expense
FROM TRANSACTIONS_LOCAL t
WHERE " . implode(' AND ', $where) . "
";
$sum = $pdo->prepare($totSql);
$sum->execute($args);
$tot = $sum->fetch(PDO::FETCH_ASSOC);
$income  = (float)($tot['total_income'] ?? 0);
$expense = (float)($tot['total_expense'] ?? 0);
$net     = $income - $expense;

// Additional stats
$total_transactions = count($rows);
$synced = count(array_filter($rows, fn($r) => $r['sync_status'] === 'SYNCED'));
$pending = $total_transactions - $synced;

// Group by month for trend
$monthly_data = [];
foreach($rows as $r) {
    $month = date('M Y', strtotime($r['txn_date']));
    if (!isset($monthly_data[$month])) {
        $monthly_data[$month] = ['income' => 0, 'expense' => 0];
    }
    if ($r['txn_type'] === 'INCOME') {
        $monthly_data[$month]['income'] += (float)$r['amount'];
    } else {
        $monthly_data[$month]['expense'] += (float)$r['amount'];
    }
}
$monthly_data = array_slice($monthly_data, 0, 6, true);

// Recent transactions for quick view
$recent = array_slice($rows, 0, 5);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Transactions - PFMS</title>
  
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
      <a href="<?= APP_BASE ?>/app/auth/accounts/index.php" class="nav-item">
        <i class="fas fa-wallet"></i>
        <span>Accounts</span>
      </a>
      <a href="<?= APP_BASE ?>/app/categories/index.php" class="nav-item">
        <i class="fas fa-tags"></i>
        <span>Categories</span>
      </a>
      <a href="<?= APP_BASE ?>/app/transactions/index.php" class="nav-item active">
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
    
    <!-- Top Bar -->
    <div class="top-bar">
      <div class="page-title">
        <h1>Transactions</h1>
        <p>Track and manage your financial transactions</p>
      </div>
      <div class="top-actions">
        <button class="btn-icon" title="Refresh" onclick="location.reload()">
          <i class="fas fa-sync-alt"></i>
        </button>
        <button class="btn-icon" title="Export">
          <i class="fas fa-download"></i>
        </button>
        <a href="<?= APP_BASE ?>/app/transactions/create.php" class="btn-primary">
          <i class="fas fa-plus"></i>
          <span>New Transaction</span>
        </a>
      </div>
    </div>

    <!-- Financial Overview Stats -->
    <div class="financial-overview">
      <div class="overview-card income-card">
        <div class="overview-icon">
          <i class="fas fa-arrow-down"></i>
        </div>
        <div class="overview-content">
          <span class="overview-label">Total Income</span>
          <span class="overview-amount"><?= number_format($income, 2) ?></span>
          <div class="overview-bar">
            <div class="bar-fill income-fill" style="width: <?= $income > 0 ? '100' : '0' ?>%"></div>
          </div>
        </div>
      </div>

      <div class="overview-card expense-card">
        <div class="overview-icon">
          <i class="fas fa-arrow-up"></i>
        </div>
        <div class="overview-content">
          <span class="overview-label">Total Expense</span>
          <span class="overview-amount"><?= number_format($expense, 2) ?></span>
          <div class="overview-bar">
            <div class="bar-fill expense-fill" style="width: <?= $expense > 0 ? '100' : '0' ?>%"></div>
          </div>
        </div>
      </div>

      <div class="overview-card net-card">
        <div class="overview-icon">
          <i class="fas fa-balance-scale"></i>
        </div>
        <div class="overview-content">
          <span class="overview-label">Net Balance</span>
          <span class="overview-amount <?= $net >= 0 ? 'positive' : 'negative' ?>"><?= number_format($net, 2) ?></span>
          <span class="overview-subtitle"><?= $net >= 0 ? 'Surplus' : 'Deficit' ?></span>
        </div>
      </div>
    </div>

    <!-- Quick Stats -->
    <div class="stats-grid">
      <div class="stat-box stat-primary">
        <div class="stat-icon">
          <i class="fas fa-receipt"></i>
        </div>
        <div class="stat-details">
          <span class="stat-label">Total Transactions</span>
          <span class="stat-value"><?= $total_transactions ?></span>
          <span class="stat-change"><i class="fas fa-list"></i> Showing latest</span>
        </div>
      </div>

      <div class="stat-box stat-success">
        <div class="stat-icon">
          <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-details">
          <span class="stat-label">Synced</span>
          <span class="stat-value"><?= $synced ?></span>
          <span class="stat-change positive"><i class="fas fa-cloud-upload-alt"></i> Up to date</span>
        </div>
      </div>

      <div class="stat-box stat-warning">
        <div class="stat-icon">
          <i class="fas fa-clock"></i>
        </div>
        <div class="stat-details">
          <span class="stat-label">Pending Sync</span>
          <span class="stat-value"><?= $pending ?></span>
          <span class="stat-change"><i class="fas fa-hourglass-half"></i> Awaiting sync</span>
        </div>
      </div>

      <div class="stat-box stat-info">
        <div class="stat-icon">
          <i class="fas fa-calendar-alt"></i>
        </div>
        <div class="stat-details">
          <span class="stat-label">Period</span>
          <span class="stat-value"><?= $from && $to ? date('M d', strtotime($from)) . ' - ' . date('M d', strtotime($to)) : 'All Time' ?></span>
          <span class="stat-change"><i class="fas fa-filter"></i> Date range</span>
        </div>
      </div>
    </div>

    <!-- Charts Section -->
    <?php if ($total_transactions > 0): ?>
    <div class="charts-section">
      <div class="chart-container chart-large">
        <div class="chart-header">
          <div>
            <h3>Income vs Expense Trend</h3>
            <p>Monthly comparison over time</p>
          </div>
        </div>
        <div class="chart-body">
          <canvas id="trendChart"></canvas>
        </div>
      </div>

      <div class="chart-container">
        <div class="chart-header">
          <div>
            <h3>Transaction Types</h3>
            <p>Income vs Expense distribution</p>
          </div>
        </div>
        <div class="chart-body">
          <canvas id="typeChart"></canvas>
        </div>
      </div>
    </div>

    <!-- Recent Transactions Preview -->
    <div class="recent-section">
      <div class="section-header">
        <h3>Recent Transactions</h3>
        <p>Latest 5 transactions</p>
      </div>
      <div class="recent-list">
        <?php foreach($recent as $txn): ?>
        <div class="recent-item <?= strtolower($txn['txn_type']) ?>">
          <div class="recent-icon">
            <i class="fas fa-<?= $txn['txn_type'] === 'INCOME' ? 'arrow-down' : 'arrow-up' ?>"></i>
          </div>
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

    <!-- Advanced Filters -->
    <div class="filter-section">
      <div class="filter-header">
        <h3><i class="fas fa-filter"></i> Advanced Filters</h3>
        <button class="btn-reset" onclick="location.href='<?= APP_BASE ?>/app/transactions/index.php'">
          <i class="fas fa-redo"></i> Reset All
        </button>
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
              <option value="INCOME" <?= $typ==='INCOME'?'selected':'' ?>>Income</option>
              <option value="EXPENSE" <?= $typ==='EXPENSE'?'selected':'' ?>>Expense</option>
            </select>
          </div>
          
          <div class="filter-group">
            <label><i class="fas fa-wallet"></i> Account</label>
            <select name="account">
              <option value="">All Accounts</option>
              <?php foreach($accounts as $a): ?>
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
              <?php foreach($categories as $c): ?>
                <option value="<?= (int)$c['local_category_id'] ?>" <?= $cat==$c['local_category_id']?'selected':'' ?>>
                  <?= h($c['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="filter-actions">
            <button type="submit" class="btn-filter">
              <i class="fas fa-search"></i> Apply Filters
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- Transactions Table -->
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
        <div class="empty-icon">
          <i class="fas fa-receipt"></i>
        </div>
        <h3>No Transactions Found</h3>
        <p>Start tracking your finances by adding your first transaction</p>
        <a href="<?= APP_BASE ?>/app/transactions/create.php" class="btn-primary">
          <i class="fas fa-plus"></i>
          <span>Add First Transaction</span>
        </a>
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
            <tr data-search="<?= h(strtolower($r['account_name'] . ' ' . $r['category_name'] . ' ' . $r['note'] . ' ' . $r['txn_type'])) ?>">
              <td>
                <div class="date-cell">
                  <i class="fas fa-calendar-day"></i>
                  <?= date('M d, Y', strtotime($r['txn_date'])) ?>
                </div>
              </td>
              <td>
                <span class="badge badge-<?= strtolower($r['txn_type']) ?>">
                  <i class="fas fa-<?= $r['txn_type'] === 'INCOME' ? 'arrow-down' : 'arrow-up' ?>"></i>
                  <?= h($r['txn_type']) ?>
                </span>
              </td>
              <td>
                <span class="amount-cell <?= strtolower($r['txn_type']) ?>">
                  <?= number_format((float)$r['amount'], 2) ?>
                </span>
              </td>
              <td>
                <div class="account-cell">
                  <i class="fas fa-wallet"></i>
                  <?= h($r['account_name']) ?>
                </div>
              </td>
              <td>
                <div class="category-cell">
                  <i class="fas fa-tag"></i>
                  <?= h($r['category_name']) ?>
                </div>
              </td>
              <td class="note-cell"><?= h($r['note']) ?: '-' ?></td>
              <td>
                <span class="sync-badge <?= strtolower($r['sync_status']) ?>">
                  <i class="fas fa-<?= $r['sync_status'] === 'SYNCED' ? 'check-circle' : 'clock' ?>"></i>
                  <?= h($r['sync_status']) ?>
                </span>
              </td>
              <td>
                <div class="action-buttons">
                  <a href="<?= APP_BASE ?>/app/transactions/edit.php?id=<?= (int)$r['local_txn_id'] ?>" 
                     class="btn-icon-small" title="Edit">
                    <i class="fas fa-edit"></i>
                  </a>
                  <a href="<?= APP_BASE ?>/app/transactions/delete.php?id=<?= (int)$r['local_txn_id'] ?>" 
                     class="btn-icon-small delete" 
                     title="Delete"
                     onclick="return confirm('Delete this transaction?')">
                    <i class="fas fa-trash"></i>
                  </a>
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
// Search functionality
document.getElementById('searchInput')?.addEventListener('input', function(e) {
  const search = e.target.value.toLowerCase();
  document.querySelectorAll('#transactionsBody tr').forEach(row => {
    const searchText = row.dataset.search;
    row.style.display = searchText.includes(search) ? '' : 'none';
  });
});

<?php if ($total_transactions > 0 && !empty($monthly_data)): ?>
// Chart configuration
const chartConfig = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      display: true,
      position: 'bottom',
      labels: { padding: 15, font: { size: 12, weight: '600' } }
    },
    tooltip: {
      backgroundColor: 'rgba(15, 23, 42, 0.95)',
      padding: 12,
      borderRadius: 8,
      titleFont: { size: 14, weight: 'bold' },
      bodyFont: { size: 13 }
    }
  }
};

// Trend Chart
const monthlyLabels = <?= json_encode(array_keys($monthly_data)) ?>;
const incomeData = <?= json_encode(array_column($monthly_data, 'income')) ?>;
const expenseData = <?= json_encode(array_column($monthly_data, 'expense')) ?>;

new Chart(document.getElementById('trendChart'), {
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
        borderWidth: 3
      },
      {
        label: 'Expense',
        data: expenseData,
        borderColor: '#ef4444',
        backgroundColor: 'rgba(239, 68, 68, 0.1)',
        tension: 0.4,
        fill: true,
        borderWidth: 3
      }
    ]
  },
  options: {
    ...chartConfig,
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

// Type Distribution Chart
new Chart(document.getElementById('typeChart'), {
  type: 'doughnut',
  data: {
    labels: ['Income', 'Expense'],
    datasets: [{
      data: [<?= $income ?>, <?= $expense ?>],
      backgroundColor: ['#10b981', '#ef4444'],
      borderWidth: 0,
      borderRadius: 8,
      spacing: 4
    }]
  },
  options: {
    ...chartConfig,
    cutout: '65%'
  }
});
<?php endif; ?>
</script>

</body>
</html>