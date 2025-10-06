<?php
require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../auth/common/auth_guard.php';

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);

$errors = [];
$selectedType = $_POST['category_type'] ?? 'EXPENSE';

// Load parent options for the currently selected type
$parentStmt = $pdo->prepare("
  SELECT local_category_id, category_name
  FROM CATEGORIES_LOCAL
  WHERE user_local_id = ? AND category_type = ?
  ORDER BY category_name
");
$parentStmt->execute([$uid, $selectedType]);
$parentOptions = $parentStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['category_name'] ?? '');
  $type = $_POST['category_type'] ?? '';
  $parentId = ($_POST['parent_local_id'] ?? '') !== '' ? (int)$_POST['parent_local_id'] : null;

  if ($name === '') {
    $errors[] = "Name is required";
  }
  if (!in_array($type, ['INCOME','EXPENSE'], true)) {
    $errors[] = "Invalid type";
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
      $errors[] = "Invalid parent";
    } elseif ($parent['category_type'] !== $type) {
      $errors[] = "Parent must be the same type";
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

    header('Location: ' . APP_BASE . '/app/categories/index.php');
    exit;
  }

  // Reload parent options
  $parentStmt->execute([$uid, $type ?: $selectedType]);
  $parentOptions = $parentStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Add Category</title>
  <!-- Link to modern CSS -->
  <link rel="stylesheet" href="<?= APP_BASE ?>/app/categories/create.css">
</head>
<body>

  <form method="post">
    <h2>Add Category</h2>
    <p><a href="<?= APP_BASE ?>/app/categories/index.php">← Back</a></p>

    <?php if ($errors): ?>
      <div>
        <b>Please fix:</b>
        <ul>
          <?php foreach($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <label>Name</label>
    <input name="category_name" value="<?= htmlspecialchars($_POST['category_name'] ?? '') ?>" required>

    <label>Type</label>
    <select name="category_type" onchange="this.form.submit()">
      <option value="INCOME" <?= ($selectedType === 'INCOME' || ($_POST['category_type'] ?? '') === 'INCOME') ? 'selected' : '' ?>>INCOME</option>
      <option value="EXPENSE" <?= ($selectedType === 'EXPENSE' || ($_POST['category_type'] ?? '') === 'EXPENSE') ? 'selected' : '' ?>>EXPENSE</option>
    </select>
    <small>Changing type reloads parent list</small>

    <label>Parent (optional, same type)</label>
    <select name="parent_local_id">
      <option value="">— None —</option>
      <?php foreach($parentOptions as $p): ?>
        <option value="<?= (int)$p['local_category_id'] ?>" <?= (($_POST['parent_local_id'] ?? '') == $p['local_category_id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($p['category_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="submit">Save</button>
  </form>

</body>
</html>
