<?php
// pfms/app/auth/accounts/create.php
require __DIR__ . '/../../../config/env.php';
require __DIR__ . '/../../../db/sqlite.php';
require __DIR__ . '/../common/auth_guard.php';

// ---- utils (load if exists; otherwise provide fallbacks) ----
$util = __DIR__ . '/../util.php';
if (file_exists($util)) {
    require $util;
}
if (!function_exists('now_iso')) {
    function now_iso(): string { return date('Y-m-d H:i:s'); }
}
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);
$errors = [];
$success = false;

// Get existing accounts for quick reference
$existing = $pdo->prepare("SELECT account_name, account_type, opening_balance FROM ACCOUNTS_LOCAL WHERE user_local_id=? ORDER BY created_at DESC LIMIT 3");
$existing->execute([$uid]);
$recent_accounts = $existing->fetchAll(PDO::FETCH_ASSOC);

// Account type suggestions
$account_types = [
    'CASH' => ['icon' => 'money-bill-wave', 'desc' => 'Physical cash on hand', 'color' => 'green'],
    'BANK' => ['icon' => 'university', 'desc' => 'Bank savings or checking account', 'color' => 'blue'],
    'CARD' => ['icon' => 'credit-card', 'desc' => 'Credit or debit card', 'color' => 'purple'],
    'MOBILE' => ['icon' => 'mobile-alt', 'desc' => 'Mobile wallet or payment app', 'color' => 'orange']
];

// Popular currencies
$currencies = ['LKR', 'USD', 'EUR', 'GBP', 'INR', 'AUD', 'CAD', 'JPY', 'CNY'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['account_name'] ?? '');
    $type     = $_POST['account_type'] ?? '';
    $currency = trim($_POST['currency_code'] ?? 'LKR');
    $opening  = isset($_POST['opening_balance']) ? (float)$_POST['opening_balance'] : 0.0;

    if ($name === '') { $errors[] = 'Account name is required'; }
    if (strlen($name) < 3) { $errors[] = 'Account name must be at least 3 characters'; }
    if (!in_array($type, ['CASH','BANK','CARD','MOBILE'], true)) { $errors[] = 'Please select a valid account type'; }
    if ($opening < 0) { $errors[] = 'Opening balance cannot be negative'; }

    // Check for duplicate name
    $dup = $pdo->prepare("SELECT COUNT(*) FROM ACCOUNTS_LOCAL WHERE user_local_id=? AND account_name=?");
    $dup->execute([$uid, $name]);
    if ($dup->fetchColumn() > 0) {
        $errors[] = 'An account with this name already exists';
    }

    if (!$errors) {
        $now  = now_iso();
        $stmt = $pdo->prepare("
            INSERT INTO ACCOUNTS_LOCAL
                (user_local_id, account_name, account_type, currency_code, opening_balance, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 1, ?, ?)
        ");
        $stmt->execute([$uid, $name, $type, $currency, $opening, $now, $now]);
        
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
  <title>Create Account - PFMS</title>
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
          <h1><i class="fas fa-plus-circle"></i> Create New Account</h1>
          <p>Add a new financial account to track your money</p>
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
        <h4>Account Created Successfully!</h4>
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
              value="<?= h($_POST['account_name'] ?? '') ?>"
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
              <?php foreach($account_types as $key => $info): ?>
              <label class="type-option">
                <input 
                  type="radio" 
                  name="account_type" 
                  value="<?= $key ?>"
                  <?= (($_POST['account_type'] ?? '') === $key) ? 'checked' : '' ?>
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
              <?php foreach($currencies as $curr): ?>
                <option value="<?= $curr ?>" <?= (($_POST['currency_code'] ?? 'LKR') === $curr) ? 'selected' : '' ?>>
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
                value="<?= h($_POST['opening_balance'] ?? '0') ?>">
              <span class="input-icon">
                <i class="fas fa-money-bill-wave"></i>
              </span>
            </div>
            <small class="field-hint">Enter the current balance in this account</small>
          </div>

          <!-- Form Actions -->
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save"></i>
              <span>Create Account</span>
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
        
        <!-- Tips Card -->
        <div class="info-card tips-card">
          <div class="info-header">
            <i class="fas fa-lightbulb"></i>
            <h3>Quick Tips</h3>
          </div>
          <div class="info-content">
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Use descriptive names like "Emergency Fund" or "Daily Cash"</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Select the correct account type for better organization</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Enter the current balance to start tracking accurately</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>You can create multiple accounts for different purposes</span>
            </div>
          </div>
        </div>

        <!-- Recent Accounts -->
        <?php if (!empty($recent_accounts)): ?>
        <div class="info-card recent-card">
          <div class="info-header">
            <i class="fas fa-history"></i>
            <h3>Recent Accounts</h3>
          </div>
          <div class="info-content">
            <?php foreach($recent_accounts as $acc): ?>
            <div class="recent-account-item">
              <div class="recent-icon">
                <i class="fas fa-wallet"></i>
              </div>
              <div class="recent-info">
                <span class="recent-name"><?= h($acc['account_name']) ?></span>
                <span class="recent-type"><?= h($acc['account_type']) ?></span>
              </div>
              <span class="recent-balance"><?= number_format((float)$acc['opening_balance'], 2) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Account Types Info -->
        <div class="info-card types-info-card">
          <div class="info-header">
            <i class="fas fa-info-circle"></i>
            <h3>Account Types</h3>
          </div>
          <div class="info-content">
            <div class="type-info-item">
              <div class="type-info-icon cash">
                <i class="fas fa-money-bill-wave"></i>
              </div>
              <div class="type-info-text">
                <strong>CASH</strong>
                <p>For physical money you carry</p>
              </div>
            </div>
            <div class="type-info-item">
              <div class="type-info-icon bank">
                <i class="fas fa-university"></i>
              </div>
              <div class="type-info-text">
                <strong>BANK</strong>
                <p>Bank accounts and deposits</p>
              </div>
            </div>
            <div class="type-info-item">
              <div class="type-info-icon card">
                <i class="fas fa-credit-card"></i>
              </div>
              <div class="type-info-text">
                <strong>CARD</strong>
                <p>Credit or debit cards</p>
              </div>
            </div>
            <div class="type-info-item">
              <div class="type-info-icon mobile">
                <i class="fas fa-mobile-alt"></i>
              </div>
              <div class="type-info-text">
                <strong>MOBILE</strong>
                <p>Digital wallets and apps</p>
              </div>
            </div>
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

// Character counter for account name
const nameInput = document.getElementById('account_name');
if (nameInput) {
  nameInput.addEventListener('input', function() {
    const len = this.value.length;
    if (len > 0) {
      this.parentElement.classList.add('has-content');
    } else {
      this.parentElement.classList.remove('has-content');
    }
  });
}
</script>

</body>
</html>