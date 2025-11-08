<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../auth/common/auth_guard.php';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);
$errors = [];
$success = false;

$selectedType = $_POST['category_type'] ?? 'EXPENSE';

// Get existing categories for suggestions
$existing = $pdo->prepare("SELECT category_name, category_type FROM CATEGORIES_LOCAL WHERE user_local_id=? ORDER BY created_at DESC LIMIT 5");
$existing->execute([$uid]);
$recent_categories = $existing->fetchAll(PDO::FETCH_ASSOC);

// Count categories by type
$counts = $pdo->prepare("SELECT category_type, COUNT(*) as cnt FROM CATEGORIES_LOCAL WHERE user_local_id=? GROUP BY category_type");
$counts->execute([$uid]);
$type_counts = ['INCOME' => 0, 'EXPENSE' => 0];
foreach($counts->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $type_counts[$row['category_type']] = (int)$row['cnt'];
}

// Load parent options for the currently selected type
$parentStmt = $pdo->prepare("
  SELECT local_category_id, category_name
  FROM CATEGORIES_LOCAL
  WHERE user_local_id = ? AND category_type = ?
  ORDER BY category_name
");
$parentStmt->execute([$uid, $selectedType]);
$parentOptions = $parentStmt->fetchAll(PDO::FETCH_ASSOC);

// Popular category suggestions
$suggestions = [
    'INCOME' => ['Salary', 'Freelance', 'Investment', 'Bonus', 'Gift', 'Refund', 'Rental Income'],
    'EXPENSE' => ['Groceries', 'Rent', 'Utilities', 'Transport', 'Entertainment', 'Healthcare', 'Education', 'Shopping']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['category_name'] ?? '');
    $type = $_POST['category_type'] ?? '';
    $parentId = ($_POST['parent_local_id'] ?? '') !== '' ? (int)$_POST['parent_local_id'] : null;

    if ($name === '') {
        $errors[] = "Category name is required";
    }
    if (strlen($name) < 2) {
        $errors[] = "Category name must be at least 2 characters";
    }
    if (!in_array($type, ['INCOME','EXPENSE'], true)) {
        $errors[] = "Please select a valid category type";
    }

    // Check for duplicate
    $dup = $pdo->prepare("SELECT COUNT(*) FROM CATEGORIES_LOCAL WHERE user_local_id=? AND category_name=?");
    $dup->execute([$uid, $name]);
    if ($dup->fetchColumn() > 0) {
        $errors[] = "A category with this name already exists";
    }

    // Validate parent
    if ($parentId !== null) {
        $chk = $pdo->prepare("
            SELECT category_type
            FROM CATEGORIES_LOCAL
            WHERE local_category_id = ? AND user_local_id = ?
        ");
        $chk->execute([$parentId, $uid]);
        $parent = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$parent) {
            $errors[] = "Invalid parent category";
        } elseif ($parent['category_type'] !== $type) {
            $errors[] = "Parent category must be the same type";
        }
    }

    if (!$errors) {
        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare("
            INSERT INTO CATEGORIES_LOCAL
                (user_local_id, parent_local_id, category_name, category_type, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$uid, $parentId, $name, $type, $now, $now]);

        $success = true;
        header('Refresh: 2; url=' . APP_BASE . '/app/categories/index.php');
    }

    // Reload parent options
    $parentStmt->execute([$uid, $type ?: $selectedType]);
    $parentOptions = $parentStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Category - PFMS</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="create.css">
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
    
    <!-- Page Header -->
    <div class="page-header">
      <div class="header-left">
        <a href="<?= APP_BASE ?>/app/categories/index.php" class="back-btn">
          <i class="fas fa-arrow-left"></i>
          <span>Back to Categories</span>
        </a>
        <div class="header-title">
          <h1><i class="fas fa-plus-circle"></i> Create New Category</h1>
          <p>Organize your income and expenses with categories</p>
        </div>
      </div>
    </div>

    <?php if ($success): ?>
    <!-- Success Message -->
    <div class="alert alert-success">
      <div class="alert-icon">
        <i class="fas fa-check-circle"></i>
      </div>
      <div class="alert-content">
        <h4>Category Created Successfully!</h4>
        <p>Redirecting you back to categories list...</p>
      </div>
    </div>
    <?php endif; ?>

    <!-- Quick Stats -->
    <div class="quick-stats">
      <div class="stat-mini">
        <div class="stat-mini-icon income">
          <i class="fas fa-arrow-down"></i>
        </div>
        <div class="stat-mini-content">
          <span class="stat-mini-value"><?= $type_counts['INCOME'] ?></span>
          <span class="stat-mini-label">Income Categories</span>
        </div>
      </div>
      <div class="stat-mini">
        <div class="stat-mini-icon expense">
          <i class="fas fa-arrow-up"></i>
        </div>
        <div class="stat-mini-content">
          <span class="stat-mini-value"><?= $type_counts['EXPENSE'] ?></span>
          <span class="stat-mini-label">Expense Categories</span>
        </div>
      </div>
      <div class="stat-mini">
        <div class="stat-mini-icon total">
          <i class="fas fa-layer-group"></i>
        </div>
        <div class="stat-mini-content">
          <span class="stat-mini-value"><?= $type_counts['INCOME'] + $type_counts['EXPENSE'] ?></span>
          <span class="stat-mini-label">Total Categories</span>
        </div>
      </div>
    </div>

    <div class="content-grid">
      
      <!-- Main Form -->
      <div class="form-section">
        
        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
          <div class="alert-icon">
            <i class="fas fa-exclamation-circle"></i>
          </div>
          <div class="alert-content">
            <h4>Please Fix the Following Errors:</h4>
            <ul>
              <?php foreach ($errors as $e): ?>
                <li><?= h($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
        <?php endif; ?>

        <form method="post" class="account-form" id="categoryForm">
          
          <!-- Category Name -->
          <div class="form-group">
            <label for="category_name">
              <i class="fas fa-tag"></i>
              <span>Category Name</span>
              <span class="required">*</span>
            </label>
            <input 
              type="text" 
              id="category_name" 
              name="category_name" 
              placeholder="e.g., Groceries, Salary, Entertainment"
              value="<?= h($_POST['category_name'] ?? '') ?>"
              required
              maxlength="100"
              autocomplete="off">
            <small class="field-hint">Choose a clear, descriptive name for your category</small>
          </div>

          <!-- Category Type -->
          <div class="form-group">
            <label>
              <i class="fas fa-exchange-alt"></i>
              <span>Category Type</span>
              <span class="required">*</span>
            </label>
            <div class="type-selector type-selector-inline">
              <label class="type-option-inline">
                <input 
                  type="radio" 
                  name="category_type" 
                  value="INCOME"
                  <?= ($selectedType === 'INCOME') ? 'checked' : '' ?>
                  onchange="this.form.submit()"
                  required>
                <div class="type-card-inline income-type">
                  <div class="type-icon-inline">
                    <i class="fas fa-arrow-down"></i>
                  </div>
                  <div class="type-content">
                    <span class="type-name-inline">INCOME</span>
                    <span class="type-desc-inline">Money coming in</span>
                  </div>
                  <div class="type-check">
                    <i class="fas fa-check-circle"></i>
                  </div>
                </div>
              </label>
              
              <label class="type-option-inline">
                <input 
                  type="radio" 
                  name="category_type" 
                  value="EXPENSE"
                  <?= ($selectedType === 'EXPENSE') ? 'checked' : '' ?>
                  onchange="this.form.submit()"
                  required>
                <div class="type-card-inline expense-type">
                  <div class="type-icon-inline">
                    <i class="fas fa-arrow-up"></i>
                  </div>
                  <div class="type-content">
                    <span class="type-name-inline">EXPENSE</span>
                    <span class="type-desc-inline">Money going out</span>
                  </div>
                  <div class="type-check">
                    <i class="fas fa-check-circle"></i>
                  </div>
                </div>
              </label>
            </div>
            <small class="field-hint">
              <i class="fas fa-info-circle"></i>
              Selecting a type will update the parent category list
            </small>
          </div>

          <!-- Parent Category -->
          <div class="form-group">
            <label for="parent_local_id">
              <i class="fas fa-sitemap"></i>
              <span>Parent Category</span>
              <span class="optional-badge">Optional</span>
            </label>
            <select id="parent_local_id" name="parent_local_id">
              <option value="">— No Parent (Top Level) —</option>
              <?php foreach($parentOptions as $p): ?>
                <option value="<?= (int)$p['local_category_id'] ?>" 
                        <?= (($_POST['parent_local_id'] ?? '') == $p['local_category_id']) ? 'selected' : '' ?>>
                  <?= h($p['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small class="field-hint">
              Create sub-categories by selecting a parent (must be same type)
            </small>
          </div>

          <!-- Form Actions -->
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save"></i>
              <span>Create Category</span>
            </button>
            <a href="<?= APP_BASE ?>/app/categories/index.php" class="btn btn-secondary">
              <i class="fas fa-times"></i>
              <span>Cancel</span>
            </a>
          </div>

        </form>
      </div>

      <!-- Sidebar Info -->
      <div class="info-section">
        
        <!-- Category Suggestions -->
        <div class="info-card suggestions-card">
          <div class="info-header">
            <i class="fas fa-lightbulb"></i>
            <h3>Popular <?= $selectedType === 'INCOME' ? 'Income' : 'Expense' ?> Categories</h3>
          </div>
          <div class="info-content">
            <div class="suggestions-grid">
              <?php foreach($suggestions[$selectedType] as $suggestion): ?>
              <button type="button" class="suggestion-btn" onclick="document.getElementById('category_name').value = '<?= $suggestion ?>'; document.getElementById('category_name').focus();">
                <i class="fas fa-plus-circle"></i>
                <span><?= $suggestion ?></span>
              </button>
              <?php endforeach; ?>
            </div>
            <small class="suggestions-hint">Click any suggestion to use it</small>
          </div>
        </div>

        <!-- Recent Categories -->
        <?php if (!empty($recent_categories)): ?>
        <div class="info-card recent-card">
          <div class="info-header">
            <i class="fas fa-history"></i>
            <h3>Recent Categories</h3>
          </div>
          <div class="info-content">
            <?php foreach($recent_categories as $cat): ?>
            <div class="recent-category-item">
              <div class="recent-cat-icon <?= strtolower($cat['category_type']) ?>">
                <i class="fas fa-<?= $cat['category_type'] === 'INCOME' ? 'arrow-down' : 'arrow-up' ?>"></i>
              </div>
              <div class="recent-cat-info">
                <span class="recent-cat-name"><?= h($cat['category_name']) ?></span>
                <span class="recent-cat-type"><?= $cat['category_type'] ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Tips Card -->
        <div class="info-card tips-card">
          <div class="info-header">
            <i class="fas fa-question-circle"></i>
            <h3>Category Tips</h3>
          </div>
          <div class="info-content">
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Use clear names like "Groceries" instead of "Food"</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Create parent categories for better organization</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Income categories track money you receive</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Expense categories track money you spend</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>You can create sub-categories for detail tracking</span>
            </div>
          </div>
        </div>

        <!-- Examples Card -->
        <div class="info-card examples-card">
          <div class="info-header">
            <i class="fas fa-book"></i>
            <h3>Category Examples</h3>
          </div>
          <div class="info-content">
            <div class="example-group">
              <div class="example-header">
                <i class="fas fa-arrow-down"></i>
                <strong>Income Examples:</strong>
              </div>
              <ul class="example-list">
                <li>Salary → Monthly Pay</li>
                <li>Freelance → Project Work</li>
                <li>Investment → Dividends</li>
              </ul>
            </div>
            <div class="example-group">
              <div class="example-header">
                <i class="fas fa-arrow-up"></i>
                <strong>Expense Examples:</strong>
              </div>
              <ul class="example-list">
                <li>Housing → Rent, Utilities</li>
                <li>Food → Groceries, Dining Out</li>
                <li>Transport → Gas, Public Transit</li>
              </ul>
            </div>
          </div>
        </div>

      </div>
    </div>

  </main>
</div>

<script>
// Form validation
document.getElementById('categoryForm')?.addEventListener('submit', function(e) {
  const name = document.getElementById('category_name').value.trim();
  const type = document.querySelector('input[name="category_type"]:checked');
  
  if (name.length < 2) {
    e.preventDefault();
    alert('Category name must be at least 2 characters long');
    return false;
  }
  
  if (!type) {
    e.preventDefault();
    alert('Please select a category type');
    return false;
  }
});

// Visual feedback
const nameInput = document.getElementById('category_name');
if (nameInput && nameInput.value.length > 0) {
  nameInput.parentElement.classList.add('has-content');
}

if (nameInput) {
  nameInput.addEventListener('input', function() {
    if (this.value.length > 0) {
      this.parentElement.classList.add('has-content');
    } else {
      this.parentElement.classList.remove('has-content');
    }
  });
}
</script>

</body>
</html>