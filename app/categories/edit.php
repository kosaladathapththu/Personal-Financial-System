<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../auth/common/auth_guard.php'; // <-- fixed path

$pdo = sqlite();
$uid = (int)$_SESSION['uid'];
$id  = (int)($_GET['id'] ?? 0);

$find = $pdo->prepare("
  SELECT local_category_id, parent_local_id, category_name, category_type
  FROM CATEGORIES_LOCAL
  WHERE local_category_id=? AND user_local_id=?
");
$find->execute([$id, $uid]);
$cat = $find->fetch(PDO::FETCH_ASSOC);
if (!$cat) { http_response_code(404); echo "Not found"; exit; }

$errors = [];
$selectedType = $_POST['category_type'] ?? $cat['category_type'];

// parents (same type, exclude self)
$parentStmt = $pdo->prepare("
  SELECT local_category_id, category_name
  FROM CATEGORIES_LOCAL
  WHERE user_local_id=? AND category_type=? AND local_category_id <> ?
  ORDER BY category_name
");
$parentStmt->execute([$uid, $selectedType, $id]);
$parentOptions = $parentStmt->fetchAll(PDO::FETCH_ASSOC);

// counts to warn if changing type
$txnCnt = (int)$pdo->query("SELECT COUNT(*) FROM TRANSACTIONS_LOCAL WHERE category_local_id=".$id)->fetchColumn();
$childCnt = (int)$pdo->query("SELECT COUNT(*) FROM CATEGORIES_LOCAL WHERE parent_local_id=".$id)->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['category_name'] ?? '');
  $type = $_POST['category_type'] ?? '';
  $parentId = $_POST['parent_local_id'] !== '' ? (int)$_POST['parent_local_id'] : null;

  if ($name === '') $errors[] = "Name is required";
  if (!in_array($type, ['INCOME','EXPENSE'])) $errors[] = "Invalid type";

  if ($parentId === $id) $errors[] = "A category cannot be its own parent";

  if ($parentId !== null) {
    $chk = $pdo->prepare("SELECT category_type FROM CATEGORIES_LOCAL WHERE local_category_id=? AND user_local_id=?");
    $chk->execute([$parentId, $uid]);
    $parent = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$parent) $errors[] = "Invalid parent";
    elseif ($parent['category_type'] !== $type) $errors[] = "Parent must be same type";
  }

  // Simple rule: if there are child categories and you change type -> block (to avoid broken tree)
  if ($type !== $cat['category_type'] && $childCnt > 0) {
    $errors[] = "Cannot change type while this category has children";
  }
  // Optional: if there are transactions and you change type -> block
  if ($type !== $cat['category_type'] && $txnCnt > 0) {
    $errors[] = "Cannot change type because transactions exist";
  }

  if (!$errors) {
    $now = date('Y-m-d H:i:s');
    $upd = $pdo->prepare("
      UPDATE CATEGORIES_LOCAL
      SET category_name=?, category_type=?, parent_local_id=?, updated_at=?
      WHERE local_category_id=? AND user_local_id=?
    ");
    $upd->execute([$name, $type, $parentId, $now, $id, $uid]);

    // 🔌 Oracle: later queue an UPDATE for sync
    header('Location: ' . APP_BASE . '/app/categories/index.php'); exit;
  }
}
?>
<h2>Edit Category ✏️🏷️</h2>
<p><a href="<?= APP_BASE ?>/app/categories/index.php">← Back</a></p>

<?php if ($txnCnt>0): ?><p>ℹ️ This category has <b><?= $txnCnt ?></b> transactions.</p><?php endif; ?>
<?php if ($childCnt>0): ?><p>ℹ️ This category has <b><?= $childCnt ?></b> children.</p><?php endif; ?>

<?php if ($errors): ?>
  <div style="color:red">
    <b>Please fix:</b>
    <ul><?php foreach($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul>
  </div>
<?php endif; ?>

<form method="post">
  <label>Name</label><br>
  <input name="category_name" value="<?= htmlspecialchars($_POST['category_name'] ?? $cat['category_name']) ?>"><br><br>

  <label>Type</label><br>
  <select name="category_type" onchange="this.form.submit()">
    <option value="INCOME"  <?= ($selectedType==='INCOME')?'selected':'' ?>>INCOME</option>
    <option value="EXPENSE" <?= ($selectedType==='EXPENSE')?'selected':'' ?>>EXPENSE</option>
  </select>
  <small>Changing type reloads parent list</small>
  <br><br>

  <label>Parent (optional, same type)</label><br>
  <select name="parent_local_id">
    <option value="">— None —</option>
    <?php
    $selParent = $_POST['parent_local_id'] ?? $cat['parent_local_id'];
    foreach($parentOptions as $p): ?>
      <option value="<?= $p['local_category_id'] ?>" <?= ($selParent == $p['local_category_id'])?'selected':'' ?>>
        <?= htmlspecialchars($p['category_name']) ?>
      </option>
    <?php endforeach; ?>
  </select><br><br>

  <button type="submit">Update</button>
</form>
