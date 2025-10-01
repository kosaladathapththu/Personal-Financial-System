<?php
// 1) Where the SQLite file will live
$dbFile = __DIR__ . '/../data/pfms.db';

// 2) Ensure /data folder exists
@mkdir(__DIR__ . '/../data', 0777, true);

// 3) Open (or create) the SQLite database file with PDO
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 4) Pragmas = SQLite settings for integrity & performance
$pdo->exec("PRAGMA foreign_keys = ON;");     // enforce FK constraints
$pdo->exec("PRAGMA journal_mode = WAL;");    // safe, fast writes

// 5) The schema: tables + indexes + change_log + triggers (transactions only for now)
$schema = <<<SQL
-- USERS
CREATE TABLE IF NOT EXISTS users (
  user_id       INTEGER PRIMARY KEY AUTOINCREMENT,
  full_name     TEXT NOT NULL,
  email         TEXT UNIQUE,
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ACCOUNTS
CREATE TABLE IF NOT EXISTS accounts (
  account_id      INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id         INTEGER NOT NULL,
  name            TEXT NOT NULL,
  type            TEXT NOT NULL CHECK (type IN ('CASH','BANK','CARD','OTHER')),
  currency        TEXT NOT NULL DEFAULT 'LKR',
  opening_balance REAL NOT NULL DEFAULT 0,
  created_at      TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at      TEXT NOT NULL DEFAULT (datetime('now')),
  guid            TEXT NOT NULL UNIQUE,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- CATEGORIES
CREATE TABLE IF NOT EXISTS categories (
  category_id   INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL,
  name          TEXT NOT NULL,
  kind          TEXT NOT NULL CHECK (kind IN ('INCOME','EXPENSE')),
  guid          TEXT NOT NULL UNIQUE,
  UNIQUE(user_id, name, kind),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- TRANSACTIONS
CREATE TABLE IF NOT EXISTS transactions (
  txn_id      INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER NOT NULL,
  account_id  INTEGER NOT NULL,
  category_id INTEGER NOT NULL,
  guid        TEXT NOT NULL UNIQUE,
  txn_date    TEXT NOT NULL,                 -- ISO8601
  amount      REAL NOT NULL CHECK (amount >= 0),
  kind        TEXT NOT NULL CHECK (kind IN ('INCOME','EXPENSE')),
  note        TEXT,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
  row_version INTEGER NOT NULL DEFAULT 1,
  FOREIGN KEY(user_id)     REFERENCES users(user_id)         ON DELETE CASCADE,
  FOREIGN KEY(account_id)  REFERENCES accounts(account_id)   ON DELETE CASCADE,
  FOREIGN KEY(category_id) REFERENCES categories(category_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_txn_user_date ON transactions(user_id, txn_date);

-- BUDGETS
CREATE TABLE IF NOT EXISTS budgets (
  budget_id    INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id      INTEGER NOT NULL,
  name         TEXT NOT NULL,
  period_start TEXT NOT NULL,
  period_end   TEXT NOT NULL,
  total_amount REAL NOT NULL CHECK (total_amount >= 0),
  created_at   TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at   TEXT NOT NULL DEFAULT (datetime('now')),
  guid         TEXT NOT NULL UNIQUE,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- BUDGET ITEMS
CREATE TABLE IF NOT EXISTS budget_items (
  item_id          INTEGER PRIMARY KEY AUTOINCREMENT,
  budget_id        INTEGER NOT NULL,
  category_id      INTEGER NOT NULL,
  allocated_amount REAL NOT NULL CHECK (allocated_amount >= 0),
  guid             TEXT NOT NULL UNIQUE,
  UNIQUE(budget_id, category_id),
  FOREIGN KEY(budget_id)   REFERENCES budgets(budget_id)     ON DELETE CASCADE,
  FOREIGN KEY(category_id) REFERENCES categories(category_id) ON DELETE CASCADE
);

-- SAVINGS GOALS
CREATE TABLE IF NOT EXISTS savings_goals (
  goal_id        INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id        INTEGER NOT NULL,
  name           TEXT NOT NULL,
  target_amount  REAL NOT NULL CHECK (target_amount > 0),
  target_date    TEXT,
  current_amount REAL NOT NULL DEFAULT 0 CHECK (current_amount >= 0),
  created_at     TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at     TEXT NOT NULL DEFAULT (datetime('now')),
  guid           TEXT NOT NULL UNIQUE,
  FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- GOAL CONTRIBUTIONS
CREATE TABLE IF NOT EXISTS goal_contributions (
  contrib_id   INTEGER PRIMARY KEY AUTOINCREMENT,
  goal_id      INTEGER NOT NULL,
  txn_id       INTEGER,
  amount       REAL NOT NULL CHECK (amount > 0),
  contrib_date TEXT NOT NULL,
  guid         TEXT NOT NULL UNIQUE,
  FOREIGN KEY(goal_id) REFERENCES savings_goals(goal_id) ON DELETE CASCADE,
  FOREIGN KEY(txn_id)  REFERENCES transactions(txn_id)   ON DELETE SET NULL
);

-- CHANGE LOG for sync (records every local change)
CREATE TABLE IF NOT EXISTS change_log (
  change_id  INTEGER PRIMARY KEY AUTOINCREMENT,
  table_name TEXT NOT NULL,
  row_guid   TEXT NOT NULL,
  op_type    TEXT NOT NULL CHECK (op_type IN ('INSERT','UPDATE','DELETE')),
  payload    TEXT, -- JSON snapshot for INSERT/UPDATE
  changed_at TEXT NOT NULL DEFAULT (datetime('now')),
  device_id  TEXT NOT NULL DEFAULT 'device-001',
  version    INTEGER NOT NULL DEFAULT 1,
  processed  INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_changelog_processed ON change_log(processed, changed_at);

-- TRIGGERS for transactions: auto-update timestamps/version + log changes
DROP TRIGGER IF EXISTS trg_txn_bu;
CREATE TRIGGER trg_txn_bu
BEFORE UPDATE ON transactions
FOR EACH ROW
BEGIN
  SELECT NEW.updated_at = datetime('now');
  SELECT NEW.row_version = OLD.row_version + 1;
END;

DROP TRIGGER IF EXISTS trg_txn_ai_log;
CREATE TRIGGER trg_txn_ai_log
AFTER INSERT ON transactions
FOR EACH ROW
BEGIN
  INSERT INTO change_log(table_name,row_guid,op_type,payload,version)
  VALUES ('transactions', NEW.guid, 'INSERT',
          json_object('guid',NEW.guid,'user_id',NEW.user_id,'account_id',NEW.account_id,'category_id',NEW.category_id,
                      'txn_date',NEW.txn_date,'amount',NEW.amount,'kind',NEW.kind,'note',NEW.note,
                      'created_at',NEW.created_at,'updated_at',NEW.updated_at,'row_version',NEW.row_version),
          NEW.row_version);
END;

DROP TRIGGER IF EXISTS trg_txn_au_log;
CREATE TRIGGER trg_txn_au_log
AFTER UPDATE ON transactions
FOR EACH ROW
BEGIN
  INSERT INTO change_log(table_name,row_guid,op_type,payload,version)
  VALUES ('transactions', NEW.guid, 'UPDATE',
          json_object('guid',NEW.guid,'user_id',NEW.user_id,'account_id',NEW.account_id,'category_id',NEW.category_id,
                      'txn_date',NEW.txn_date,'amount',NEW.amount,'kind',NEW.kind,'note',NEW.note,
                      'created_at',NEW.created_at,'updated_at',NEW.updated_at,'row_version',NEW.row_version),
          NEW.row_version);
END;

DROP TRIGGER IF EXISTS trg_txn_bd_log;
CREATE TRIGGER trg_txn_bd_log
BEFORE DELETE ON transactions
FOR EACH ROW
BEGIN
  INSERT INTO change_log(table_name,row_guid,op_type,payload,version)
  VALUES ('transactions', OLD.guid, 'DELETE', NULL, OLD.row_version + 1);
END;
SQL;

// 6) Execute the whole schema at once
$pdo->exec($schema);

// 7) Seed minimal rows so forms have something to reference
$pdo->exec("
INSERT INTO users(full_name,email) VALUES
('Kosala Daneshwara Athapaththu','kosala@example.com');

INSERT INTO accounts(user_id,name,type,currency,opening_balance,guid)
VALUES (1,'Main Wallet','CASH','LKR',0,'acc-1111-aaaa');

INSERT INTO categories(user_id,name,kind,guid) VALUES
(1,'Salary','INCOME','cat-1111-inc'),
(1,'Food','EXPENSE','cat-2222-exp');
");

echo 'SQLite installed at: ' . $dbFile;
