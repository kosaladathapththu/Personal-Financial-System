<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../auth/common/auth_guard.php';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$pdo = sqlite();
$uid = (int)$_SESSION['uid'];
$id  = (int)($_GET['id'] ?? 0);
$errors = [];
$success = false;

// Load category
$find = $pdo->prepare("
  SELECT local_category_id, parent_local_id, category_name, category_type, created_at, updated_at
  FROM CATEGORIES_LOCAL
  WHERE local_category_id=? AND user_local_id=?
");
$find->execute([$id, $uid]);
$cat = $find->fetch(PDO::FETCH_ASSOC);
if (!$cat) { 
    http_response_code(404); 
    die("Category not found or you don't have permission to edit it."); 
}

$selectedType = $_POST['category_type'] ?? $cat['category_type'];

// Get parent options (same type, exclude self)
$parentStmt = $pdo->prepare("
  SELECT local_category_id, category_name
  FROM CATEGORIES_LOCAL
  WHERE user_local_id=? AND category_type=? AND local_category_id <> ?
  ORDER BY category_name
");
$parentStmt->execute([$uid, $selectedType, $id]);
$parentOptions = $parentStmt->fetchAll(PDO::FETCH_ASSOC);

// Counts
$txnCnt = (int)$pdo->query("SELECT COUNT(*) FROM TRANSACTIONS_LOCAL WHERE category_local_id=".$id)->fetchColumn();
$childCnt = (int)$pdo->query("SELECT COUNT(*) FROM CATEGORIES_LOCAL WHERE parent_local_id=".$id)->fetchColumn();

// Get children for display
$children = [];
if ($childCnt > 0) {
    $childStmt = $pdo->prepare("SELECT category_name FROM CATEGORIES_LOCAL WHERE parent_local_id=? ORDER BY category_name LIMIT 5");
    $childStmt->execute([$id]);
    $children = $childStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['category_name'] ?? '');
    $type = $_POST['category_type'] ?? '';
    $parentId = $_POST['parent_local_id'] !== '' ? (int)$_POST['parent_local_id'] : null;

    if ($name === '') $errors[] = "Category name is required";
    if (strlen($name) < 2) $errors[] = "Category name must be at least 2 characters";
    if (!in_array($type, ['INCOME','EXPENSE'])) $errors[] = "Please select a valid type";

    if ($parentId === $id) $errors[] = "A category cannot be its own parent";

    if ($parentId !== null) {
        $chk = $pdo->prepare("SELECT category_type FROM CATEGORIES_LOCAL WHERE local_category_id=? AND user_local_id=?");
        $chk->execute([$parentId, $uid]);
        $parent = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$parent) $errors[] = "Invalid parent category";
        elseif ($parent['category_type'] !== $type) $errors[] = "Parent must be the same type";
    }

    // Validation rules
    if ($type !== $cat['category_type'] && $childCnt > 0) {
        $errors[] = "Cannot change type while this category has {$childCnt} child categories";
    }
    if ($type !== $cat['category_type'] && $txnCnt > 0) {
        $errors[] = "Cannot change type because {$txnCnt} transactions exist for this category";
    }

    // Check for duplicate name
    $dup = $pdo->prepare("SELECT COUNT(*) FROM CATEGORIES_LOCAL WHERE user_local_id=? AND category_name=? AND local_category_id!=?");
    $dup->execute([$uid, $name, $id]);
    if ($dup->fetchColumn() > 0) {
        $errors[] = "Another category with this name already exists";
    }

    if (!$errors) {
        $now = date('Y-m-d H:i:s');
        $upd = $pdo->prepare("
            UPDATE CATEGORIES_LOCAL
            SET category_name=?, category_type=?, parent_local_id=?, updated_at=?
            WHERE local_category_id=? AND user_local_id=?
        ");
        $upd->execute([$name, $type, $parentId, $now, $id, $uid]);

        $success = true;
        header('Refresh: 2; url=' . APP_BASE . '/app/categories/index.php');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Category - PFMS</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="edit.css">
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
    
    <!-- Page Header -->
    <div class="page-header">
      <div class="header-left">
        <a href="<?= APP_BASE ?>/app/categories/index.php" class="back-btn">
          <i class="fas fa-arrow-left"></i>
          <span>Back to Categories</span>
        </a>
        <div class="header-title">
          <h1><i class="fas fa-edit"></i> Edit Category</h1>
          <p>Update your category information</p>
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
        <h4>Category Updated Successfully!</h4>
        <p>Redirecting you back to categories list...</p>
      </div>
    </div>
    <?php endif; ?>

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
              value="<?= h($_POST['category_name'] ?? $cat['category_name']) ?>"
              required
              maxlength="100">
            <small class="field-hint">Give your category a descriptive name</small>
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
                  <span class="type-name-inline">INCOME</span>
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
                  <span class="type-name-inline">EXPENSE</span>
                  <div class="type-check">
                    <i class="fas fa-check-circle"></i>
                  </div>
                </div>
              </label>
            </div>
            <small class="field-hint">
              <i class="fas fa-info-circle"></i>
              Changing type will reload the parent category list
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
              <?php
              $selParent = $_POST['parent_local_id'] ?? $cat['parent_local_id'];
              foreach($parentOptions as $p): 
              ?>
                <option value="<?= $p['local_category_id'] ?>" 
                        <?= ($selParent == $p['local_category_id']) ? 'selected' : '' ?>>
                  <?= h($p['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small class="field-hint">Organize categories hierarchically (parent must be same type)</small>
          </div>

          <!-- Form Actions -->
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save"></i>
              <span>Update Category</span>
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
        
        <!-- Category Info Card -->
        <div class="info-card account-info-card">
          <div class="info-header">
            <i class="fas fa-info-circle"></i>
            <h3>Category Information</h3>
          </div>
          <div class="info-content">
            <div class="info-row">
              <span class="info-label">
                <i class="fas fa-hashtag"></i>
                Category ID
              </span>
              <span class="info-value"><?= $id ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">
                <i class="fas fa-tag"></i>
                Current Type
              </span>
              <span class="info-value">
                <span class="status-badge <?= strtolower($cat['category_type']) ?>-badge">
                  <?= $cat['category_type'] ?>
                </span>
              </span>
            </div>
            <div class="info-row">
              <span class="info-label">
                <i class="fas fa-receipt"></i>
                Transactions
              </span>
              <span class="info-value"><?= $txnCnt ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">
                <i class="fas fa-sitemap"></i>
                Sub-categories
              </span>
              <span class="info-value"><?= $childCnt ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">
                <i class="fas fa-calendar-plus"></i>
                Created
              </span>
              <span class="info-value"><?= date('M d, Y', strtotime($cat['created_at'])) ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">
                <i class="fas fa-calendar-check"></i>
                Last Updated
              </span>
              <span class="info-value"><?= date('M d, Y', strtotime($cat['updated_at'])) ?></span>
            </div>
          </div>
        </div>

        <!-- Child Categories -->
        <?php if ($childCnt > 0): ?>
        <div class="info-card children-card">
          <div class="info-header">
            <i class="fas fa-network-wired"></i>
            <h3>Sub-categories (<?= $childCnt ?>)</h3>
          </div>
          <div class="info-content">
            <?php foreach($children as $child): ?>
            <div class="child-item">
              <i class="fas fa-level-down-alt"></i>
              <span><?= h($child['category_name']) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($childCnt > 5): ?>
            <div class="child-item more">
              <i class="fas fa-ellipsis-h"></i>
              <span>And <?= $childCnt - 5 ?> more...</span>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Warning Card -->
        <?php if ($txnCnt > 0 || $childCnt > 0): ?>
        <div class="info-card warning-card">
          <div class="info-header">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Important Notice</h3>
          </div>
          <div class="info-content">
            <?php if ($txnCnt > 0): ?>
            <p class="warning-text">
              <i class="fas fa-info-circle"></i>
              This category has <strong><?= $txnCnt ?></strong> associated transaction<?= $txnCnt != 1 ? 's' : '' ?>. 
              You cannot change its type.
            </p>
            <?php endif; ?>
            <?php if ($childCnt > 0): ?>
            <p class="warning-text">
              <i class="fas fa-info-circle"></i>
              This category has <strong><?= $childCnt ?></strong> sub-categor<?= $childCnt != 1 ? 'ies' : 'y' ?>. 
              Changing type is not allowed.
            </p>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Tips Card -->
        <div class="info-card tips-card">
          <div class="info-header">
            <i class="fas fa-lightbulb"></i>
            <h3>Editing Tips</h3>
          </div>
          <div class="info-content">
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>You can rename your category anytime</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Changing parent helps organize your budget</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Type cannot be changed if transactions exist</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Parent category must be the same type</span>
            </div>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="info-card actions-card">
          <div class="info-header">
            <i class="fas fa-bolt"></i>
            <h3>Quick Actions</h3>
          </div>
          <div class="info-content">
            <a href="<?= APP_BASE ?>/app/transactions/index.php?category=<?= $id ?>" class="action-link">
              <i class="fas fa-list"></i>
              <span>View Transactions</span>
              <i class="fas fa-arrow-right"></i>
            </a>
            <a href="<?= APP_BASE ?>/app/transactions/create.php?category=<?= $id ?>" class="action-link">
              <i class="fas fa-plus"></i>
              <span>Add Transaction</span>
              <i class="fas fa-arrow-right"></i>
            </a>
            <a href="<?= APP_BASE ?>/app/categories/create.php?parent=<?= $id ?>" class="action-link">
              <i class="fas fa-layer-group"></i>
              <span>Create Sub-category</span>
              <i class="fas fa-arrow-right"></i>
            </a>
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