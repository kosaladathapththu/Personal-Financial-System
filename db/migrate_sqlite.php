<?php
require __DIR__ . '/../config/env.php';
require __DIR__ . '/sqlite.php';

$pdo = sqlite();

// Enforce foreign keys in SQLite
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->beginTransaction();

// USERS_LOCAL
$pdo->exec("
CREATE TABLE IF NOT EXISTS USERS_LOCAL (
  local_user_id     INTEGER PRIMARY KEY AUTOINCREMENT,
  server_user_id    INTEGER,
  email             TEXT NOT NULL UNIQUE,
  password_hash     TEXT NOT NULL,
  full_name         TEXT NOT NULL,
  last_login_at     TEXT
);
");

// ACCOUNTS_LOCAL
$pdo->exec("
CREATE TABLE IF NOT EXISTS ACCOUNTS_LOCAL (
  local_account_id  INTEGER PRIMARY KEY AUTOINCREMENT,
  user_local_id     INTEGER NOT NULL,
  server_account_id INTEGER,
  account_name      TEXT NOT NULL,
  account_type      TEXT NOT NULL CHECK(account_type IN ('CASH','BANK','CARD','MOBILE')),
  currency_code     TEXT NOT NULL DEFAULT 'LKR',
  opening_balance   REAL NOT NULL DEFAULT 0,
  is_active         INTEGER NOT NULL DEFAULT 1, -- 1/0
  created_at        TEXT NOT NULL,
  updated_at        TEXT NOT NULL,
  FOREIGN KEY(user_local_id) REFERENCES USERS_LOCAL(local_user_id)
);
");

// CATEGORIES_LOCAL
$pdo->exec("
CREATE TABLE IF NOT EXISTS CATEGORIES_LOCAL (
  local_category_id  INTEGER PRIMARY KEY AUTOINCREMENT,
  user_local_id      INTEGER NOT NULL,
  server_category_id INTEGER,
  parent_local_id    INTEGER,
  category_name      TEXT NOT NULL,
  category_type      TEXT NOT NULL CHECK(category_type IN ('INCOME','EXPENSE')),
  created_at         TEXT NOT NULL,
  updated_at         TEXT NOT NULL,
  FOREIGN KEY(user_local_id)   REFERENCES USERS_LOCAL(local_user_id),
  FOREIGN KEY(parent_local_id) REFERENCES CATEGORIES_LOCAL(local_category_id)
);
");

// TRANSACTIONS_LOCAL
$pdo->exec("
CREATE TABLE IF NOT EXISTS TRANSACTIONS_LOCAL (
  local_txn_id       INTEGER PRIMARY KEY AUTOINCREMENT,
  client_txn_uuid    TEXT NOT NULL UNIQUE,
  user_local_id      INTEGER NOT NULL,
  account_local_id   INTEGER NOT NULL,
  category_local_id  INTEGER NOT NULL,
  txn_type           TEXT NOT NULL CHECK(txn_type IN ('INCOME','EXPENSE','TRANSFER')),
  amount             REAL NOT NULL,
  txn_date           TEXT NOT NULL, -- ISO-8601
  note               TEXT,
  sync_status        TEXT NOT NULL DEFAULT 'PENDING' CHECK(sync_status IN ('PENDING','SYNCED','CONFLICT')),
  server_txn_id      INTEGER,
  created_at         TEXT NOT NULL,
  updated_at         TEXT NOT NULL,
  last_sync_at       TEXT,
  FOREIGN KEY(user_local_id)     REFERENCES USERS_LOCAL(local_user_id),
  FOREIGN KEY(account_local_id)  REFERENCES ACCOUNTS_LOCAL(local_account_id),
  FOREIGN KEY(category_local_id) REFERENCES CATEGORIES_LOCAL(local_category_id)
);
");

// Seed minimal demo data (optional)
$now = date('Y-m-d H:i:s');

$pdo->exec("
INSERT OR IGNORE INTO USERS_LOCAL (local_user_id, email, password_hash, full_name, last_login_at)
VALUES (1, 'kosala@example.com', 'demo_hash_replace', 'Kosala D. Athapaththu', '$now');
");

$pdo->exec("
INSERT OR IGNORE INTO ACCOUNTS_LOCAL (local_account_id, user_local_id, account_name, account_type, currency_code, opening_balance, is_active, created_at, updated_at)
VALUES
 (1, 1, 'Cash Wallet', 'CASH', 'LKR', 5000.00, 1, '$now', '$now'),
 (2, 1, 'People''s Bank - Savings', 'BANK', 'LKR', 25000.00, 1, '$now', '$now');
");

$pdo->exec("
INSERT OR IGNORE INTO CATEGORIES_LOCAL (local_category_id, user_local_id, category_name, category_type, created_at, updated_at)
VALUES
 (1, 1, 'Salary', 'INCOME', '$now', '$now'),
 (3, 1, 'Food & Dining', 'EXPENSE', '$now', '$now');
");

$pdo->commit();

echo "SQLite migration completed\n";
