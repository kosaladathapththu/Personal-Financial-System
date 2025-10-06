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
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Categories</title>
  <!-- Link to professional light CSS -->
  <link rel="stylesheet" href="<?= APP_BASE ?>/app/categories/index.css">
</head>
<body>
  <h2>Categories</h2>

  <p>
    <a href="<?= APP_BASE ?>/app/categories/create.php">➕ Add Category</a> |
    <a href="<?= APP_BASE ?>/public/dashboard.php">🏠 Dashboard</a>
  </p>

  <form method="get">
    <label>Filter:</label>
    <select name="type" onchange="this.form.submit()">
      <option value="ALL"     <?= $typeFilter==='ALL'?'selected':'' ?>>ALL</option>
      <option value="INCOME"  <?= $typeFilter==='INCOME'?'selected':'' ?>>INCOME</option>
      <option value="EXPENSE" <?= $typeFilter==='EXPENSE'?'selected':'' ?>>EXPENSE</option>
    </select>
    <noscript><button type="submit">Apply</button></noscript>
  </form>

  <table>
    <tr>
      <th>ID</th>
      <th>Name</th>
      <th>Type</th>
      <th>Parent</th>
      <th>Created</th>
      <th>Updated</th>
      <th>Actions</th>
    </tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['local_category_id'] ?></td>
        <td><?= htmlspecialchars($r['category_name']) ?></td>
        <td><?= htmlspecialchars($r['category_type']) ?></td>
        <td><?= $r['parent_local_id'] ? (int)$r['parent_local_id'] : '-' ?></td>
        <td><?= htmlspecialchars($r['created_at']) ?></td>
        <td><?= htmlspecialchars($r['updated_at']) ?></td>
        <td>
          <a href="<?= APP_BASE ?>/app/categories/edit.php?id=<?= (int)$r['local_category_id'] ?>">Edit</a>
          |
          <a href="<?= APP_BASE ?>/app/categories/delete.php?id=<?= (int)$r['local_category_id'] ?>"
             onclick="return confirm('Delete this category?')">Delete</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</body>
</html>
