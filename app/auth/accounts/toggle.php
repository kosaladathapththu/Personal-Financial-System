<?php
// pfms/app/auth/accounts/toggle.php

// --- Correct paths (3 levels up) ---
require __DIR__ . '/../../../config/env.php';
require __DIR__ . '/../../../db/sqlite.php';
require __DIR__ . '/../common/auth_guard.php';

// Optional utils; add safe fallbacks if missing
foreach ([
    __DIR__ . '/../util.php',
    __DIR__ . '/../common/util.php',
] as $u) {
    if (file_exists($u)) { require_once $u; }
}
if (!function_exists('now_iso')) {
    function now_iso(): string { return date('Y-m-d H:i:s'); }
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);
$id  = (int)($_GET['id'] ?? 0);

// Basic guard
if ($uid <= 0 || $id <= 0) { http_response_code(400); echo "Bad request"; exit; }

// read current status (and ensure ownership)
$s = $pdo->prepare("
    SELECT is_active 
    FROM ACCOUNTS_LOCAL 
    WHERE local_account_id = ? AND user_local_id = ?
");
$s->execute([$id, $uid]);
$row = $s->fetch(PDO::FETCH_ASSOC);
if (!$row) { 
    http_response_code(404); 
    echo "Not found"; 
    exit; 
}

$new = ((int)$row['is_active'] === 1) ? 0 : 1;

// toggle
$u = $pdo->prepare("
    UPDATE ACCOUNTS_LOCAL 
    SET is_active = ?, updated_at = ? 
    WHERE local_account_id = ? AND user_local_id = ?
");
$u->execute([$new, now_iso(), $id, $uid]);

// 🔌 Oracle sync touchpoint (later):
// - Queue status change for Oracle

// Correct redirect: use APP_BASE and the right path
header('Location: ' . APP_BASE . '/app/auth/accounts/index.php');
exit;
