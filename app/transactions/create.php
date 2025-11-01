<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../auth/common/auth_guard.php';

// ---- Util helpers ----
$utilCandidates = [
    __DIR__ . '/../auth/util.php',
    __DIR__ . '/../auth/common/util.php',
    __DIR__ . '/../common/util.php',
];
foreach ($utilCandidates as $utilPath) {
    if (file_exists($utilPath)) {
        require $utilPath;
        break;
    }
}
if (!function_exists('now_iso')) {
    function now_iso(): string { return date('Y-m-d H:i:s'); }
}
if (!function_exists('uuidv4')) {
    function uuidv4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);
$errors = [];
$success = false;
$typ = $_POST['txn_type'] ?? 'EXPENSE';

// Get accounts (use live balances view)
$accounts = $pdo->prepare("
  SELECT local_account_id, account_name, account_type, current_balance
  FROM V_ACCOUNT_BALANCES
  WHERE user_local_id = ? AND is_active = 1
  ORDER BY account_name
");
$accounts->execute([$uid]);
$accounts = $accounts->fetchAll(PDO::FETCH_ASSOC);


// Get categories by type
$catStmt = $pdo->prepare("
  SELECT local_category_id, category_name, parent_local_id
  FROM CATEGORIES_LOCAL
  WHERE user_local_id = ? AND category_type = ?
  ORDER BY category_name
");
$catStmt->execute([$uid, $typ]);
$catOptions = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent transactions for quick reference
$recent = $pdo->prepare("
  SELECT t.txn_type, t.amount, a.account_name, c.category_name, t.txn_date
  FROM TRANSACTIONS_LOCAL t
  JOIN ACCOUNTS_LOCAL a ON t.account_local_id = a.local_account_id
  JOIN CATEGORIES_LOCAL c ON t.category_local_id = c.local_category_id
  WHERE t.user_local_id = ?
  ORDER BY t.created_at DESC
  LIMIT 5
");
$recent->execute([$uid]);
$recent_transactions = $recent->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = $pdo->prepare("
  SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN txn_type='INCOME' THEN amount ELSE 0 END) as total_income,
    SUM(CASE WHEN txn_type='EXPENSE' THEN amount ELSE 0 END) as total_expense
  FROM TRANSACTIONS_LOCAL
  WHERE user_local_id = ?
");
$stats->execute([$uid]);
$statistics = $stats->fetch(PDO::FETCH_ASSOC);

// Quick amount suggestions
$quick_amounts = [100, 500, 1000, 5000, 10000];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $date = $_POST['txn_date'] ?? '';
    $type = $_POST['txn_type'] ?? '';
    $acc  = (int)($_POST['account_local_id'] ?? 0);
    $cat  = (int)($_POST['category_local_id'] ?? 0);
    $amt  = (float)($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if (!in_array($type, ['INCOME','EXPENSE'], true)) $errors[] = "Please select a valid transaction type";
    if ($date === '') $errors[] = "Transaction date is required";
    if ($acc <= 0) $errors[] = "Please select an account";
    if ($cat <= 0) $errors[] = "Please select a category";
    if ($amt <= 0) $errors[] = "Amount must be greater than zero";
    
    // Date validation
    if ($date && strtotime($date) > time()) {
        $errors[] = "Future dates are not allowed";
    }

    // Validate category type match
    if ($cat > 0) {
        $chk = $pdo->prepare("
            SELECT category_type
            FROM CATEGORIES_LOCAL
            WHERE local_category_id = ? AND user_local_id = ?
        ");
        $chk->execute([$cat, $uid]);
        $c = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$c || $c['category_type'] !== $type) {
            $errors[] = "Selected category does not match transaction type";
        }
    }

    if (!$errors) {
        $now  = now_iso();
        $uuid = uuidv4();
        $ins = $pdo->prepare("
            INSERT INTO TRANSACTIONS_LOCAL
                (client_txn_uuid, user_local_id, account_local_id, category_local_id, txn_type, amount, txn_date, note, sync_status, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?, ?)
        ");
        $ins->execute([$uuid, $uid, $acc, $cat, $type, $amt, $date, $note, $now, $now]);

        $success = true;
        header('Refresh: 2; url=' . APP_BASE . '/app/transactions/index.php');
    }

    // Refresh categories if type changed
    $catStmt->execute([$uid, $type ?: $typ]);
    $catOptions = $catStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add Transaction - PFMS</title>
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
      <a href="<?= APP_BASE ?>/app/categories/index.php" class="nav-item">
        <i class="fas fa-tags"></i>
        <span>Categories</span>
      </a>
      <a href="<?= APP_BASE ?>/app/transactions/index.php" class="nav-item active">
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
        <a href="<?= APP_BASE ?>/app/transactions/index.php" class="back-btn">
          <i class="fas fa-arrow-left"></i>
          <span>Back to Transactions</span>
        </a>
        <div class="header-title">
          <h1><i class="fas fa-plus-circle"></i> Add New Transaction</h1>
          <p>Record your income or expense transaction</p>
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
        <h4>Transaction Added Successfully!</h4>
        <p>Redirecting you back to transactions list...</p>
      </div>
    </div>
    <?php endif; ?>

    <!-- Quick Stats -->
    <div class="quick-stats">
      <div class="stat-mini">
        <div class="stat-mini-icon total">
          <i class="fas fa-receipt"></i>
        </div>
        <div class="stat-mini-content">
          <span class="stat-mini-value"><?= $statistics['total'] ?? 0 ?></span>
          <span class="stat-mini-label">Total Transactions</span>
        </div>
      </div>
      <div class="stat-mini">
        <div class="stat-mini-icon income">
          <i class="fas fa-arrow-down"></i>
        </div>
        <div class="stat-mini-content">
          <span class="stat-mini-value"><?= number_format($statistics['total_income'] ?? 0, 0) ?></span>
          <span class="stat-mini-label">Total Income</span>
        </div>
      </div>
      <div class="stat-mini">
        <div class="stat-mini-icon expense">
          <i class="fas fa-arrow-up"></i>
        </div>
        <div class="stat-mini-content">
          <span class="stat-mini-value"><?= number_format($statistics['total_expense'] ?? 0, 0) ?></span>
          <span class="stat-mini-label">Total Expense</span>
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

        <form method="post" class="account-form" id="transactionForm">
          
          <!-- Transaction Type -->
          <div class="form-group">
            <label>
              <i class="fas fa-exchange-alt"></i>
              <span>Transaction Type</span>
              <span class="required">*</span>
            </label>
            <div class="type-selector type-selector-inline">
              <label class="type-option-inline">
                <input 
                  type="radio" 
                  name="txn_type" 
                  value="INCOME"
                  <?= ($typ === 'INCOME') ? 'checked' : '' ?>
                  onchange="this.form.submit()"
                  required>
                <div class="type-card-inline income-type">
                  <div class="type-icon-inline">
                    <i class="fas fa-arrow-down"></i>
                  </div>
                  <div class="type-content">
                    <span class="type-name-inline">INCOME</span>
                    <span class="type-desc-inline">Money received</span>
                  </div>
                  <div class="type-check">
                    <i class="fas fa-check-circle"></i>
                  </div>
                </div>
              </label>
              
              <label class="type-option-inline">
                <input 
                  type="radio" 
                  name="txn_type" 
                  value="EXPENSE"
                  <?= ($typ === 'EXPENSE') ? 'checked' : '' ?>
                  onchange="this.form.submit()"
                  required>
                <div class="type-card-inline expense-type">
                  <div class="type-icon-inline">
                    <i class="fas fa-arrow-up"></i>
                  </div>
                  <div class="type-content">
                    <span class="type-name-inline">EXPENSE</span>
                    <span class="type-desc-inline">Money spent</span>
                  </div>
                  <div class="type-check">
                    <i class="fas fa-check-circle"></i>
                  </div>
                </div>
              </label>
            </div>
            <small class="field-hint">
              <i class="fas fa-info-circle"></i>
              Type selection will update available categories
            </small>
          </div>

          <!-- Date -->
          <div class="form-group">
            <label for="txn_date">
              <i class="fas fa-calendar-day"></i>
              <span>Transaction Date</span>
              <span class="required">*</span>
            </label>
            <input 
              type="date" 
              id="txn_date" 
              name="txn_date" 
              value="<?= h($_POST['txn_date'] ?? date('Y-m-d')) ?>"
              max="<?= date('Y-m-d') ?>"
              required>
            <small class="field-hint">When did this transaction occur?</small>
          </div>

          <!-- Account -->
          <div class="form-group">
            <label for="account_local_id">
              <i class="fas fa-wallet"></i>
              <span>Account</span>
              <span class="required">*</span>
            </label>
            <select id="account_local_id" name="account_local_id" required>
              <option value="0">— Select Account —</option>
              <?php foreach($accounts as $a): ?>
            <option value="<?= (int)$a['local_account_id'] ?>" 
                    <?= (($_POST['account_local_id'] ?? '') == $a['local_account_id']) ? 'selected' : '' ?>>
              <?= h($a['account_name']) ?> (<?= h($a['account_type']) ?>) - Balance: <?= number_format($a['current_balance'], 2) ?>
            </option>

              <?php endforeach; ?>
            </select>
            <small class="field-hint">Which account is this transaction for?</small>
          </div>

          <!-- Category -->
          <div class="form-group">
            <label for="category_local_id">
              <i class="fas fa-tags"></i>
              <span>Category</span>
              <span class="required">*</span>
            </label>
            <select id="category_local_id" name="category_local_id" required>
              <option value="0">— Select Category —</option>
              <?php foreach($catOptions as $c): ?>
                <option value="<?= (int)$c['local_category_id'] ?>" 
                        <?= (($_POST['category_local_id'] ?? '') == $c['local_category_id']) ? 'selected' : '' ?>>
                  <?= h($c['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small class="field-hint">Categorize your <?= strtolower($typ) ?></small>
          </div>

          <!-- Amount -->
          <div class="form-group">
            <label for="amount">
              <i class="fas fa-money-bill-wave"></i>
              <span>Amount</span>
              <span class="required">*</span>
            </label>
            <div class="input-with-icon">
              <input 
                type="number" 
                id="amount" 
                name="amount" 
                step="0.01"
                min="0.01"
                placeholder="0.00"
                value="<?= h($_POST['amount'] ?? '') ?>"
                required>
              <span class="input-icon">
                <i class="fas fa-dollar-sign"></i>
              </span>
            </div>
            <div class="quick-amounts">
              <?php foreach($quick_amounts as $qa): ?>
              <button type="button" class="quick-amount-btn" onclick="document.getElementById('amount').value = '<?= $qa ?>'">
                <?= number_format($qa) ?>
              </button>
              <?php endforeach; ?>
            </div>
            <small class="field-hint">Click quick amounts or enter custom value</small>
          </div>

          <!-- Note -->
          <div class="form-group">
            <label for="note">
              <i class="fas fa-sticky-note"></i>
              <span>Note</span>
              <span class="optional-badge">Optional</span>
            </label>
            <textarea 
              id="note" 
              name="note" 
              rows="3"
              placeholder="Add any additional details about this transaction..."
              maxlength="500"><?= h($_POST['note'] ?? '') ?></textarea>
            <small class="field-hint">Add description or memo (max 500 characters)</small>
          </div>

          <!-- Form Actions -->
          <div class="form-actions">
            <button type="submit" name="save" class="btn btn-primary">
              <i class="fas fa-save"></i>
              <span>Save Transaction</span>
            </button>
            <a href="<?= APP_BASE ?>/app/transactions/index.php" class="btn btn-secondary">
              <i class="fas fa-times"></i>
              <span>Cancel</span>
            </a>
          </div>

        </form>
      </div>

      <!-- Sidebar Info -->
      <div class="info-section">
        
        <!-- Recent Transactions -->
        <?php if (!empty($recent_transactions)): ?>
        <div class="info-card recent-card">
          <div class="info-header">
            <i class="fas fa-history"></i>
            <h3>Recent Transactions</h3>
          </div>
          <div class="info-content">
            <?php foreach($recent_transactions as $txn): ?>
            <div class="recent-txn-item">
              <div class="recent-txn-icon <?= strtolower($txn['txn_type']) ?>">
                <i class="fas fa-<?= $txn['txn_type'] === 'INCOME' ? 'arrow-down' : 'arrow-up' ?>"></i>
              </div>
              <div class="recent-txn-info">
                <span class="recent-txn-category"><?= h($txn['category_name']) ?></span>
                <span class="recent-txn-account"><?= h($txn['account_name']) ?></span>
              </div>
              <div class="recent-txn-right">
                <span class="recent-txn-amount"><?= number_format($txn['amount'], 2) ?></span>
                <span class="recent-txn-date"><?= date('M d', strtotime($txn['txn_date'])) ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Tips Card -->
        <div class="info-card tips-card">
          <div class="info-header">
            <i class="fas fa-lightbulb"></i>
            <h3>Transaction Tips</h3>
          </div>
          <div class="info-content">
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Record transactions as soon as they happen</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Add notes for better tracking and memory</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Use correct categories for accurate reports</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Choose the right account for each transaction</span>
            </div>
            <div class="tip-item">
              <i class="fas fa-check"></i>
              <span>Double-check amounts before saving</span>
            </div>
          </div>
        </div>

        <!-- Quick Links -->
        <div class="info-card actions-card">
          <div class="info-header">
            <i class="fas fa-bolt"></i>
            <h3>Quick Actions</h3>
          </div>
          <div class="info-content">
            <a href="<?= APP_BASE ?>/app/auth/accounts/create.php" class="action-link">
              <i class="fas fa-wallet"></i>
              <span>Create New Account</span>
              <i class="fas fa-arrow-right"></i>
            </a>
            <a href="<?= APP_BASE ?>/app/categories/create.php" class="action-link">
              <i class="fas fa-tag"></i>
              <span>Create New Category</span>
              <i class="fas fa-arrow-right"></i>
            </a>
            <a href="<?= APP_BASE ?>/app/transactions/index.php" class="action-link">
              <i class="fas fa-list"></i>
              <span>View All Transactions</span>
              <i class="fas fa-arrow-right"></i>
            </a>
          </div>
        </div>

        <!-- Account Summary -->
        <?php if (!empty($accounts)): ?>
        <div class="info-card account-summary-card">
          <div class="info-header">
            <i class="fas fa-chart-pie"></i>
            <h3>Your Accounts</h3>
          </div>
          <div class="info-content">
            <?php foreach(array_slice($accounts, 0, 4) as $acc): ?>
            <div class="account-summary-item">
              <div class="acc-sum-icon">
                <i class="fas fa-wallet"></i>
              </div>
              <div class="acc-sum-info">
                <span class="acc-sum-name"><?= h($acc['account_name']) ?></span>
                <span class="acc-sum-type"><?= h($acc['account_type']) ?></span>
              </div>
              <span class="acc-sum-balance"><?= number_format($acc['current_balance'], 2) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>

  </main>
</div>

<script>
// Form validation
document.getElementById('transactionForm')?.addEventListener('submit', function(e) {
  const type = document.querySelector('input[name="txn_type"]:checked');
  const date = document.getElementById('txn_date').value;
  const account = document.getElementById('account_local_id').value;
  const category = document.getElementById('category_local_id').value;
  const amount = document.getElementById('amount').value;
  
  if (!type) {
    e.preventDefault();
    alert('Please select a transaction type');
    return false;
  }
  
  if (!date) {
    e.preventDefault();
    alert('Please select a date');
    return false;
  }
  
  if (account === '0') {
    e.preventDefault();
    alert('Please select an account');
    return false;
  }
  
  if (category === '0') {
    e.preventDefault();
    alert('Please select a category');
    return false;
  }
  
  if (!amount || parseFloat(amount) <= 0) {
    e.preventDefault();
    alert('Please enter a valid amount');
    return false;
  }
});

// Character counter for note
const noteInput = document.getElementById('note');
if (noteInput) {
  noteInput.addEventListener('input', function() {
    const len = this.value.length;
    const hint = this.parentElement.querySelector('.field-hint');
    if (hint) {
      hint.textContent = `${len}/500 characters used`;
    }
  });
}
</script>

</body>
</html>