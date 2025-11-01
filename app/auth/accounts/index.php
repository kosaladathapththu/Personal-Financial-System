<?php
require __DIR__ . '/../../../config/env.php';
require __DIR__ . '/../../../db/sqlite.php';
require __DIR__ . '/../common/auth_guard.php';

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);

$stmt = $pdo->prepare("
  SELECT local_account_id, account_name, account_type, currency_code,
         current_balance, is_active, created_at, updated_at
  FROM V_ACCOUNT_BALANCES
  WHERE user_local_id = ?
  ORDER BY is_active DESC, created_at DESC
");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Calculate statistics
$total_accounts = count($rows);
$active_accounts = count(array_filter($rows, fn($r) => $r['is_active']));
$inactive_accounts = $total_accounts - $active_accounts;

$total_balance = array_sum(array_map(fn($r) => (float)$r['current_balance'], $rows));



// Group by account type
$type_data = [];
foreach ($rows as $r) {
    $type = $r['account_type'];
    if (!isset($type_data[$type])) {
        $type_data[$type] = ['count' => 0, 'balance' => 0];
    }
    $type_data[$type]['count']++;
    $type_data[$type]['balance'] += (float)$r['current_balance'];
}

// Group by currency
$currency_data = [];
foreach ($rows as $r) {
    $curr = $r['currency_code'];
    if (!isset($currency_data[$curr])) {
        $currency_data[$curr] = ['count' => 0, 'balance' => 0];
    }
    $currency_data[$curr]['count']++;
    $currency_data[$curr]['balance'] += (float)$r['current_balance'];
}


// Recent activity (last 5 accounts)
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
    
    <!-- Top Bar -->
    <div class="top-bar">
      <div class="page-title">
        <h1>Accounts Overview</h1>
        <p>Manage and monitor your financial accounts</p>
      </div>
      <div class="top-actions">
        <button class="btn-icon" title="Refresh">
          <i class="fas fa-sync-alt"></i>
        </button>
        <button class="btn-icon" title="Filter">
          <i class="fas fa-filter"></i>
        </button>
        <a href="<?= APP_BASE ?>/app/auth/accounts/create.php" class="btn-primary">
          <i class="fas fa-plus"></i>
          <span>New Account</span>
        </a>
      </div>
    </div>

    <!-- Quick Stats Grid -->
    <div class="stats-grid">
      <div class="stat-box stat-primary">
        <div class="stat-icon">
          <i class="fas fa-wallet"></i>
        </div>
        <div class="stat-details">
          <span class="stat-label">Total Accounts</span>
          <span class="stat-value"><?= $total_accounts ?></span>
          <span class="stat-change positive"><i class="fas fa-arrow-up"></i> All time</span>
        </div>
      </div>

      <div class="stat-box stat-success">
        <div class="stat-icon">
          <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-details">
          <span class="stat-label">Active Accounts</span>
          <span class="stat-value"><?= $active_accounts ?></span>
          <span class="stat-change positive"><i class="fas fa-check"></i> <?= $total_accounts > 0 ? round(($active_accounts/$total_accounts)*100) : 0 ?>% active</span>
        </div>
      </div>

      <div class="stat-box stat-warning">
        <div class="stat-icon">
          <i class="fas fa-coins"></i>
        </div>
        <div class="stat-details">
          <span class="stat-label">Total Balance</span>
          <span class="stat-value"><?= number_format($total_balance, 2) ?></span>
          <span class="stat-change"><i class="fas fa-money-bill-wave"></i> Combined</span>
        </div>
      </div>

      <div class="stat-box stat-info">
        <div class="stat-icon">
          <i class="fas fa-globe"></i>
        </div>
        <div class="stat-details">
          <span class="stat-label">Currencies</span>
          <span class="stat-value"><?= count($currency_data) ?></span>
          <span class="stat-change"><i class="fas fa-exchange-alt"></i> Multi-currency</span>
        </div>
      </div>
    </div>

    <!-- Charts Section -->
    <?php if ($total_accounts > 0): ?>
    <div class="charts-section">
      <div class="chart-container">
        <div class="chart-header">
          <div>
            <h3>Account Distribution</h3>
            <p>Breakdown by account type</p>
          </div>
          <div class="chart-legend" id="typeLegend"></div>
        </div>
        <div class="chart-body">
          <canvas id="typeChart"></canvas>
        </div>
      </div>

      <div class="chart-container">
        <div class="chart-header">
          <div>
            <h3>Balance Overview</h3>
            <p>Total balance by currency</p>
          </div>
        </div>
        <div class="chart-body">
          <canvas id="currencyChart"></canvas>
        </div>
      </div>

      <div class="chart-container">
        <div class="chart-header">
          <div>
            <h3>Account Status</h3>
            <p>Active vs Inactive ratio</p>
          </div>
        </div>
        <div class="chart-body">
          <canvas id="statusChart"></canvas>
        </div>
      </div>
    </div>

    <!-- Account Type Cards -->
    <div class="type-cards">
      <?php foreach($type_data as $type => $data): ?>
      <div class="type-card">
        <div class="type-card-icon">
          <i class="fas fa-<?= $type === 'Savings' ? 'piggy-bank' : ($type === 'Credit' ? 'credit-card' : 'university') ?>"></i>
        </div>
        <div class="type-card-content">
          <h4><?= htmlspecialchars($type) ?></h4>
          <div class="type-stats">
            <span><?= $data['count'] ?> account<?= $data['count'] != 1 ? 's' : '' ?></span>
            <span class="type-balance"><?= number_format($data['balance'], 2) ?></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Accounts Table -->
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
        <div class="empty-icon">
          <i class="fas fa-wallet"></i>
        </div>
        <h3>No Accounts Found</h3>
        <p>Start managing your finances by creating your first account</p>
        <a href="<?= APP_BASE ?>/app/auth/accounts/create.php" class="btn-primary">
          <i class="fas fa-plus"></i>
          <span>Create First Account</span>
        </a>
      </div>
      <?php else: ?>
      <div class="accounts-grid">
        <?php foreach($rows as $r): ?>
        <div class="account-card" data-account-name="<?= htmlspecialchars($r['account_name']) ?>" data-account-type="<?= htmlspecialchars($r['account_type']) ?>" data-currency="<?= htmlspecialchars($r['currency_code']) ?>">
          <div class="account-header">
            <div class="account-icon-wrapper">
              <i class="fas fa-wallet"></i>
            </div>
            <div class="account-status">
              <?php if ($r['is_active']): ?>
                <span class="badge badge-success"><i class="fas fa-circle"></i> Active</span>
              <?php else: ?>
                <span class="badge badge-inactive"><i class="fas fa-circle"></i> Inactive</span>
              <?php endif; ?>
            </div>
          </div>
          
          <div class="account-body">
            <h3><?= htmlspecialchars($r['account_name']) ?></h3>
            <div class="account-meta">
              <span class="account-type">
                <i class="fas fa-tag"></i>
                <?= htmlspecialchars($r['account_type']) ?>
              </span>
              <span class="account-currency">
                <i class="fas fa-dollar-sign"></i>
                <?= htmlspecialchars($r['currency_code']) ?>
              </span>
            </div>
            <div class="account-balance">
              <span class="balance-label">Current Balance</span>
              <span class="balance-amount"><?= number_format((float)$r['current_balance'], 2) ?></span>

            </div>
          </div>
          
          <div class="account-footer">
            <a href="<?= APP_BASE ?>/app/auth/accounts/edit.php?id=<?= (int)$r['local_account_id'] ?>" class="action-btn">
              <i class="fas fa-edit"></i>
              <span>Edit</span>
            </a>
            <a href="<?= APP_BASE ?>/app/auth/accounts/toggle.php?id=<?= (int)$r['local_account_id'] ?>" class="action-btn">
              <i class="fas fa-<?= $r['is_active'] ? 'toggle-on' : 'toggle-off' ?>"></i>
              <span><?= $r['is_active'] ? 'Disable' : 'Enable' ?></span>
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
// Search functionality
document.getElementById('searchInput')?.addEventListener('input', function(e) {
  const search = e.target.value.toLowerCase();
  document.querySelectorAll('.account-card').forEach(card => {
    const name = card.dataset.accountName.toLowerCase();
    const type = card.dataset.accountType.toLowerCase();
    const currency = card.dataset.currency.toLowerCase();
    card.style.display = (name.includes(search) || type.includes(search) || currency.includes(search)) ? '' : 'none';
  });
});

<?php if ($total_accounts > 0): ?>
// Chart configuration
const chartConfig = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: 'rgba(15, 23, 42, 0.95)',
      padding: 12,
      borderRadius: 8,
      titleFont: { size: 14, weight: 'bold' },
      bodyFont: { size: 13 }
    }
  }
};

// Color palette
const colors = {
  primary: '#6366f1',
  success: '#10b981',
  warning: '#f59e0b',
  danger: '#ef4444',
  info: '#3b82f6',
  purple: '#8b5cf6',
  pink: '#ec4899',
  teal: '#14b8a6'
};

const gradients = {
  primary: ['#818cf8', '#6366f1'],
  success: ['#34d399', '#10b981'],
  warning: ['#fbbf24', '#f59e0b'],
  danger: ['#f87171', '#ef4444']
};

// Type Distribution Chart (Doughnut)
const typeData = <?= json_encode(array_map(fn($d) => $d['count'], $type_data)) ?>;
const typeLabels = <?= json_encode(array_keys($type_data)) ?>;

new Chart(document.getElementById('typeChart'), {
  type: 'doughnut',
  data: {
    labels: typeLabels,
    datasets: [{
      data: typeData,
      backgroundColor: [colors.primary, colors.success, colors.warning, colors.danger, colors.purple],
      borderWidth: 0,
      borderRadius: 8,
      spacing: 4
    }]
  },
  options: {
    ...chartConfig,
    cutout: '70%',
    plugins: {
      ...chartConfig.plugins,
      tooltip: {
        ...chartConfig.plugins.tooltip,
        callbacks: {
          label: (ctx) => ` ${ctx.label}: ${ctx.parsed} accounts`
        }
      }
    }
  }
});

// Currency Balance Chart (Bar)
const currencyBalances = <?= json_encode(array_map(fn($d) => $d['balance'], $currency_data)) ?>;
const currencyLabels = <?= json_encode(array_keys($currency_data)) ?>;

new Chart(document.getElementById('currencyChart'), {
  type: 'bar',
  data: {
    labels: currencyLabels,
    datasets: [{
      data: currencyBalances,
      backgroundColor: colors.success,
      borderRadius: 8,
      barThickness: 40
    }]
  },
  options: {
    ...chartConfig,
    scales: {
      y: {
        beginAtZero: true,
        grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false },
        ticks: { font: { size: 11 }, callback: val => val.toFixed(0) }
      },
      x: {
        grid: { display: false, drawBorder: false },
        ticks: { font: { size: 11 } }
      }
    }
  }
});

// Status Chart (Pie)
new Chart(document.getElementById('statusChart'), {
  type: 'pie',
  data: {
    labels: ['Active', 'Inactive'],
    datasets: [{
      data: [<?= $active_accounts ?>, <?= $inactive_accounts ?>],
      backgroundColor: [colors.success, colors.danger],
      borderWidth: 0
    }]
  },
  options: {
    ...chartConfig,
    plugins: {
      ...chartConfig.plugins,
      tooltip: {
        ...chartConfig.plugins.tooltip,
        callbacks: {
          label: (ctx) => ` ${ctx.label}: ${ctx.parsed} accounts`
        }
      }
    }
  }
});
<?php endif; ?>
</script>

</body>
</html>