<?php
/* ====== App basics ====== */
define('APP_TIMEZONE', 'Asia/Colombo');
date_default_timezone_set(APP_TIMEZONE);

define('APP_BASE', '/pfms');
function url($p){ return APP_BASE . $p; }

/* ====== SQLite (ABSOLUTE path) ====== */
define('SQLITE_PATH', realpath(__DIR__ . '/../db/pfms.sqlite') ?: (__DIR__ . '/../db/pfms.sqlite'));

/* ====== Oracle (PDB service) ====== */
define('ORACLE_HOST',     'localhost');
define('ORACLE_PORT',     '1521');
define('ORACLE_SERVICE',  'XEPDB1');   // PDB service name
define('ORACLE_SID',      '');         // leave empty when SERVICE used

// Your Oracle user/schema credentials (OWNER of *_CLOUD tables)
define('ORACLE_USER',     'KOSALA');
define('ORACLE_PASS',     'Playptome');

// Charset
define('ORACLE_CHARSET',  'AL32UTF8');

// Default schema (used by SyncManager to qualify tables)
define('ORACLE_CURRENT_SCHEMA', 'KOSALA');

/* ====== Sync settings ====== */
define('AUTO_SYNC_ENABLED', false);
define('SYNC_INTERVAL_MINUTES', 15);
define('MAX_SYNC_RETRIES', 3);
define('SYNC_TIMEOUT_SECONDS', 30);
define('SYNC_DEBUG', true);

/* ====== Helper for quick debug ====== */
define('DEBUG_SHOW_SQLITE_PATH', true);
