<?php
require __DIR__ . '/../config/env.php';
require __DIR__ . '/../db/sqlite.php';
require __DIR__ . '/../app/auth/common/auth_guard.php'; // fixed path

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE . '/public/sync.php');
    exit;
}

$pdo = sqlite();
$uid = (int)($_SESSION['uid'] ?? 0);

try {
    $pdo->beginTransaction();

    // Get user's pending transactions
    $stmt = $pdo->prepare("
        SELECT local_txn_id, server_txn_id
        FROM TRANSACTIONS_LOCAL
        WHERE user_local_id = ? AND sync_status = 'PENDING'
    ");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare("
        UPDATE TRANSACTIONS_LOCAL
        SET sync_status   = 'SYNCED',
            server_txn_id = COALESCE(server_txn_id, ?),
            last_sync_at  = datetime('now'),
            updated_at    = datetime('now')
        WHERE local_txn_id = ?
          AND user_local_id = ?
    ");

    $count = 0;
    foreach ($rows as $r) {
        $serverId = $r['server_txn_id'] ?: random_int(100000, 999999); // simulate Oracle ID
        $upd->execute([$serverId, $r['local_txn_id'], $uid]);
        $count++;
    }

    $pdo->commit();

    header('Location: ' . APP_BASE . '/public/sync.php?msg=' . urlencode("Pushed $count transactions to Oracle (simulated)"));
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "Error: " . htmlspecialchars($e->getMessage());
}
