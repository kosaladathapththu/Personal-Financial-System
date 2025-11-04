<?php
// pfms/api/run_sync.php
declare(strict_types=1);

$ROOT = dirname(__DIR__); // .../pfms

require $ROOT . '/config/env.php';
require $ROOT . '/db/sqlite.php';
require $ROOT . '/db/oracle.php';

// session (guard usually starts it)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// must be logged in
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
    header('Location: ' . APP_BASE . '/public/login.php?err=login_required');
    exit;
}

// connect DBs
$pdo    = sqlite();
$oracle = oracle_connect();
if (!$oracle) {
    $msg = 'Oracle not connected. Check env.php / OCI8.';
    header('Location: ' . APP_BASE . '/public/sync.php?msg=' . urlencode($msg));
    exit;
}

// run SyncManager
require $ROOT . '/app/sync/SyncManager.php';

try {
    $manager = new SyncManager($pdo, $oracle, $uid);
    $result  = $manager->syncAll();
    oracle_close($oracle);

    if (!empty($result['success'])) {
        $msg = sprintf(
            'Synced ✅ Accounts: %d, Categories: %d, Transactions: %d',
            (int)$result['accounts'],
            (int)$result['categories'],
            (int)$result['transactions']
        );
        if (!empty($result['errors'])) { $msg .= ' (with warnings)'; }
    } else {
        $errs = !empty($result['errors']) ? implode(' | ', $result['errors']) : 'Unknown error';
        $msg  = 'Sync failed ❌: ' . $errs;
    }
} catch (Throwable $e) {
    $msg = 'Sync crashed ❌: ' . $e->getMessage();
}

// back to UI
header('Location: ' . APP_BASE . '/public/sync.php?msg=' . urlencode($msg));
exit;
