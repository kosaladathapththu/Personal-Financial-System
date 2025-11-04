<?php
require __DIR__ . '/../config/env.php';
require __DIR__ . '/../db/sqlite.php';

$pdo = sqlite();

echo "<pre>";
echo "SQLITE_PATH constant: " . SQLITE_PATH . "\n";
echo "Resolved realpath:   " . (realpath(SQLITE_PATH) ?: '(not found)') . "\n\n";

$rows = $pdo->query("PRAGMA database_list")->fetchAll(PDO::FETCH_ASSOC);
echo "PRAGMA database_list:\n";
print_r($rows);

echo "\nRow counts:\n";
foreach (['USERS_LOCAL','ACCOUNTS_LOCAL','CATEGORIES_LOCAL','TRANSACTIONS_LOCAL'] as $t) {
  $c = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
  echo "  $t = $c\n";
}
echo "</pre>";
