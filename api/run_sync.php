<?php
// pfms/api/run_sync.php
declare(strict_types=1);

// ----- bootstrap -----
$ROOT = dirname(__DIR__); // .../pfms
require $ROOT . '/config/env.php';
require $ROOT . '/db/sqlite.php';
require $ROOT . '/db/oracle.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ----- tiny logger -----
$LOG = $ROOT . '/app/sync/sync.log';
function log_sync($m){
    @file_put_contents($GLOBALS['LOG'], "[".date('Y-m-d H:i:s')."] $m\n", FILE_APPEND);
}

/** Build URL to /public/<path> without double /public */
function url_public(string $path): string {
    $base = rtrim(APP_BASE ?? '', '/');       // e.g. /pfms
    // if someone set APP_BASE=/pfms/public, strip the trailing /public once
    if (substr($base, -7) === '/public') { $base = substr($base, 0, -7); }
    return $base . '/public/' . ltrim($path, '/');
}

/** Safe redirect after POST to avoid loops */
function see_other(string $url): void {
    header('Location: ' . $url, true, 303);   // 303 = See Other (prevents re-POST)
    exit;
}

// ----- allow only POST -----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    see_other(url_public('sync.php?msg=' . urlencode('Use the Sync button to start.')));
}

// ----- auth -----
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) {
    see_other(url_public('login.php?err=login_required'));
}

// ----- connections -----
$pdo    = sqlite();
$oracle = oracle_connect();          // from your oracle.php
if (!$oracle) {
    see_other(url_public('sync.php?msg=' . urlencode('Oracle not connected. Check env.php / OCI8.')));
}

// ----- run SyncManager -----
require $ROOT . '/app/sync/SyncManager.php';

try {
    $manager = new SyncManager($pdo, $oracle, $uid);
    $result  = $manager->syncAll();
    oracle_close($oracle);

    if (!empty($result['success'])) {
        $msg = sprintf(
            'Synced Accounts:%d Categories:%d Transactions:%d%s',
            (int)($result['accounts'] ?? 0),
            (int)($result['categories'] ?? 0),
            (int)($result['transactions'] ?? 0),
            !empty($result['errors']) ? ' (with warnings)' : ''
        );
    } else {
        $errs = !empty($result['errors']) ? implode(' | ', $result['errors']) : 'Unknown error';
        $msg  = 'Sync failed: ' . $errs;
    }

    log_sync($msg);
    see_other(url_public('sync.php?msg=' . urlencode($msg)));

} catch (Throwable $e) {
    $msg = 'Sync crashed: ' . $e->getMessage();
    log_sync($msg);
    see_other(url_public('sync.php?msg=' . urlencode($msg)));
}
