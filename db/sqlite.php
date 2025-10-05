<?php
function sqlite(): PDO {
  $pdo = new PDO('sqlite:' . SQLITE_PATH);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  // Enforce FKs as per logical rules
  $pdo->exec('PRAGMA foreign_keys = ON;');
  return $pdo;
}
