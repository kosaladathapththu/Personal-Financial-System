<?php
// app/categories/index.php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../../db/oracle.php';
require __DIR__ . '/../auth/common/auth_guard.php';

function h($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }
function arr_keys_lower(array $r): array {
    $o = [];
    foreach ($r as $k=>$v) $o[strtolower((string)$k)] = $v;
    return $o;
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);
$typeFilter = strtoupper(trim($_GET['type'] ?? 'ALL'));
if (!in_array($typeFilter, ['ALL','INCOME','EXPENSE'], true)) $typeFilter = 'ALL';

// 1) Resolve local → server user id (for Oracle)
$mapStmt = $pdo->prepare("SELECT server_user_id FROM USERS_LOCAL WHERE local_user_id = ?");
$mapStmt->execute([$uid]);
$serverUid = (int)($mapStmt->fetchColumn() ?: 0);

// 2) Try Oracle (and validate connection)
$oconn = @oracle_conn();
$use_oracle = false;
if ($oconn && $serverUid > 0) {
    $chk = @oci_parse($oconn, "SELECT 1 FROM DUAL");
    if ($chk && @oci_execute($chk)) $use_oracle = true;
}

// 3) Fetch categories (Oracle-first, SQLite fallback)
$rows = [];

if ($use_oracle) {
    // NOTE: We alias server ids to match existing UI keys:
    // local_category_id ← server_category_id
    // parent_local_id   ← parent_server_id
    $sql = "
        SELECT
            c.server_category_id       AS local_category_id,
            c.category_name            AS category_name,
            c.category_type            AS category_type,
            c.parent_server_id         AS parent_local_id,
            c.created_at               AS created_at,
            c.updated_at               AS updated_at
        FROM CATEGORIES_CLOUD c
        WHERE c.user_server_id = :P_UID
          " . ($typeFilter !== 'ALL' ? "AND UPPER(c.category_type) = :P_TYPE" : "") . "
        ORDER BY c.category_type, c.category_name
    ";
    $st = oci_parse($oconn, $sql);
    oci_bind_by_name($st, ':P_UID', $serverUid, -1, SQLT_INT);
    if ($typeFilter !== 'ALL') {
        $t = $typeFilter; // keep a var to bind by reference
        oci_bind_by_name($st, ':P_TYPE', $t, -1, SQLT_CHR);
    }
    oci_execute($st);
    while ($r = oci_fetch_assoc($st)) $rows[] = arr_keys_lower($r);

} else {
    // SQLite fallback (your original logic against local tables)
    $params = [$uid];
    $sql = "
      SELECT local_category_id, category_name, category_type, parent_local_id, created_at, updated_at
      FROM CATEGORIES_LOCAL
      WHERE user_local_id = ?
    ";
    if ($typeFilter !== 'ALL') {
        $sql .= " AND category_type = ?";
        $params[] = $typeFilter;
    }
    $sql .= " ORDER BY category_type, category_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 4) Stats & groupings (works for both sources)
$total_categories  = count($rows);
$income_categories = count(array_filter($rows, fn($r) => strtoupper($r['category_type']) === 'INCOME'));
$expense_categories= count(array_filter($rows, fn($r) => strtoupper($r['category_type']) === 'EXPENSE'));
$parent_categories = count(array_filter($rows, fn($r) => empty($r['parent_local_id'])));
$sub_categories    = $total_categories - $parent_categories;

// For “Top Categories” (recent ones): sort by created_at desc if available
$rows_sorted = $rows;
usort($rows_sorted, function($a,$b){
    $da = isset($a['created_at']) ? strtotime((string)$a['created_at']) : 0;
    $db = isset($b['created_at']) ? strtotime((string)$b['created_at']) : 0;
    return $db <=> $da;
});
$top_categories = array_slice($rows_sorted, 0, 6);

// For charts
$show_insights = $total_categories > 0;
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
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="logo"><i class="fas fa-chart-line"></i><span>PFMS</span></div>
    <nav class="nav-menu">
      <a href="<?= APP_BASE ?>/public/dashboard.php" class="nav-item"><i class="fas fa-home"></i><span>Dashboard</span></a>
      <a href="<?= APP_BASE ?>/app/auth/accounts/index.php" class="nav-item"><i class="fas fa-wallet"></i><span>Accounts</span></a>
      <a href="<?= APP_BASE ?>/app/categories/index.php" class="nav-item active"><i class="fas fa-tags"></i><span>Categories</span></a>
      <a href="<?= APP_BASE ?>/app/transactions/index.php" class="nav-item"><i class="fas fa-exchange-alt"></i><span>Transactions</span></a>
      <a href="<?= APP_BASE ?>/app/reports/index_oracle.php" class="nav-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
      <a href="<?= APP_BASE ?>/public/sync.php" class="nav-item"><i class="fas fa-sync-alt"></i><span>Sync</span></a>
    </nav>
    <div class="sidebar-footer">
      <a href="<?= APP_BASE ?>/public/logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
    </div>
  </aside>

  <!-- Main -->
  <main class="main-content">
    <!-- Top Bar -->
    <div class="top-bar">
      <div class="page-title">
        <h1>Categories Management</h1>
        <p>Organize your income and expense categories</p>
      </div>
      <div class="top-actions">
        <a href="<?= APP_BASE ?>/public/sync.php" class="btn-icon" title="Sync Now"><i class="fas fa-sync-alt"></i></a>
        <a href="<?= APP_BASE ?>/app/categories/create.php" class="btn-primary"><i class="fas fa-plus"></i><span>New Category</span></a>
      </div>
    </div>

    <!-- Quick Stats -->
    <div class="stats-grid">
      <div class="stat-box stat-primary">
        <div class="stat-icon"><i class="fas fa-tags"></i></div>
        <div class="stat-details">
          <span class="stat-label">Total Categories</span>
          <span class="stat-value"><?= $total_categories ?></span>
          <span class="stat-change"><i class="fas fa-layer-group"></i> All</span>
        </div>
      </div>
      <div class="stat-box stat-success">
        <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
        <div class="stat-details">
          <span class="stat-label">Income</span>
          <span class="stat-value"><?= $income_categories ?></span>
        </div>
      </div>
      <div class="stat-box stat-danger">
        <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
        <div class="stat-details">
          <span class="stat-label">Expense</span>
          <span class="stat-value"><?= $expense_categories ?></span>
        </div>
      </div>
      <div class="stat-box stat-info">
        <div class="stat-icon"><i class="fas fa-sitemap"></i></div>
        <div class="stat-details">
          <span class="stat-label">Sub Categories</span>
          <span class="stat-value"><?= $sub_categories ?></span>
        </div>
      </div>
    </div>

    <!-- Insights -->
    <?php if ($show_insights): ?>
    <div class="insights-section">
      <div class="chart-container">
        <div class="chart-header"><div><h3>Category Distribution</h3><p>Income vs Expense</p></div></div>
        <div class="chart-body"><canvas id="distributionChart"></canvas></div>
      </div>

      <div class="chart-container">
        <div class="chart-header"><div><h3>Category Hierarchy</h3><p>Parent vs Sub-categories</p></div></div>
        <div class="chart-body"><canvas id="hierarchyChart"></canvas></div>
      </div>

      <div class="popular-categories">
        <div class="chart-header"><div><h3>Top Categories</h3><p>Most recently created</p></div></div>
        <div class="popular-list">
          <?php foreach($top_categories as $cat): ?>
          <div class="popular-item">
            <div class="popular-icon <?= strtolower($cat['category_type']) ?>">
              <i class="fas fa-<?= strtoupper($cat['category_type'])==='INCOME' ? 'arrow-down' : 'arrow-up' ?>"></i>
            </div>
            <div class="popular-content">
              <span class="popular-name"><?= h($cat['category_name']) ?></span>
              <span class="popular-type"><?= h($cat['category_type']) ?></span>
            </div>
            <span class="badge badge-<?= strtolower($cat['category_type']) ?>"><?= empty($cat['parent_local_id']) ? 'Parent' : 'Sub' ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Filter Tabs -->
    <div class="filter-section">
      <div class="filter-header">
        <h3>Filter Categories</h3>
        <div class="filter-tabs">
          <a href="?type=ALL" class="filter-tab <?= $typeFilter==='ALL' ? 'active' : '' ?>">
            <i class="fas fa-th"></i> All (<?= $total_categories ?>)
          </a>
          <a href="?type=INCOME" class="filter-tab <?= $typeFilter==='INCOME' ? 'active' : '' ?>">
            <i class="fas fa-arrow-down"></i> Income (<?= $income_categories ?>)
          </a>
          <a href="?type=EXPENSE" class="filter-tab <?= $typeFilter==='EXPENSE' ? 'active' : '' ?>">
            <i class="fas fa-arrow-up"></i> Expense (<?= $expense_categories ?>)
          </a>
        </div>
      </div>
    </div>

    <!-- Categories Grid -->
    <div class="table-section">
      <div class="section-header">
        <div>
          <h2><?= $typeFilter==='ALL' ? 'All' : $typeFilter ?> Categories</h2>
          <p>Showing <?= count($rows) ?> categories</p>
        </div>
        <div class="search-container">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search categories...">
        </div>
      </div>

      <?php if (empty($rows)): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-tags"></i></div>
        <h3>No Categories Found</h3>
        <p>Create categories to organize your transactions</p>
        <a href="<?= APP_BASE ?>/app/categories/create.php" class="btn-primary"><i class="fas fa-plus"></i><span>Create First Category</span></a>
      </div>
      <?php else: ?>
      <div class="categories-grid">
        <?php foreach($rows as $r): ?>
        <div class="category-card" data-name="<?= h($r['category_name']) ?>" data-type="<?= h($r['category_type']) ?>">
          <div class="category-header">
            <div class="category-icon-wrapper <?= strtolower($r['category_type']) ?>">
              <i class="fas fa-<?= strtoupper($r['category_type'])==='INCOME' ? 'arrow-down' : 'arrow-up' ?>"></i>
            </div>
            <div class="category-badges">
              <span class="badge badge-<?= strtolower($r['category_type']) ?>"><?= h($r['category_type']) ?></span>
              <?php if (!empty($r['parent_local_id'])): ?>
              <span class="badge badge-sub"><i class="fas fa-level-down-alt"></i> Sub</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="category-body">
            <h3><?= h($r['category_name']) ?></h3>
            <div class="category-meta">
              <div class="meta-item"><i class="fas fa-hashtag"></i><span>ID: <?= (int)$r['local_category_id'] ?></span></div>
              <?php if (!empty($r['parent_local_id'])): ?>
              <div class="meta-item"><i class="fas fa-sitemap"></i><span>Parent: <?= (int)$r['parent_local_id'] ?></span></div>
              <?php endif; ?>
            </div>
            <div class="category-dates">
              <div class="date-item"><i class="fas fa-calendar-plus"></i><span><?= !empty($r['created_at']) ? date('M d, Y', strtotime($r['created_at'])) : '—' ?></span></div>
              <div class="date-item"><i class="fas fa-calendar-check"></i><span><?= !empty($r['updated_at']) ? date('M d, Y', strtotime($r['updated_at'])) : '—' ?></span></div>
            </div>
          </div>

          <div class="category-footer">
            <a href="<?= APP_BASE ?>/app/categories/edit.php?id=<?= (int)$r['local_category_id'] ?>" class="action-btn action-edit">
              <i class="fas fa-edit"></i><span>Edit</span>
            </a>
            <a href="<?= APP_BASE ?>/app/categories/delete.php?id=<?= (int)$r['local_category_id'] ?>" class="action-btn action-delete"
               onclick="return confirm('Delete <?= h($r['category_name']) ?>?')">
              <i class="fas fa-trash"></i><span>Delete</span>
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
// Search
document.getElementById('searchInput')?.addEventListener('input', (e) => {
  const s = e.target.value.toLowerCase();
  document.querySelectorAll('.category-card').forEach(card => {
    const name = card.dataset.name.toLowerCase();
    const type = card.dataset.type.toLowerCase();
    card.style.display = (name.includes(s) || type.includes(s)) ? '' : 'none';
  });
});

<?php if ($show_insights): ?>
// Charts
const chartCfg = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: true, position: 'bottom', labels: { padding: 15, font: { size: 12, weight: '600' } } },
    tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.95)', padding: 12, borderRadius: 8 }
  }
};

// Distribution (Income vs Expense)
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
  options: { ...chartCfg, cutout: '70%' }
});

// Hierarchy (Parent vs Sub)
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
    ...chartCfg,
    plugins: { ...chartCfg.plugins, legend: { display: false } },
    scales: {
      y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false }, ticks: { font: { size: 11 }, stepSize: 1 } },
      x: { grid: { display: false, drawBorder: false }, ticks: { font: { size: 11 } } }
    }
  }
});
<?php endif; ?>
</script>

</body>
</html>
