<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../auth/common/auth_guard.php';

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);

$typeFilter = $_GET['type'] ?? 'ALL';

$sql = "
  SELECT local_category_id, category_name, category_type, parent_local_id, created_at, updated_at
  FROM CATEGORIES_LOCAL
  WHERE user_local_id = ?
";
$params = [$uid];

if ($typeFilter === 'INCOME' || $typeFilter === 'EXPENSE') {
  $sql .= " AND category_type = ?";
  $params[] = $typeFilter;
}

$sql .= " ORDER BY category_type, category_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_categories = count($rows);
$income_categories = count(array_filter($rows, fn($r) => $r['category_type'] === 'INCOME'));
$expense_categories = count(array_filter($rows, fn($r) => $r['category_type'] === 'EXPENSE'));
$parent_categories = count(array_filter($rows, fn($r) => $r['parent_local_id'] === null));
$sub_categories = $total_categories - $parent_categories;

// Group by type for visualization
$income_cats = array_filter($rows, fn($r) => $r['category_type'] === 'INCOME');
$expense_cats = array_filter($rows, fn($r) => $r['category_type'] === 'EXPENSE');

// Get top categories
$top_categories = array_slice($rows, 0, 6);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Categories Management - PFMS</title>
  
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
      <a href="<?= APP_BASE ?>/app/categories/index.php" class="nav-item active">
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
        <h1>Categories Management</h1>
        <p>Organize your income and expense categories</p>
      </div>
      <div class="top-actions">
        <button class="btn-icon" title="Refresh" onclick="location.reload()">
          <i class="fas fa-sync-alt"></i>
        </button>
        <button class="btn-icon" title="Export">
          <i class="fas fa-download"></i>
        </button>
        <a href="<?= APP_BASE ?>/app/categories/create.php" class="btn-primary">
          <i class="fas fa-plus"></i>
          <span>New Category</span>
        </a>
      </div>
    </div>

    <!-- Quick Stats Grid -->
    <div class="stats-grid">
      <div class="stat-box stat-primary">
        <div class="stat-icon">
          <i class="fas fa-tags"></i>
        </div>
        <div class="stat-details">
          <span class="stat-label">Total Categories</span>
          <span class="stat-value"><?= $total_categories ?></span>
          <span class="stat-change"><i class="fas fa-layer-group"></i> All categories</span>
        </div>
      </div>

      <div class="stat-box stat-success">
        <div class="stat-icon">
          <i class="fas fa-arrow-down"></i>
        </div>
        <div class="stat-details">
          <span class="stat-label">Income Categories</span>
          <span class="stat-value"><?= $income_categories ?></span>
          <span class="stat-change positive"><i class="fas fa-plus-circle"></i> Revenue sources</span>
        </div>
      </div>

      <div class="stat-box stat-danger">
        <div class="stat-icon">
          <i class="fas fa-arrow-up"></i>
        </div>
        <div class="stat-details">
          <span class="stat-label">Expense Categories</span>
          <span class="stat-value"><?= $expense_categories ?></span>
          <span class="stat-change"><i class="fas fa-minus-circle"></i> Spending areas</span>
        </div>
      </div>

      <div class="stat-box stat-info">
        <div class="stat-icon">
          <i class="fas fa-sitemap"></i>
        </div>
        <div class="stat-details">
          <span class="stat-label">Sub Categories</span>
          <span class="stat-value"><?= $sub_categories ?></span>
          <span class="stat-change"><i class="fas fa-network-wired"></i> Nested items</span>
        </div>
      </div>
    </div>

    <!-- Charts and Insights -->
    <?php if ($total_categories > 0): ?>
    <div class="insights-section">
      <div class="chart-container">
        <div class="chart-header">
          <div>
            <h3>Category Distribution</h3>
            <p>Income vs Expense breakdown</p>
          </div>
        </div>
        <div class="chart-body">
          <canvas id="distributionChart"></canvas>
        </div>
      </div>

      <div class="chart-container">
        <div class="chart-header">
          <div>
            <h3>Category Hierarchy</h3>
            <p>Parent vs Sub-categories</p>
          </div>
        </div>
        <div class="chart-body">
          <canvas id="hierarchyChart"></canvas>
        </div>
      </div>

      <div class="popular-categories">
        <div class="chart-header">
          <div>
            <h3>Top Categories</h3>
            <p>Most recently created</p>
          </div>
        </div>
        <div class="popular-list">
          <?php foreach($top_categories as $cat): ?>
          <div class="popular-item">
            <div class="popular-icon <?= strtolower($cat['category_type']) ?>">
              <i class="fas fa-<?= $cat['category_type'] === 'INCOME' ? 'arrow-down' : 'arrow-up' ?>"></i>
            </div>
            <div class="popular-content">
              <span class="popular-name"><?= htmlspecialchars($cat['category_name']) ?></span>
              <span class="popular-type"><?= $cat['category_type'] ?></span>
            </div>
            <span class="badge badge-<?= strtolower($cat['category_type']) ?>">
              <?= $cat['parent_local_id'] ? 'Sub' : 'Parent' ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="filter-section">
      <div class="filter-header">
        <h3>Filter Categories</h3>
        <div class="filter-tabs">
          <a href="?type=ALL" class="filter-tab <?= $typeFilter === 'ALL' ? 'active' : '' ?>">
            <i class="fas fa-th"></i> All (<?= $total_categories ?>)
          </a>
          <a href="?type=INCOME" class="filter-tab <?= $typeFilter === 'INCOME' ? 'active' : '' ?>">
            <i class="fas fa-arrow-down"></i> Income (<?= $income_categories ?>)
          </a>
          <a href="?type=EXPENSE" class="filter-tab <?= $typeFilter === 'EXPENSE' ? 'active' : '' ?>">
            <i class="fas fa-arrow-up"></i> Expense (<?= $expense_categories ?>)
          </a>
        </div>
      </div>
    </div>

    <!-- Categories Grid -->
    <div class="table-section">
      <div class="section-header">
        <div>
          <h2><?= $typeFilter === 'ALL' ? 'All' : $typeFilter ?> Categories</h2>
          <p>Showing <?= count($rows) ?> categories</p>
        </div>
        <div class="search-container">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search categories...">
        </div>
      </div>

      <?php if (empty($rows)): ?>
      <div class="empty-state">
        <div class="empty-icon">
          <i class="fas fa-tags"></i>
        </div>
        <h3>No Categories Found</h3>
        <p>Start organizing your finances by creating categories</p>
        <a href="<?= APP_BASE ?>/app/categories/create.php" class="btn-primary">
          <i class="fas fa-plus"></i>
          <span>Create First Category</span>
        </a>
      </div>
      <?php else: ?>
      <div class="categories-grid">
        <?php foreach($rows as $r): ?>
        <div class="category-card" 
             data-name="<?= htmlspecialchars($r['category_name']) ?>" 
             data-type="<?= htmlspecialchars($r['category_type']) ?>">
          <div class="category-header">
            <div class="category-icon-wrapper <?= strtolower($r['category_type']) ?>">
              <i class="fas fa-<?= $r['category_type'] === 'INCOME' ? 'arrow-down' : 'arrow-up' ?>"></i>
            </div>
            <div class="category-badges">
              <span class="badge badge-<?= strtolower($r['category_type']) ?>">
                <?= $r['category_type'] ?>
              </span>
              <?php if ($r['parent_local_id']): ?>
              <span class="badge badge-sub">
                <i class="fas fa-level-down-alt"></i> Sub
              </span>
              <?php endif; ?>
            </div>
          </div>
          
          <div class="category-body">
            <h3><?= htmlspecialchars($r['category_name']) ?></h3>
            <div class="category-meta">
              <div class="meta-item">
                <i class="fas fa-hashtag"></i>
                <span>ID: <?= (int)$r['local_category_id'] ?></span>
              </div>
              <?php if ($r['parent_local_id']): ?>
              <div class="meta-item">
                <i class="fas fa-sitemap"></i>
                <span>Parent: <?= (int)$r['parent_local_id'] ?></span>
              </div>
              <?php endif; ?>
            </div>
            <div class="category-dates">
              <div class="date-item">
                <i class="fas fa-calendar-plus"></i>
                <span><?= date('M d, Y', strtotime($r['created_at'])) ?></span>
              </div>
              <div class="date-item">
                <i class="fas fa-calendar-check"></i>
                <span><?= date('M d, Y', strtotime($r['updated_at'])) ?></span>
              </div>
            </div>
          </div>
          
          <div class="category-footer">
            <a href="<?= APP_BASE ?>/app/categories/edit.php?id=<?= (int)$r['local_category_id'] ?>" 
               class="action-btn action-edit">
              <i class="fas fa-edit"></i>
              <span>Edit</span>
            </a>
            <a href="<?= APP_BASE ?>/app/categories/delete.php?id=<?= (int)$r['local_category_id'] ?>" 
               class="action-btn action-delete"
               onclick="return confirm('Delete <?= htmlspecialchars($r['category_name']) ?>?')">
              <i class="fas fa-trash"></i>
              <span>Delete</span>
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
  document.querySelectorAll('.category-card').forEach(card => {
    const name = card.dataset.name.toLowerCase();
    const type = card.dataset.type.toLowerCase();
    card.style.display = (name.includes(search) || type.includes(search)) ? '' : 'none';
  });
});

<?php if ($total_categories > 0): ?>
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

// Distribution Chart
new Chart(document.getElementById('distributionChart'), {
  type: 'doughnut',
  data: {
    labels: ['Income', 'Expense'],
    datasets: [{
      data: [<?= $income_categories ?>, <?= $expense_categories ?>],
      backgroundColor: ['#10b981', '#ef4444'],
      borderWidth: 0,
      borderRadius: 8,
      spacing: 4
    }]
  },
  options: {
    ...chartConfig,
    cutout: '70%'
  }
});

// Hierarchy Chart
new Chart(document.getElementById('hierarchyChart'), {
  type: 'bar',
  data: {
    labels: ['Parent', 'Sub-categories'],
    datasets: [{
      label: 'Count',
      data: [<?= $parent_categories ?>, <?= $sub_categories ?>],
      backgroundColor: ['#6366f1', '#8b5cf6'],
      borderRadius: 8,
      barThickness: 50
    }]
  },
  options: {
    ...chartConfig,
    plugins: {
      ...chartConfig.plugins,
      legend: { display: false }
    },
    scales: {
      y: {
        beginAtZero: true,
        grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false },
        ticks: { font: { size: 11 }, stepSize: 1 }
      },
      x: {
        grid: { display: false, drawBorder: false },
        ticks: { font: { size: 11 } }
      }
    }
  }
});
<?php endif; ?>
</script>

</body>
</html>