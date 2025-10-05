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

    // Simulate: mark local categories as "synced" by giving them a server_category_id if missing
    $sel = $pdo->prepare("
        SELECT local_category_id
        FROM CATEGORIES_LOCAL
        WHERE user_local_id = ?
          AND (server_category_id IS NULL OR server_category_id = '')
    ");
    $sel->execute([$uid]);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare("
        UPDATE CATEGORIES_LOCAL
        SET server_category_id = ?,
            updated_at         = datetime('now')
        WHERE local_category_id = ?
          AND user_local_id = ?
    ");

    $count = 0;
    foreach ($rows as $r) {
        $serverId = (string)random_int(100000, 999999); // simulate Oracle ID
        $upd->execute([$serverId, $r['local_category_id'], $uid]);
        $count++;
    }

    $pdo->commit();
    header('Location: ' . APP_BASE . '/public/sync.php?msg=' . urlencode("Pulled $count categories from Oracle (simulated)"));
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "Error: " . htmlspecialchars($e->getMessage());
}
