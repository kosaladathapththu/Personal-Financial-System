<?php
require __DIR__ . '/../config/env.php';
require __DIR__ . '/../db/sqlite.php';
require __DIR__ . '/../app/auth/common/auth_guard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE . '/public/sync.php');
    exit;
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);

try {
    $pdo->beginTransaction();

    $sel = $pdo->prepare("
        SELECT local_account_id
        FROM ACCOUNTS_LOCAL
        WHERE user_local_id = ?
          AND (server_account_id IS NULL OR server_account_id = '')
    ");
    $sel->execute([$uid]);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare("
        UPDATE ACCOUNTS_LOCAL
        SET server_account_id = ?,
            updated_at        = datetime('now')
        WHERE local_account_id = ?
          AND user_local_id = ?
    ");

    $count = 0;
    foreach ($rows as $r) {
        $serverId = (string)random_int(100000, 999999); // simulate Oracle ID
        $upd->execute([$serverId, $r['local_account_id'], $uid]);
        $count++;
    }

    $pdo->commit();
    header('Location: ' . APP_BASE . '/public/sync.php?msg=' . urlencode("Pushed $count accounts to Oracle (simulated)"));
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "Error: " . htmlspecialchars($e->getMessage());
}
