<?php
// app/auth/common/auth_guard.php — robust, path-safe guard

// 1) Find project root and load env/sqlite safely
$ROOT = dirname(__DIR__, 3); // .../pfms
$ENV  = $ROOT . '/config/env.php';
$SQL  = $ROOT . '/db/sqlite.php';

if (file_exists($ENV))  require_once $ENV;
if (file_exists($SQL))  require_once $SQL;

// 2) Start session only if not started yet
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3) Enforce login
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
    // APP_BASE is defined in env.php; fall back to "" if not loaded
    $base = defined('APP_BASE') ? APP_BASE : '';
    header('Location: ' . $base . '/public/login.php?err=login_required');
    exit;
}
