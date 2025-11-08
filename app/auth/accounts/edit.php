<?php
// pfms/app/auth/accounts/edit.php
require __DIR__ . '/../../../config/env.php';
require __DIR__ . '/../../../db/sqlite.php';
require __DIR__ . '/../common/auth_guard.php';

// utils (load if present); provide safe fallbacks
foreach ([
    __DIR__ . '/../util.php',
    __DIR__ . '/../common/util.php',
] as $u) {
    if (file_exists($u)) { require_once $u; }
}
if (!function_exists('now_iso')) {
    function now_iso(): string { return date('Y-m-d H:i:s'); }
}
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);
$id  = (int)($_GET['id'] ?? 0);
$errors = [];
$success = false;

// load current account
$stmt = $pdo->prepare("
  SELECT local_account_id, account_name, account_type, currency_code, opening_balance, is_active, created_at, updated_at
  FROM ACCOUNTS_LOCAL
  WHERE local_account_id = ? AND user_local_id = ?
");
$stmt->execute([$id, $uid]);
$acc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$acc) { 
    http_response_code(404); 
    die("Account not found or you don't have permission to edit it."); 
}

// Get transaction count for this account
$txn_count = $pdo->prepare("SELECT COUNT(*) FROM TRANSACTIONS_LOCAL WHERE account_local_id=?");
$txn_count->execute([$id]);
$transaction_count = (int)$txn_count->fetchColumn();

// Account type info
$account_types = [
    'CASH' => ['icon' => 'money-bill-wave', 'desc' => 'Physical cash on hand', 'color' => 'green'],
    'BANK' => ['icon' => 'university', 'desc' => 'Bank savings or checking account', 'color' => 'blue'],
    'CARD' => ['icon' => 'credit-card', 'desc' => 'Credit or debit card', 'color' => 'purple'],
    'MOBILE' => ['icon' => 'mobile-alt', 'desc' => 'Mobile wallet or payment app', 'color' => 'orange']
];

$currencies = ['LKR', 'USD', 'EUR', 'GBP', 'INR', 'AUD', 'CAD', 'JPY', 'CNY'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['account_name'] ?? '');
    $type     = $_POST['account_type'] ?? '';
    $currency = $_POST['currency_code'] ?? 'LKR';
    $opening  = (float)($_POST['opening_balance'] ?? 0);
    $active   = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') { $errors[] = 'Account name is required'; }
    if (strlen($name) < 3) { $errors[] = 'Account name must be at least 3 characters'; }
    if (!in_array($type, ['CASH','BANK','CARD','MOBILE'], true)) { 
        $errors[] = 'Please select a valid account type'; 
    }
    if ($opening < 0) { $errors[] = 'Opening balance cannot be negative'; }

    // Check for duplicate name (excluding current account)
    $dup = $pdo->prepare("SELECT COUNT(*) FROM ACCOUNTS_LOCAL WHERE user_local_id=? AND account_name=? AND local_account_id!=?");
    $dup->execute([$uid, $name, $id]);
    if ($dup->fetchColumn() > 0) {
        $errors[] = 'Another account with this name already exists';
    }

    if (!$errors) {
        $now = now_iso();
        $upd = $pdo->prepare("
            UPDATE ACCOUNTS_LOCAL
            SET account_name=?, account_type=?, currency_code=?, opening_balance=?, is_active=?, updated_at=?
            WHERE local_account_id=? AND user_local_id=?
        ");
        $upd->execute([$name, $type, $currency, $opening, $active, $now, $id, $uid]);

        $success = true;
        // Redirect after 2 seconds
        header('Refresh: 2; url=' . APP_BASE . '/app/auth/accounts/index.php');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Account - PFMS</title>
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
        <a href="<?= APP_BASE ?>/app/auth/accounts/index.php" class="back-btn">
          <i class="fas fa-arrow-left"></i>
          <span>Back to Accounts</span>
        </a>
        <div class="header-title">
          <h1><i class="fas fa-edit"></i> Edit Account</h1>
          <p>Update your account information</p>
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
        <h4>Account Updated Successfully!</h4>
        <p>Redirecting you back to accounts list...</p>
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

        <form method="post" class="account-form" id="accountForm">
          
          <!-- Account Name -->
          <div class="form-group">
            <label for="account_name">
              <i class="fas fa-signature"></i>
              <span>Account Name</span>
              <span class="required">*</span>
            </label>
            <input 
              type="text" 
              id="account_name" 
              name="account_name" 
              placeholder="e.g., My Savings Account, Cash Wallet"
              value="<?= h($_POST['account_name'] ?? $acc['account_name']) ?>"
              required
              maxlength="100">
            <small class="field-hint">Give your account a descriptive name</small>
          </div>

          <!-- Account Type -->
          <div class="form-group">
            <label>
              <i class="fas fa-layer-group"></i>
              <span>Account Type</span>
              <span class="required">*</span>
            </label>
            <div class="type-selector">
              <?php 
              $selected_type = $_POST['account_type'] ?? $acc['account_type'];
              foreach($account_types as $key => $info): 
              ?>
              <label class="type-option">
                <input 
                  type="radio" 
                  name="account_type" 
                  value="<?= $key ?>"
                  <?= ($selected_type === $key) ? 'checked' : '' ?>
                  required>
                <div class="type-card <?= $info['color'] ?>">
                  <div class="type-icon">
                    <i class="fas fa-<?= $info['icon'] ?>"></i>
                  </div>
                  <div class="type-info">
                    <span class="type-name"><?= $key ?></span>
                    <span class="type-desc"><?= $info['desc'] ?></span>
                  </div>
                  <div class="type-check">
                    <i class="fas fa-check-circle"></i>
                  </div>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Currency -->
          <div class="form-group">
            <label for="currency_code">
              <i class="fas fa-dollar-sign"></i>
              <span>Currency</span>
              <span class="required">*</span>
            </label>
            <select id="currency_code" name="currency_code" required>
              <?php 
              $selected_currency = $_POST['currency_code'] ?? $acc['currency_code'];
              foreach($currencies as $curr): 
              ?>
                <option value="<?= $curr ?>" <?= ($selected_currency === $curr) ? 'selected' : '' ?>>
                  <?= $curr ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small class="field-hint">Select your account's currency</small>
          </div>

          <!-- Opening Balance -->
          <div class="form-group">
            <label for="opening_balance">
              <i class="fas fa-coins"></i>
              <span>Opening Balance</span>
            </label>
            <div class="input-with-icon">
              <input 
                type="number" 
                id="opening_balance" 
                name="opening_balance" 
                step="0.01"
                min="0"
                placeholder="0.00"
                value="<?= h($_POST['opening_balance'] ?? $acc['opening_balance']) ?>">
              <span class="input-icon">
                <i class="fas fa-money-bill-wave"></i>
              </span>
            </div>
            <small class="field-hint">Current balance in this account</small>
          </div>

          <!-- Account Status -->
          <div class="form-group">
            <label class="checkbox-label">
              <input 
                type="checkbox" 
                name="is_active" 
                id="is_active"
                <?= (isset($_POST['is_active']) ? (isset($_POST['is_active']) ? 'checked' : '') : ($acc['is_active'] ? 'checked' : '')) ?>>
              <span class="checkbox-custom"></span>
              <span class="checkbox-text">
                <strong>Account is Active</strong>
                <small>Inactive accounts won't appear in transaction forms</small>
              </span>
            </label>
          </div>

          <!-- Form Actions -->
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save"></i>
              <span>Update Account</span>
            </button>
            <a href="<?= APP_BASE ?>/app/auth/accounts/index.php" class="btn btn-secondary">
              <i class="fas fa-times"></i>
              <span>Cancel</span>
            </a>
          </div>

        </form>
      </div>

      <!-- Sidebar Info -->
      <div class="info-section">
        
        <!-- Account Info Card -->
        <div class="info-card account-info-card">
          <div class="info-header">
            <i class="fas fa-info-circle"></i>
            <h3>Account Information</h3>
          </div>
          <div class="info-content">
            <div class="info-row">
              <span class="info-label">
                <i class="fas fa-calendar-plus"></i>
                Created
              </span>
              <span class="info-value"><?= date('M d, Y', strtotime($acc['created_at'])) ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">
                <i class="fas fa-calendar-check"></i>
                Last Updated
              </span>
              <span class="info-value"><?= date('M d, Y', strtotime($acc['updated_at'])) ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">
                <i class="fas fa-receipt"></i>
                Transactions
              </span>
              <span class="info-value"><?= $transaction_count ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">
                <i class="fas fa-toggle-<?= $acc['is_active'] ? 'on' : 'off' ?>"></i>
                Status
              </span>
              <span class="info-value">
                <span class="status-badge <?= $acc['is_active'] ? 'active' : 'inactive' ?>">
                  <?= $acc['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
              </span>
            </div>
          </div>
        </div>

        <!-- Warning Card -->
        <?php if ($transaction_count > 0): ?>
        <div class="info-card warning-card">
          <div class="info-header">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Important Notice</h3>
          </div>
          <div class="info-content">
            <p class="warning-text">
              <i class="fas fa-info-circle"></i>
              This account has <strong><?= $transaction_count ?></strong> associated transaction<?= $transaction_count != 1 ? 's' : '' ?>. 
              Changing the account type or currency may affect your financial reports.
            </p>
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
              <span>You can rename your account anytime</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Changing type helps organize your accounts better</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Deactivating hides account from new transactions</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Opening balance can be adjusted if needed</span>
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
            <a href="<?= APP_BASE ?>/app/transactions/index.php?account=<?= $id ?>" class="action-link">
              <i class="fas fa-list"></i>
              <span>View Transactions</span>
              <i class="fas fa-arrow-right"></i>
            </a>
            <a href="<?= APP_BASE ?>/app/transactions/create.php?account=<?= $id ?>" class="action-link">
              <i class="fas fa-plus"></i>
              <span>Add Transaction</span>
              <i class="fas fa-arrow-right"></i>
            </a>
            <a href="<?= APP_BASE ?>/app/auth/accounts/toggle.php?id=<?= $id ?>" class="action-link">
              <i class="fas fa-toggle-<?= $acc['is_active'] ? 'off' : 'on' ?>"></i>
              <span><?= $acc['is_active'] ? 'Deactivate' : 'Activate' ?> Account</span>
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
document.getElementById('accountForm')?.addEventListener('submit', function(e) {
  const name = document.getElementById('account_name').value.trim();
  const type = document.querySelector('input[name="account_type"]:checked');
  
  if (name.length < 3) {
    e.preventDefault();
    alert('Account name must be at least 3 characters long');
    return false;
  }
  
  if (!type) {
    e.preventDefault();
    alert('Please select an account type');
    return false;
  }
});

// Visual feedback for active input
const nameInput = document.getElementById('account_name');
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