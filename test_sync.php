<?php
/**
 * PFMS Sync Test Runner 🧩
 * This script checks your Oracle + SQLite sync connection and runs one manual sync.
 */

require __DIR__ . '/config/env.php';
require __DIR__ . '/db/sqlite.php';
require __DIR__ . '/app/sync/SyncManager.php';

/* ========== STEP 1: CONNECT TO SQLITE ========== */
try {
    $pdo = sqlite(); // from sqlite.php
    echo "✅ SQLite connected successfully!\n";
} catch (Exception $e) {
    die("❌ SQLite connection failed: " . $e->getMessage() . "\n");
}

/* ========== STEP 2: CONNECT TO ORACLE ========== */
try {
    $conn_str = ORACLE_HOST . ":" . ORACLE_PORT . "/" . ORACLE_SERVICE;
    $oracle = @oci_connect(ORACLE_USER, ORACLE_PASS, $conn_str, 'AL32UTF8');

    if (!$oracle) {
        $e = oci_error();
        throw new Exception($e['message']);
    }
    echo "✅ Oracle connected successfully! (User: " . ORACLE_USER . ")\n";
} catch (Exception $e) {
    die("❌ Oracle connection failed: " . $e->getMessage() . "\n");
}

/* ========== STEP 3: DETECT ACTIVE USER IN LOCAL DB ========== */
$stmt = $pdo->query("SELECT local_user_id, email, full_name FROM USERS_LOCAL LIMIT 1");
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    die("❌ No local user found. Please sign up a user first in your PFMS app.\n");
}
echo "👤 Found local user: {$user['full_name']} ({$user['email']})\n";

/* ========== STEP 4: RUN THE SYNC ========== */
try {
    $manager = new SyncManager($pdo, $oracle, $user['local_user_id']);
    $result = $manager->syncAll();

    echo "\n🟢 Sync completed!\n";
    echo "-----------------------------\n";
    echo "Accounts synced:     {$result['accounts']}\n";
    echo "Categories synced:   {$result['categories']}\n";
    echo "Transactions synced: {$result['transactions']}\n";
    echo "-----------------------------\n";

    if (!empty($result['errors'])) {
        echo "⚠️ Errors:\n";
        foreach ($result['errors'] as $err) echo "  - $err\n";
    } else {
        echo "✅ No sync errors.\n";
    }
} catch (Exception $e) {
    echo "❌ Sync failed: " . $e->getMessage() . "\n";
}

/* ========== STEP 5: CLOSE CONNECTIONS ========== */
if ($oracle) oci_close($oracle);
echo "\n🧾 Log file saved at: app/sync/sync.log\n";
