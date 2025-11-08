<?php
// app/sync/push_all.php
declare(strict_types=1);

require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/sqlite.php';
require __DIR__ . '/../../db/oracle.php';

function info($m){ echo "[*] $m\n"; }
function ok($m){ echo "[OK] $m\n"; }
function warn($m){ echo "[!] $m\n"; }
function fail($m){ echo "[X] $m\n"; exit(1); }

$pdo = sqlite();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$oci = oracle_conn();
if (!$oci) fail("Could not connect to Oracle");

// ---------- helpers ----------
function oci_one($c, $sql, $binds=[]) {
    $s = oci_parse($c, $sql) ?: die("Parse error");
    foreach ($binds as $k=>$v) oci_bind_by_name($s, ":$k", $binds[$k]);
    if (!oci_execute($s)) { $e=oci_error($s); die("Exec error: ".$e['message']); }
    $r = oci_fetch_assoc($s);
    return $r ?: null;
}
function oci_exec_ok($c, $sql, $binds=[]) {
    $s = oci_parse($c, $sql) ?: fail("Parse error");
    foreach ($binds as $k=>$v) oci_bind_by_name($s, ":$k", $binds[$k]);
    if (!oci_execute($s)) { $e=oci_error($s); fail("Exec error: ".$e['message']); }
    return $s;
}

// ---------- 0) indexes/uniques (idempotent) ----------
info("Ensuring Oracle unique constraints...");
$ddl = [
 "BEGIN EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX UQ_USERS_EMAIL ON USERS_CLOUD(LOWER(TRIM(email)))'; EXCEPTION WHEN OTHERS THEN NULL; END;",
 "BEGIN EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX UQ_TXN_UUID ON TRANSACTIONS_CLOUD(client_txn_uuid)'; EXCEPTION WHEN OTHERS THEN NULL; END;",
 "BEGIN EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX UQ_ACC_USER_NAME ON ACCOUNTS_CLOUD(user_server_id, account_name)'; EXCEPTION WHEN OTHERS THEN NULL; END;",
 "BEGIN EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX UQ_CAT_USER_NAME ON CATEGORIES_CLOUD(user_server_id, category_name)'; EXCEPTION WHEN OTHERS THEN NULL; END;",
];
foreach ($ddl as $sql) oci_exec_ok($oci, $sql);
ok("Oracle constraints ready");

// ---------- 1) USERS ----------
info("Syncing USERS...");
$rows = $pdo->query("SELECT local_user_id, server_user_id, email, full_name FROM USERS_LOCAL")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $local = (int)$r['local_user_id'];
    $sid   = (int)($r['server_user_id'] ?? 0);
    $email = trim((string)$r['email']);
    $name  = (string)$r['full_name'];
    if ($email === '') { warn("Skip local user $local: empty email"); continue; }

    if ($sid <= 0) {
        $found = oci_one($oci, "SELECT server_user_id FROM USERS_CLOUD WHERE LOWER(TRIM(email))=LOWER(TRIM(:e))", ['e'=>$email]);
        if ($found) {
            $sid = (int)$found['SERVER_USER_ID'];
        } else {
            $sql = "INSERT INTO USERS_CLOUD(email, password_hash, full_name, created_at, updated_at)
                    VALUES (:e, 'sqlite-migrated', :n, SYSTIMESTAMP, SYSTIMESTAMP)
                    RETURNING server_user_id INTO :sid";
            $st = oci_parse($oci, $sql);
            if (!$st) { $e=oci_error($oci); fail("Parse user insert: ".$e['message']); }
            $eParam=$email; $nParam=$name; $sidParam=0;
            oci_bind_by_name($st, ':e', $eParam);
            oci_bind_by_name($st, ':n', $nParam);
            oci_bind_by_name($st, ':sid', $sidParam, -1, SQLT_INT);
            if (!oci_execute($st)) {
                $e = oci_error($st);
                if (strpos($e['message'], 'ORA-00001') !== false) {
                    $found = oci_one($oci, "SELECT server_user_id FROM USERS_CLOUD WHERE LOWER(TRIM(email))=LOWER(TRIM(:e))", ['e'=>$email]);
                    $sidParam = (int)$found['SERVER_USER_ID'];
                } else {
                    fail("User insert error: ".$e['message']);
                }
            }
            $sid = (int)$sidParam;
        }
        $pdo->prepare("UPDATE USERS_LOCAL SET server_user_id=? WHERE local_user_id=?")->execute([$sid,$local]);
        ok("Cloud user ready for $email -> $sid");
    }
}
ok("USERS done");

// ---------- 2) ACCOUNTS ----------
info("Syncing ACCOUNTS...");
$accs = $pdo->query("
    SELECT a.local_account_id, a.user_local_id, u.server_user_id AS user_server_id,
           a.account_name, a.account_type, a.currency_code, a.opening_balance, a.is_active
    FROM ACCOUNTS_LOCAL a
    JOIN USERS_LOCAL u ON u.local_user_id = a.user_local_id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($accs as $a) {
    $uid = (int)$a['user_server_id'];
    if ($uid <= 0) { warn("Skip account {$a['local_account_id']}: user not linked"); continue; }

    $found = oci_one($oci, "SELECT server_account_id FROM ACCOUNTS_CLOUD WHERE user_server_id=:u AND account_name=:n", ['u'=>$uid,'n'=>$a['account_name']]);
    if ($found) {
        oci_exec_ok($oci, "
          UPDATE ACCOUNTS_CLOUD
             SET account_type=:t, currency_code=:c, opening_balance=:ob, updated_at=SYSTIMESTAMP
           WHERE server_account_id=:id
        ", ['t'=>$a['account_type'],'c'=>$a['currency_code'],'ob'=>$a['opening_balance'],'id'=>$found['SERVER_ACCOUNT_ID']]);
        ok("Account up-to-date: {$a['account_name']} (user $uid)");
    } else {
        oci_exec_ok($oci, "
          INSERT INTO ACCOUNTS_CLOUD(user_server_id, account_name, account_type, currency_code, opening_balance, is_active, created_at, updated_at)
          VALUES (:u,:n,:t,:c,:ob,:ia,SYSTIMESTAMP,SYSTIMESTAMP)
        ", ['u'=>$uid,'n'=>$a['account_name'],'t'=>$a['account_type'],'c'=>$a['currency_code'],'ob'=>$a['opening_balance'],'ia'=>((int)$a['is_active']?1:0)]);
        ok("Account created: {$a['account_name']} (user $uid)");
    }
}
ok("ACCOUNTS done");

// ---------- 3) CATEGORIES ----------
if ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='CATEGORIES_LOCAL'")->fetch()) {
    info("Syncing CATEGORIES...");
    $cats = $pdo->query("
        SELECT c.local_category_id, c.user_local_id, u.server_user_id AS user_server_id,
               c.category_name, c.category_type
        FROM CATEGORIES_LOCAL c
        JOIN USERS_LOCAL u ON u.local_user_id = c.user_local_id
        ORDER BY c.local_category_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cats as $c) {
        $uid = (int)$c['user_server_id'];
        if ($uid <= 0) { warn("Skip category {$c['local_category_id']}: user not linked"); continue; }

        $found = oci_one($oci, "SELECT server_category_id FROM CATEGORIES_CLOUD WHERE user_server_id=:u AND category_name=:n", ['u'=>$uid,'n'=>$c['category_name']]);
        if ($found) {
            oci_exec_ok($oci, "UPDATE CATEGORIES_CLOUD SET category_type=:t, updated_at=SYSTIMESTAMP WHERE server_category_id=:id",
                ['t'=>$c['category_type'], 'id'=>$found['SERVER_CATEGORY_ID']]);
            ok("Category up-to-date: {$c['category_name']} (user $uid)");
        } else {
            oci_exec_ok($oci, "INSERT INTO CATEGORIES_CLOUD(user_server_id,category_name,category_type,created_at,updated_at)
                                VALUES (:u,:n,:t,SYSTIMESTAMP,SYSTIMESTAMP)",
                ['u'=>$uid,'n'=>$c['category_name'],'t'=>$c['category_type']]);
            ok("Category created: {$c['category_name']} (user $uid)");
        }
    }
    ok("CATEGORIES done");
} else {
    warn("CATEGORIES_LOCAL not found; skipping categories");
}

// ---------- 4) TRANSACTIONS (FIX: resolve names in SQLite, use only *_CLOUD in Oracle) ----------
info("Syncing TRANSACTIONS...");

$txns = $pdo->query("
    SELECT t.local_txn_id, t.client_txn_uuid,
           u.server_user_id AS user_server_id,
           t.account_local_id, t.category_local_id,
           t.txn_type, t.amount, t.note,
           substr(t.txn_date,1,10) as txn_date
    FROM TRANSACTIONS_LOCAL t
    JOIN USERS_LOCAL u ON u.local_user_id = t.user_local_id
    ORDER BY t.local_txn_id
")->fetchAll(PDO::FETCH_ASSOC);

// prepared statements on SQLite to get names from local IDs
$stAccName = $pdo->prepare("SELECT account_name FROM ACCOUNTS_LOCAL WHERE local_account_id=?");
$stCatName = $pdo->prepare("SELECT category_name FROM CATEGORIES_LOCAL WHERE local_category_id=?");

$insSql = "
  INSERT INTO TRANSACTIONS_CLOUD(
    client_txn_uuid, user_server_id, account_server_id, category_server_id,
    txn_type, amount, note, txn_date, created_at, updated_at
  )
  VALUES (
    :p_uuid, :p_uid,
    (SELECT server_account_id FROM ACCOUNTS_CLOUD
      WHERE user_server_id=:p_uid AND account_name=:p_acc_name),
    (SELECT server_category_id FROM CATEGORIES_CLOUD
      WHERE user_server_id=:p_uid AND category_name=:p_cat_name),
    :p_type, :p_amt, :p_note, TO_DATE(:p_date,'YYYY-MM-DD'), SYSTIMESTAMP, SYSTIMESTAMP
  )
";
$ins = oci_parse($oci, $insSql);
if (!$ins) { $e=oci_error($oci); fail("Parse insert txn: ".$e['message']); }

$added=0; $skipped=0; $failed=0;

foreach ($txns as $t) {
    $uid = (int)$t['user_server_id'];
    if ($uid <= 0) { warn("Skip txn {$t['local_txn_id']}: user not linked"); $skipped++; continue; }

    // skip if exists
    $exists = oci_one($oci, "SELECT 1 FROM TRANSACTIONS_CLOUD WHERE client_txn_uuid=:uuid", ['uuid'=>$t['client_txn_uuid']]);
    if ($exists) { $skipped++; continue; }

    // resolve names in SQLite
    $stAccName->execute([(int)$t['account_local_id']]);
    $accName = (string)($stAccName->fetchColumn() ?: '');

    $catName = null;
    if (!empty($t['category_local_id'])) {
        $stCatName->execute([(int)$t['category_local_id']]);
        $catName = (string)($stCatName->fetchColumn() ?: null);
    }

    // ensure account exists in Oracle for this user (should, from step 2)
    if ($accName === '') { warn("Txn {$t['local_txn_id']} has no account name"); $failed++; continue; }
    $accRow = oci_one($oci, "SELECT server_account_id FROM ACCOUNTS_CLOUD WHERE user_server_id=:u AND account_name=:n", ['u'=>$uid,'n'=>$accName]);
    if (!$accRow) { warn("Account '$accName' missing in Oracle for user $uid (txn {$t['local_txn_id']})"); $failed++; continue; }

    // category may be NULL (allow if FK permits)
    if ($catName !== null) {
        $catRow = oci_one($oci, "SELECT server_category_id FROM CATEGORIES_CLOUD WHERE user_server_id=:u AND category_name=:n", ['u'=>$uid,'n'=>$catName]);
        if (!$catRow) { warn("Category '$catName' missing in Oracle for user $uid (txn {$t['local_txn_id']})"); $failed++; continue; }
    }

    // bind & insert
    $p_uuid = (string)$t['client_txn_uuid'];
    $p_uid  = $uid;
    $p_acc_name = $accName;
    $p_cat_name = $catName; // can be null
    $p_type = (string)$t['txn_type'];
    $p_amt  = (float)$t['amount'];
    $p_note = (string)($t['note'] ?? '');
    $p_date = (string)$t['txn_date']; // 'YYYY-MM-DD'

    oci_bind_by_name($ins, ':p_uuid',     $p_uuid);
    oci_bind_by_name($ins, ':p_uid',      $p_uid, -1, SQLT_INT);
    oci_bind_by_name($ins, ':p_acc_name', $p_acc_name);
    oci_bind_by_name($ins, ':p_cat_name', $p_cat_name);
    oci_bind_by_name($ins, ':p_type',     $p_type);
    oci_bind_by_name($ins, ':p_amt',      $p_amt);
    oci_bind_by_name($ins, ':p_note',     $p_note);
    oci_bind_by_name($ins, ':p_date',     $p_date);

    if (!oci_execute($ins, OCI_NO_AUTO_COMMIT)) {
        $e = oci_error($ins);
        warn("Txn {$t['local_txn_id']} failed: ".$e['message']);
        oci_rollback($oci);
        $failed++;
    } else {
        $added++;
    }
}
oci_commit($oci);
ok("TRANSACTIONS done: added=$added, skipped=$skipped, failed=$failed");

ok("ALL SYNC COMPLETED.");
