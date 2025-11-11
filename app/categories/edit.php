<?php
// app/categories/edit.php
declare(strict_types=1);

require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../../db/oracle.php';        // Oracle push
require __DIR__ . '/../auth/common/auth_guard.php';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function now_iso(): string { return date('Y-m-d H:i:s'); }

/**
 * Push a local category to Oracle (upsert).
 * Returns array [ok(bool), server_id(int|null), message(string)]
 */
function push_category_to_oracle(PDO $pdo, int $uid, int $localCategoryId): array {
    $conn = oracle_conn();
    if (!$conn) {
        return [false, null, 'Oracle not connected'];
    }

    // Set client identifier for audit trigger (changed_by)
    $clientId = (string)($_SESSION['email'] ?? $_SESSION['uid'] ?? 'PFMS');
    @oci_set_client_identifier($conn, $clientId);

    // 1) Load local category + user mapping
    $st = $pdo->prepare("
        SELECT c.local_category_id, c.server_category_id, c.category_name, c.category_type,
               c.parent_local_id, c.created_at, c.updated_at,
               u.server_user_id
        FROM CATEGORIES_LOCAL c
        JOIN USERS_LOCAL u ON u.local_user_id = c.user_local_id
        WHERE c.local_category_id = ? AND c.user_local_id = ?
    ");
    $st->execute([$localCategoryId, $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [false, null, 'Local category not found'];

    $serverUserId = (int)($row['server_user_id'] ?? 0);
    if ($serverUserId <= 0) {
        return [false, null, 'No server_user_id mapping; run full sync first'];
    }

    $serverCategoryId = $row['server_category_id'] ? (int)$row['server_category_id'] : null;
    $name            = (string)$row['category_name'];
    $type            = (string)$row['category_type']; // 'INCOME' | 'EXPENSE'
    $parentLocalId   = $row['parent_local_id'] !== null ? (int)$row['parent_local_id'] : null;

    // 2) Resolve parent_server_id if any
    $parentServerId = null;
    if ($parentLocalId !== null) {
        $ps = $pdo->prepare("
            SELECT server_category_id
            FROM CATEGORIES_LOCAL
            WHERE local_category_id = ? AND user_local_id = ?
        ");
        $ps->execute([$parentLocalId, $uid]);
        $parentServerId = $ps->fetchColumn();
        $parentServerId = $parentServerId !== false ? (int)$parentServerId : null;
    }

    // 3) UPDATE if we already have server_category_id
    if ($serverCategoryId) {
        $sql = "
            UPDATE CATEGORIES_CLOUD
               SET category_name    = :name,
                   category_type    = :type,
                   parent_server_id = :parent_id,
                   updated_at       = SYSTIMESTAMP
             WHERE server_category_id = :sid
               AND user_server_id     = :uid
        ";
        $stmt = oci_parse($conn, $sql);
        oci_bind_by_name($stmt, ':name', $name);
        oci_bind_by_name($stmt, ':type', $type);

        if ($parentServerId === null) {
            $tmp = null;
            oci_bind_by_name($stmt, ':parent_id', $tmp, -1, SQLT_INT);
        } else {
            oci_bind_by_name($stmt, ':parent_id', $parentServerId);
        }

        oci_bind_by_name($stmt, ':sid', $serverCategoryId);
        oci_bind_by_name($stmt, ':uid', $serverUserId);

        $ok = @oci_execute($stmt, OCI_NO_AUTO_COMMIT);
        if (!$ok) {
            $e = oci_error($stmt);
            oci_rollback($conn);
            return [false, $serverCategoryId, 'Oracle UPDATE failed: ' . ($e['message'] ?? 'unknown')];
        }
        oci_commit($conn);
        return [true, $serverCategoryId, 'Updated'];
    }

    // 4) INSERT if no server_category_id yet
    $sql = "
        INSERT INTO CATEGORIES_CLOUD (
            user_server_id, parent_server_id, category_name, category_type, created_at, updated_at
        ) VALUES (
            :uid, :parent_id, :name, :type, SYSTIMESTAMP, SYSTIMESTAMP
        )
        RETURNING server_category_id INTO :out_id
    ";
    $stmt = oci_parse($conn, $sql);
    oci_bind_by_name($stmt, ':uid', $serverUserId);

    if ($parentServerId === null) {
        $tmp = null;
        oci_bind_by_name($stmt, ':parent_id', $tmp, -1, SQLT_INT);
    } else {
        oci_bind_by_name($stmt, ':parent_id', $parentServerId);
    }

    oci_bind_by_name($stmt, ':name', $name);
    oci_bind_by_name($stmt, ':type', $type);

    $outId = null; // NUMBER -> bind as string buffer OK
    oci_bind_by_name($stmt, ':out_id', $outId, 40);

    $ok = @oci_execute($stmt, OCI_NO_AUTO_COMMIT);
    if (!$ok) {
        $e = oci_error($stmt);
        oci_rollback($conn);
        return [false, null, 'Oracle INSERT failed: ' . ($e['message'] ?? 'unknown')];
    }

    oci_commit($conn);

    // Save mapping back to SQLite
    if ($outId !== null) {
        $mx = $pdo->prepare("
            UPDATE CATEGORIES_LOCAL
               SET server_category_id = ?
             WHERE local_category_id = ? AND user_local_id = ?
        ");
        $mx->execute([(int)$outId, $localCategoryId, $uid]);
        return [true, (int)$outId, 'Inserted'];
    }

    return [false, null, 'Oracle insert returned no id'];
}

$pdo = sqlite();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { http_response_code(401); exit('Not logged in'); }

// Accept either local id (?id=) or server id (?sid=)
$idParam  = (int)($_GET['id']  ?? 0);
$sidParam = (int)($_GET['sid'] ?? 0);

$cat = null;

// 1) Try by local id
if ($idParam > 0) {
    $st = $pdo->prepare("
        SELECT local_category_id, user_local_id, parent_local_id, category_name, category_type,
               created_at, updated_at, server_category_id
        FROM CATEGORIES_LOCAL
        WHERE local_category_id = ? AND user_local_id = ?
    ");
    $st->execute([$idParam, $uid]);
    $cat = $st->fetch(PDO::FETCH_ASSOC);
}

// 2) If not found and server id passed, map server -> local
if (!$cat && $sidParam > 0) {
    $st = $pdo->prepare("
        SELECT local_category_id, user_local_id, parent_local_id, category_name, category_type,
               created_at, updated_at, server_category_id
        FROM CATEGORIES_LOCAL
        WHERE server_category_id = ? AND user_local_id = ?
    ");
    $st->execute([$sidParam, $uid]);
    $cat = $st->fetch(PDO::FETCH_ASSOC);
}

// 3) Some callers might pass server id in ?id=...
if (!$cat && $idParam > 0) {
    $st = $pdo->prepare("
        SELECT local_category_id, user_local_id, parent_local_id, category_name, category_type,
               created_at, updated_at, server_category_id
        FROM CATEGORIES_LOCAL
        WHERE server_category_id = ? AND user_local_id = ?
    ");
    $st->execute([$idParam, $uid]);
    $cat = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$cat) {
    http_response_code(404);
    exit("Category not found or you don't have permission to edit it.");
}

$id = (int)$cat['local_category_id'];

// Sticky type (so parent list refreshes when user toggles)
$selectedType = $_POST['category_type'] ?? $cat['category_type'];

// Parent options: same type, same user, not itself
$parentStmt = $pdo->prepare("
  SELECT local_category_id, category_name
  FROM CATEGORIES_LOCAL
  WHERE user_local_id = ? AND category_type = ? AND local_category_id <> ?
  ORDER BY category_name
");
$parentStmt->execute([$uid, $selectedType, $id]);
$parentOptions = $parentStmt->fetchAll(PDO::FETCH_ASSOC);

// Counts (parameterized + scoped to user)
$txnCntStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM TRANSACTIONS_LOCAL
    WHERE category_local_id = ? AND user_local_id = ?
");
$txnCntStmt->execute([$id, $uid]);
$txnCnt = (int)$txnCntStmt->fetchColumn();

$childCntStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM CATEGORIES_LOCAL
    WHERE parent_local_id = ? AND user_local_id = ?
");
$childCntStmt->execute([$id, $uid]);
$childCnt = (int)$childCntStmt->fetchColumn();

// Children list (for sidebar preview)
$children = [];
if ($childCnt > 0) {
    $childStmt = $pdo->prepare("
        SELECT category_name
        FROM CATEGORIES_LOCAL
        WHERE parent_local_id = ? AND user_local_id = ?
        ORDER BY category_name
        LIMIT 5
    ");
    $childStmt->execute([$id, $uid]);
    $children = $childStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Helper to prevent parent loops: ensure $candidate is NOT a descendant of $id
function is_descendant(PDO $pdo, int $uid, int $candidateId, int $id): bool {
    // BFS up the tree: walk parents of candidate until null/root
    $seen = [];
    $cur  = $candidateId;
    $q = $pdo->prepare("SELECT parent_local_id FROM CATEGORIES_LOCAL WHERE local_category_id = ? AND user_local_id = ?");
    while ($cur) {
        if (isset($seen[$cur])) break;
        $seen[$cur] = true;
        if ($cur === $id) return true; // loop detected
        $q->execute([$cur, $uid]);
        $p = (int)($q->fetchColumn() ?: 0);
        $cur = $p > 0 ? $p : 0;
    }
    return false;
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim((string)($_POST['category_name'] ?? ''));
    $type     = (string)($_POST['category_type'] ?? '');
    $parentId = (isset($_POST['parent_local_id']) && $_POST['parent_local_id'] !== '') ? (int)$_POST['parent_local_id'] : null;

    if ($name === '')            $errors[] = "Category name is required";
    if (strlen($name) < 2)       $errors[] = "Category name must be at least 2 characters";
    if (!in_array($type, ['INCOME','EXPENSE'], true)) $errors[] = "Please select a valid type";

    if ($parentId !== null) {
        if ($parentId === $id) {
            $errors[] = "A category cannot be its own parent";
        } else {
            // Parent must exist, same user & same type
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
                $errors[] = "Parent must be the same type";
            } elseif (is_descendant($pdo, $uid, $parentId, $id)) {
                $errors[] = "Cannot set a descendant as parent (loop detected)";
            }
        }
    }

    // Type change constraints
    if ($type !== $cat['category_type'] && $childCnt > 0) {
        $errors[] = "Cannot change type while this category has {$childCnt} sub-categor" . ($childCnt === 1 ? 'y' : 'ies');
    }
    if ($type !== $cat['category_type'] && $txnCnt > 0) {
        $errors[] = "Cannot change type because {$txnCnt} transaction" . ($txnCnt === 1 ? '' : 's') . " exist for this category";
    }

    // Duplicate name (same user, exclude current)
    $dup = $pdo->prepare("
        SELECT COUNT(*)
        FROM CATEGORIES_LOCAL
        WHERE user_local_id = ? AND category_name = ? AND local_category_id <> ?
    ");
    $dup->execute([$uid, $name, $id]);
    if ((int)$dup->fetchColumn() > 0) {
        $errors[] = "Another category with this name already exists";
    }

    if (!$errors) {
        $now = now_iso();

        // Update SQLite first
        $upd = $pdo->prepare("
            UPDATE CATEGORIES_LOCAL
               SET category_name   = ?,
                   category_type   = ?,
                   parent_local_id = ?,
                   updated_at      = ?
             WHERE local_category_id = ? AND user_local_id = ?
        ");
        $upd->execute([$name, $type, $parentId, $now, $id, $uid]);

        // Push to Oracle immediately (upsert)
        list($okPush, $srvId, $pushMsg) = push_category_to_oracle($pdo, $uid, $id);

        // Refresh $cat for sidebar
        $st = $pdo->prepare("
            SELECT local_category_id, user_local_id, parent_local_id, category_name, category_type,
                   created_at, updated_at, server_category_id
            FROM CATEGORIES_LOCAL
            WHERE local_category_id = ? AND user_local_id = ?
        ");
        $st->execute([$id, $uid]);
        $cat = $st->fetch(PDO::FETCH_ASSOC);

        $success = true;

        // Redirect with a friendly message
        $note = $okPush ? 'oracle_sync_ok' : ('oracle_sync_failed: '.urlencode($pushMsg));
        header('Location: ' . APP_BASE . '/app/categories/index.php?msg=' . $note);
        exit;
    }
}

// Sticky fields
$posted_name     = $_POST['category_name']   ?? $cat['category_name'];
$posted_type     = $_POST['category_type']   ?? $cat['category_type'];
$posted_parentId = $_POST['parent_local_id'] ?? $cat['parent_local_id'];
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

    <div class="page-header">
      <div class="header-left">
        <a href="<?= APP_BASE ?>/app/categories/index.php" class="back-btn">
          <i class="fas fa-arrow-left"></i><span>Back to Categories</span>
        </a>
        <div class="header-title">
          <h1><i class="fas fa-edit"></i> Edit Category</h1>
          <p>Update your category information</p>
        </div>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success">
        <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
        <div class="alert-content">
          <h4>Category Updated Successfully!</h4>
          <p>Redirecting you back to categories list...</p>
        </div>
      </div>
    <?php endif; ?>

    <div class="content-grid">

      <!-- Form -->
      <div class="form-section">

        <?php if (!empty($errors)): ?>
          <div class="alert alert-error">
            <div class="alert-icon"><i class="fas fa-exclamation-circle"></i></div>
            <div class="alert-content">
              <h4>Please Fix the Following Errors:</h4>
              <ul>
                <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
              </ul>
            </div>
          </div>
        <?php endif; ?>

        <form method="post" class="account-form" id="categoryForm">

          <!-- Name -->
          <div class="form-group">
            <label for="category_name">
              <i class="fas fa-tag"></i><span>Category Name</span><span class="required">*</span>
            </label>
            <input
              type="text"
              id="category_name"
              name="category_name"
              placeholder="e.g., Groceries, Salary, Entertainment"
              value="<?= h($posted_name) ?>"
              required maxlength="100">
            <small class="field-hint">Give your category a descriptive name</small>
          </div>

          <!-- Type -->
          <div class="form-group">
            <label><i class="fas fa-exchange-alt"></i><span>Category Type</span><span class="required">*</span></label>
            <div class="type-selector type-selector-inline">
              <label class="type-option-inline">
                <input type="radio" name="category_type" value="INCOME"
                       <?= ($posted_type === 'INCOME') ? 'checked' : '' ?>
                       onchange="this.form.submit()" required>
                <div class="type-card-inline income-type">
                  <div class="type-icon-inline"><i class="fas fa-arrow-down"></i></div>
                  <span class="type-name-inline">INCOME</span>
                  <div class="type-check"><i class="fas fa-check-circle"></i></div>
                </div>
              </label>

              <label class="type-option-inline">
                <input type="radio" name="category_type" value="EXPENSE"
                       <?= ($posted_type === 'EXPENSE') ? 'checked' : '' ?>
                       onchange="this.form.submit()" required>
                <div class="type-card-inline expense-type">
                  <div class="type-icon-inline"><i class="fas fa-arrow-up"></i></div>
                  <span class="type-name-inline">EXPENSE</span>
                  <div class="type-check"><i class="fas fa-check-circle"></i></div>
                </div>
              </label>
            </div>
            <small class="field-hint"><i class="fas fa-info-circle"></i> Changing type will reload the parent category list</small>
          </div>

          <!-- Parent -->
          <div class="form-group">
            <label for="parent_local_id">
              <i class="fas fa-sitemap"></i><span>Parent Category</span><span class="optional-badge">Optional</span>
            </label>
            <select id="parent_local_id" name="parent_local_id">
              <option value="">— No Parent (Top Level) —</option>
              <?php foreach ($parentOptions as $p): ?>
                <option value="<?= $p['local_category_id'] ?>"
                        <?= ((string)$posted_parentId !== '' && (int)$posted_parentId === (int)$p['local_category_id']) ? 'selected' : '' ?>>
                  <?= h($p['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small class="field-hint">Organize categories hierarchically (parent must be same type)</small>
          </div>

          <!-- Actions -->
          <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><span>Update Category</span></button>
            <a href="<?= APP_BASE ?>/app/categories/index.php" class="btn btn-secondary"><i class="fas fa-times"></i><span>Cancel</span></a>
          </div>

        </form>
      </div>

      <!-- Sidebar -->
      <div class="info-section">

        <div class="info-card account-info-card">
          <div class="info-header"><i class="fas fa-info-circle"></i><h3>Category Information</h3></div>
          <div class="info-content">
            <div class="info-row"><span class="info-label"><i class="fas fa-hashtag"></i>Category ID</span><span class="info-value"><?= $id ?></span></div>
            <div class="info-row">
              <span class="info-label"><i class="fas fa-tag"></i>Current Type</span>
              <span class="info-value">
                <span class="status-badge <?= strtolower($cat['category_type']) ?>-badge"><?= $cat['category_type'] ?></span>
              </span>
            </div>
            <div class="info-row"><span class="info-label"><i class="fas fa-receipt"></i>Transactions</span><span class="info-value"><?= $txnCnt ?></span></div>
            <div class="info-row"><span class="info-label"><i class="fas fa-sitemap"></i>Sub-categories</span><span class="info-value"><?= $childCnt ?></span></div>
            <div class="info-row"><span class="info-label"><i class="fas fa-calendar-plus"></i>Created</span><span class="info-value"><?= h(date('M d, Y', strtotime((string)$cat['created_at']))) ?></span></div>
            <div class="info-row"><span class="info-label"><i class="fas fa-calendar-check"></i>Last Updated</span><span class="info-value"><?= h(date('M d, Y', strtotime((string)$cat['updated_at']))) ?></span></div>
          </div>
        </div>

        <?php if ($childCnt > 0): ?>
          <div class="info-card children-card">
            <div class="info-header"><i class="fas fa-network-wired"></i><h3>Sub-categories (<?= $childCnt ?>)</h3></div>
            <div class="info-content">
              <?php foreach ($children as $child): ?>
                <div class="child-item"><i class="fas fa-level-down-alt"></i><span><?= h($child['category_name']) ?></span></div>
              <?php endforeach; ?>
              <?php if ($childCnt > 5): ?>
                <div class="child-item more"><i class="fas fa-ellipsis-h"></i><span>And <?= $childCnt - 5 ?> more...</span></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($txnCnt > 0 || $childCnt > 0): ?>
          <div class="info-card warning-card">
            <div class="info-header"><i class="fas fa-exclamation-triangle"></i><h3>Important Notice</h3></div>
            <div class="info-content">
              <?php if ($txnCnt > 0): ?>
                <p class="warning-text"><i class="fas fa-info-circle"></i>
                  This category has <strong><?= $txnCnt ?></strong> transaction<?= $txnCnt === 1 ? '' : 's' ?>. You cannot change its type.
                </p>
              <?php endif; ?>
              <?php if ($childCnt > 0): ?>
                <p class="warning-text"><i class="fas fa-info-circle"></i>
                  This category has <strong><?= $childCnt ?></strong> sub-categor<?= $childCnt === 1 ? 'y' : 'ies' ?>. Changing type is not allowed.
                </p>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="info-card actions-card">
          <div class="info-header"><i class="fas fa-bolt"></i><h3>Quick Actions</h3></div>
          <div class="info-content">
            <a href="<?= APP_BASE ?>/app/transactions/index.php?category=<?= $id ?>" class="action-link">
              <i class="fas fa-list"></i><span>View Transactions</span><i class="fas fa-arrow-right"></i>
            </a>
            <a href="<?= APP_BASE ?>/app/transactions/create.php?category=<?= $id ?>" class="action-link">
              <i class="fas fa-plus"></i><span>Add Transaction</span><i class="fas fa-arrow-right"></i>
            </a>
            <a href="<?= APP_BASE ?>/app/categories/create.php?parent=<?= $id ?>" class="action-link">
              <i class="fas fa-layer-group"></i><span>Create Sub-category</span><i class="fas fa-arrow-right"></i>
            </a>
          </div>
        </div>

      </div>
    </div>

  </main>
</div>

<script>
// Validate on submit
document.getElementById('categoryForm')?.addEventListener('submit', function(e) {
  const name = document.getElementById('category_name').value.trim();
  const type = document.querySelector('input[name="category_type"]:checked');
  if (name.length < 2) { e.preventDefault(); alert('Category name must be at least 2 characters long'); return; }
  if (!type) { e.preventDefault(); alert('Please select a category type'); return; }
});

// Visual feedback
const nameInput = document.getElementById('category_name');
if (nameInput) {
  if (nameInput.value.length > 0) nameInput.parentElement.classList.add('has-content');
  nameInput.addEventListener('input', function() {
    this.parentElement.classList.toggle('has-content', this.value.length > 0);
  });
}
</script>

</body>
</html>
