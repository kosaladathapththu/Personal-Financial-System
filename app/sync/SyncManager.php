<?php
/**
 * PFMS — Robust SyncManager (UI + CLI safe)
 * Path: app/sync/SyncManager.php
 *
 * Flow:
 *   1) Ensure cloud user (create or link)
 *   2) Push ACCOUNTS_LOCAL ➜ ACCOUNTS_CLOUD
 *   3) Repair account mappings (IDs that point to missing rows)
 *   4) Push CATEGORIES_LOCAL ➜ CATEGORIES_CLOUD (parents→children)
 *   5) Repair category mappings (IDs that point to missing rows)
 *   6) Push TRANSACTIONS_LOCAL (PENDING) ➜ TRANSACTIONS_CLOUD
 *   7) Log everything to app/sync/sync.log
 */

class SyncManager {
    private PDO $sqlite_db;
    /** @var resource|false */
    private $oracle_conn;
    private int $local_user_id;
    private int $server_user_id = 0;
    private array $errors = [];
    private string $logFile;

    public function __construct(PDO $sqlite_db, $oracle_conn, int $local_user_id) {
        $this->sqlite_db     = $sqlite_db;
        $this->oracle_conn   = $oracle_conn;
        $this->local_user_id = $local_user_id;
        $this->logFile       = __DIR__ . '/sync.log';

        $this->log("\n────────────────────────────────────────────");
        $this->log("SyncManager constructed (local_user_id={$this->local_user_id})");

        $this->server_user_id = $this->ensureCloudUser();
        $this->log("Linked server_user_id={$this->server_user_id}");
    }

    /* --------------------------- helpers --------------------------- */

    private function log(string $msg): void {
        @file_put_contents($this->logFile, '['.date('Y-m-d H:i:s')."] $msg\n", FILE_APPEND);
    }

    private function ociFail($stmtOrConn, string $context): never {
        $e = is_resource($stmtOrConn) || $stmtOrConn ? oci_error($stmtOrConn) : oci_error();
        $m = $context . ' :: ' . ($e['message'] ?? 'Unknown OCI error');
        $this->errors[] = $m;
        $this->log(" $m");
        throw new Exception($m);
    }

    private function strOrNull($v) { return ($v === null || $v === '') ? null : $v; }

    private function tsOrNow(?string $v): string {
        if (!$v) return date('Y-m-d H:i:s');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v.' 00:00:00';
        return $v;
    }

    private function asDateYmd(string $sqliteDate): string { return substr($sqliteDate, 0, 10); }

    private function ensureBindInt($stmt, string $name, $val): void {
        $tmp = (int)$val; // must be a variable for pass-by-ref
        oci_bind_by_name($stmt, $name, $tmp, -1, SQLT_INT);
    }

    private function oracleIdExists(string $table, string $col, int $id): bool {
        $sql = "SELECT 1 FROM {$table} WHERE {$col}=:id";
        $s = oci_parse($this->oracle_conn, $sql);
        $this->ensureBindInt($s, ':id', $id);
        if (!oci_execute($s)) $this->ociFail($s, "Exist check {$table}");
        return (bool) oci_fetch_assoc($s);
    }

    /* ------------------------ user bootstrap ----------------------- */
    private function ensureCloudUser(): int {
        $q = $this->sqlite_db->prepare("SELECT email, full_name, server_user_id FROM USERS_LOCAL WHERE local_user_id = ?");
        $q->execute([$this->local_user_id]);
        $u = $q->fetch(PDO::FETCH_ASSOC);
        if (!$u) throw new Exception("Local user not found (id={$this->local_user_id})");

        if (!empty($u['server_user_id'])) {
            $this->log("Local user already linked to server_user_id={$u['server_user_id']}");
            return (int)$u['server_user_id'];
        }

        $email = (string)$u['email'];
        $name  = (string)($u['full_name'] ?: 'Local User');

        // Find by email
        $stmt = oci_parse($this->oracle_conn, "SELECT server_user_id FROM USERS_CLOUD WHERE LOWER(email)=LOWER(:e)");
        $eVar = $email;
        oci_bind_by_name($stmt, ':e', $eVar);
        if (!oci_execute($stmt)) $this->ociFail($stmt, 'Find cloud user');
        $row = oci_fetch_assoc($stmt);
        if ($row && !empty($row['SERVER_USER_ID'])) {
            $sid = (int)$row['SERVER_USER_ID'];
            $this->sqlite_db->prepare("UPDATE USERS_LOCAL SET server_user_id=? WHERE local_user_id=?")
                            ->execute([$sid, $this->local_user_id]);
            $this->log("Linked existing cloud user {$sid} to local user {$this->local_user_id} ({$email})");
            return $sid;
        }

        // Create new user
        $sql = "INSERT INTO USERS_CLOUD (email, password_hash, full_name, created_at, updated_at)
                VALUES (:e, 'local-sync', :n, SYSTIMESTAMP, SYSTIMESTAMP)
                RETURNING server_user_id INTO :id";
        $stmt = oci_parse($this->oracle_conn, $sql);
        $idOut = 0;
        $nVar  = $name;
        oci_bind_by_name($stmt, ':e',  $eVar);
        oci_bind_by_name($stmt, ':n',  $nVar);
        oci_bind_by_name($stmt, ':id', $idOut, -1, SQLT_INT);
        if (!oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) $this->ociFail($stmt, 'Create cloud user');

        $this->sqlite_db->prepare("UPDATE USERS_LOCAL SET server_user_id=? WHERE local_user_id=?")
                        ->execute([$idOut, $this->local_user_id]);
        $this->log("Created cloud user {$idOut} for {$email}");
        return (int)$idOut;
    }

    /* --------------------------- public API ------------------------ */
    public function syncAll(): array {
        $res = ['success'=>false, 'accounts'=>0, 'categories'=>0, 'transactions'=>0, 'errors'=>[]];
        try {
            if ($this->server_user_id <= 0) throw new Exception('User not linked to cloud (server_user_id missing).');
            $this->log("=== SYNC START (local_user_id={$this->local_user_id}, server_user_id={$this->server_user_id}) ===");

            // 1) accounts ➜ cloud
            $res['accounts'] = $this->syncAccounts();
            // 2) repair account mappings (handle stale IDs)
            $this->repairAccountMappings();

            // 3) categories ➜ cloud (parents → children)
            $res['categories'] = $this->syncCategoriesOrdered();
            // 4) repair category mappings (handle stale IDs, unfound parents)
            $this->repairCategoryMappings();

            // 5) transactions ➜ cloud
            $res['transactions'] = $this->syncTransactions();

            $this->log("=== SYNC DONE: A={$res['accounts']} C={$res['categories']} T={$res['transactions']} ===");
            $res['success'] = true;
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            $this->log("FATAL: ".$e->getMessage());
        }
        $res['errors'] = array_merge($res['errors'], $this->errors);
        return $res;
    }

    /* -------------------------- Accounts -------------------------- */
    private function syncAccounts(): int {
        $sql = "SELECT local_account_id, account_name, account_type, currency_code, opening_balance, is_active,
                       COALESCE(created_at, datetime('now')) AS created_at,
                       COALESCE(updated_at, datetime('now')) AS updated_at
                  FROM ACCOUNTS_LOCAL
                 WHERE user_local_id=? AND (server_account_id IS NULL OR server_account_id='')";
        $st = $this->sqlite_db->prepare($sql);
        $st->execute([$this->local_user_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $count = 0;
        foreach ($rows as $a) {
            try {
                $serverId = $this->upsertCloudAccount($a);
                $this->sqlite_db->prepare("UPDATE ACCOUNTS_LOCAL SET server_account_id=? WHERE local_account_id=?")
                                ->execute([$serverId, $a['local_account_id']]);
                $count++;
            } catch (Exception $e) {
                $this->errors[] = "Account '{$a['account_name']}': ".$e->getMessage();
                $this->log("Account error: ".$e->getMessage());
            }
        }
        $this->log("Accounts synced: {$count}");
        return $count;
    }

    private function upsertCloudAccount(array $a): int {
        // Idempotent check by (user, name, type)
        $find = oci_parse($this->oracle_conn,
            "SELECT server_account_id
               FROM ACCOUNTS_CLOUD
              WHERE user_server_id = :u
                AND account_name    = :n
                AND account_type    = :t");

        $this->ensureBindInt($find, ':u', $this->server_user_id);
        $nVar = (string)($a['account_name'] ?? '');
        $tVar = (string)($a['account_type'] ?? '');
        oci_bind_by_name($find, ':n', $nVar);
        oci_bind_by_name($find, ':t', $tVar);

        if (!oci_execute($find)) $this->ociFail($find, 'Find account');
        $row = oci_fetch_assoc($find);
        if ($row && !empty($row['SERVER_ACCOUNT_ID'])) {
            return (int)$row['SERVER_ACCOUNT_ID'];
        }

        // Insert new
        $sql = "INSERT INTO ACCOUNTS_CLOUD
                  (user_server_id, account_name, account_type, currency_code, opening_balance, is_active,
                   created_at, updated_at)
                VALUES
                  (:u, :n, :t, :c, :b, :a,
                   TO_TIMESTAMP(:cr, 'YYYY-MM-DD HH24:MI:SS'),
                   TO_TIMESTAMP(:up, 'YYYY-MM-DD HH24:MI:SS'))
                RETURNING server_account_id INTO :id";
        $stmt = oci_parse($this->oracle_conn, $sql);

        $idVar     = 0;
        $uVar      = (int)$this->server_user_id;
        $cVar      = ($a['currency_code'] ?? '') ?: 'LKR';
        $bVar      = (float)($a['opening_balance'] ?? 0);
        $activeVar = (int)($a['is_active'] ?? 1);
        $crVar     = $this->tsOrNow($a['created_at'] ?? null);
        $upVar     = $this->tsOrNow($a['updated_at'] ?? null);

        $this->ensureBindInt($stmt, ':u',  $uVar);
        oci_bind_by_name($stmt, ':n',  $nVar);
        oci_bind_by_name($stmt, ':t',  $tVar);
        oci_bind_by_name($stmt, ':c',  $cVar);
        oci_bind_by_name($stmt, ':b',  $bVar);
        $this->ensureBindInt($stmt, ':a',  $activeVar);
        oci_bind_by_name($stmt, ':cr', $crVar);
        oci_bind_by_name($stmt, ':up', $upVar);
        $this->ensureBindInt($stmt, ':id', $idVar);

        if (!oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) $this->ociFail($stmt, 'Insert account');
        return (int)$idVar;
    }

    // Repair any broken/missing account mappings (SQLite -> Oracle)
    private function repairAccountMappings(): void {
        $q = $this->sqlite_db->prepare("
            SELECT local_account_id, account_name, account_type, server_account_id,
                   COALESCE(created_at, datetime('now')) AS created_at,
                   COALESCE(updated_at, datetime('now')) AS updated_at
              FROM ACCOUNTS_LOCAL
             WHERE user_local_id = ?
               AND server_account_id IS NOT NULL
               AND server_account_id != ''
        ");
        $q->execute([$this->local_user_id]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $a) {
            $sid = (int)$a['server_account_id'];
            if ($sid > 0 && $this->oracleIdExists('ACCOUNTS_CLOUD', 'server_account_id', $sid)) {
                continue; // mapping OK
            }

            // Try to find again by (user, name, type)
            $f = oci_parse($this->oracle_conn,
                "SELECT server_account_id
                   FROM ACCOUNTS_CLOUD
                  WHERE user_server_id = :u
                    AND account_name    = :n
                    AND account_type    = :t");
            $this->ensureBindInt($f, ':u', $this->server_user_id);
            $nVar = (string)$a['account_name'];
            $tVar = (string)$a['account_type'];
            oci_bind_by_name($f, ':n', $nVar);
            oci_bind_by_name($f, ':t', $tVar);
            if (!oci_execute($f)) $this->ociFail($f, 'Repair find account');
            $row = oci_fetch_assoc($f);

            if ($row && !empty($row['SERVER_ACCOUNT_ID'])) {
                $new = (int)$row['SERVER_ACCOUNT_ID'];
                $this->sqlite_db->prepare("UPDATE ACCOUNTS_LOCAL SET server_account_id=? WHERE local_account_id=?")
                                ->execute([$new, $a['local_account_id']]);
                $this->log("Repaired account map '{$a['account_name']}' → {$new}");
            } else {
                $new = $this->upsertCloudAccount($a);
                $this->sqlite_db->prepare("UPDATE ACCOUNTS_LOCAL SET server_account_id=? WHERE local_account_id=?")
                                ->execute([$new, $a['local_account_id']]);
                $this->log("Created & mapped account '{$a['account_name']}' → {$new}");
            }
        }
    }

    /* --------------------- Categories (ordered) -------------------- */
    private function syncCategoriesOrdered(): int {
        $st = $this->sqlite_db->prepare("
            SELECT local_category_id, user_local_id, parent_local_id,
                   category_name, category_type, server_category_id,
                   COALESCE(created_at, datetime('now')) AS created_at,
                   COALESCE(updated_at, datetime('now')) AS updated_at
              FROM CATEGORIES_LOCAL
             WHERE user_local_id=?
             ORDER BY COALESCE(parent_local_id,0), local_category_id
        ");
        $st->execute([$this->local_user_id]);
        $all = $st->fetchAll(PDO::FETCH_ASSOC);

        $pending = array_filter($all, fn($r) => empty($r['server_category_id']));
        if (empty($pending)) { $this->log("Categories synced: 0"); return 0; }

        // quick map by local id
        $byId = [];
        foreach ($all as $r) $byId[(int)$r['local_category_id']] = $r;

        $synced = 0;
        for ($pass = 1; $pass <= 5; $pass++) {
            $progress = 0;
            foreach ($pending as $idx => $c) {
                $parentSid = null;
                if (!empty($c['parent_local_id'])) {
                    $p = $byId[(int)$c['parent_local_id']] ?? null;
                    if (!$p) continue; // parent row missing locally (shouldn't happen)
                    $parentSid = $p['server_category_id'] ?? null;
                    if ($parentSid === '' || $parentSid === null) continue; // wait for parent
                }

                try {
                    $serverId = $this->insertCloudCategory($c, $parentSid);
                    $this->sqlite_db->prepare("UPDATE CATEGORIES_LOCAL SET server_category_id=? WHERE local_category_id=?")
                                    ->execute([$serverId, $c['local_category_id']]);
                    $byId[(int)$c['local_category_id']]['server_category_id'] = $serverId;
                    unset($pending[$idx]);
                    $synced++; $progress++;
                } catch (Exception $e) {
                    $this->errors[] = "Category '{$c['category_name']}': ".$e->getMessage();
                    $this->log("Category error: ".$e->getMessage());
                }
            }
            if ($progress === 0) break; // nothing moved this pass
        }

        // Leftovers (children without mapped parents)
        foreach ($pending as $c) {
            $msg = "Skipped child '{$c['category_name']}' (local_id={$c['local_category_id']}) → parent not synced.";
            $this->errors[] = $msg;
            $this->log($msg);
        }

        $this->log("Categories synced: {$synced}");
        return $synced;
    }

    private function insertCloudCategory(array $c, $parentServerId): int {
        // idempotent by (user, name, type)
        $find = oci_parse($this->oracle_conn,
            "SELECT server_category_id
               FROM CATEGORIES_CLOUD
              WHERE user_server_id=:u AND category_name=:n AND category_type=:t");
        $this->ensureBindInt($find, ':u', $this->server_user_id);
        $nVar = (string)$c['category_name'];
        $tVar = (string)$c['category_type'];
        oci_bind_by_name($find, ':n', $nVar);
        oci_bind_by_name($find, ':t', $tVar);
        if (!oci_execute($find)) $this->ociFail($find, 'Find category');
        $row = oci_fetch_assoc($find);
        if ($row && !empty($row['SERVER_CATEGORY_ID'])) return (int)$row['SERVER_CATEGORY_ID'];

        // insert
        $sql = "INSERT INTO CATEGORIES_CLOUD
                  (user_server_id, parent_server_id, category_name, category_type, created_at, updated_at)
                VALUES
                  (:u, :p, :n, :t,
                   TO_TIMESTAMP(:cr, 'YYYY-MM-DD HH24:MI:SS'),
                   TO_TIMESTAMP(:up, 'YYYY-MM-DD HH24:MI:SS'))
                RETURNING server_category_id INTO :id";
        $stmt = oci_parse($this->oracle_conn, $sql);

        $idVar = 0;
        $crVar = $this->tsOrNow($c['created_at'] ?? null);
        $upVar = $this->tsOrNow($c['updated_at'] ?? null);
        $this->ensureBindInt($stmt, ':u', $this->server_user_id);

        // proper nullable parent binding (must be a variable)
        if ($parentServerId === null) {
            $parentNull = null;
            oci_bind_by_name($stmt, ':p', $parentNull);
        } else {
            $pid = (int)$parentServerId;
            oci_bind_by_name($stmt, ':p', $pid, -1, SQLT_INT);
        }

        oci_bind_by_name($stmt, ':n',  $nVar);
        oci_bind_by_name($stmt, ':t',  $tVar);
        oci_bind_by_name($stmt, ':cr', $crVar);
        oci_bind_by_name($stmt, ':up', $upVar);
        $this->ensureBindInt($stmt, ':id', $idVar);

        if (!oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) $this->ociFail($stmt, 'Insert category');
        return (int)$idVar;
    }

    private function repairCategoryMappings(): void {
        $q = $this->sqlite_db->prepare("
            SELECT local_category_id, parent_local_id, category_name, category_type, server_category_id,
                   COALESCE(created_at, datetime('now')) AS created_at,
                   COALESCE(updated_at, datetime('now')) AS updated_at
              FROM CATEGORIES_LOCAL
             WHERE user_local_id=? AND server_category_id IS NOT NULL AND server_category_id!=''
        ");
        $q->execute([$this->local_user_id]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        // parent lookup (local_id -> server_id)
        $parents = $this->sqlite_db->query("
            SELECT local_category_id, server_category_id FROM CATEGORIES_LOCAL
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($rows as $c) {
            $sid = (int)$c['server_category_id'];
            if ($sid > 0 && $this->oracleIdExists('CATEGORIES_CLOUD', 'server_category_id', $sid)) {
                continue; // mapping OK
            }

            // try find in cloud again
            $f = oci_parse($this->oracle_conn,
                "SELECT server_category_id FROM CATEGORIES_CLOUD
                  WHERE user_server_id=:u AND category_name=:n AND category_type=:t");
            $this->ensureBindInt($f, ':u', $this->server_user_id);
            $nVar = (string)$c['category_name'];
            $tVar = (string)$c['category_type'];
            oci_bind_by_name($f, ':n', $nVar);
            oci_bind_by_name($f, ':t', $tVar);
            if (!oci_execute($f)) $this->ociFail($f, 'Repair find category');
            $row = oci_fetch_assoc($f);

            if ($row && !empty($row['SERVER_CATEGORY_ID'])) {
                $newSid = (int)$row['SERVER_CATEGORY_ID'];
                $this->sqlite_db->prepare("UPDATE CATEGORIES_LOCAL SET server_category_id=? WHERE local_category_id=?")
                                ->execute([$newSid, $c['local_category_id']]);
                $this->log("Repaired category map '{$c['category_name']}' → {$newSid}");
            } else {
                // create if we can resolve parent
                $parentSid = null;
                if (!empty($c['parent_local_id'])) {
                    $parentSid = $parents[(int)$c['parent_local_id']] ?? null;
                    if ($parentSid === '' || $parentSid === null) {
                        $this->log("Category repair skipped '{$c['category_name']}' — parent not mapped yet.");
                        continue;
                    }
                }
                $newSid = $this->insertCloudCategory($c, $parentSid);
                $this->sqlite_db->prepare("UPDATE CATEGORIES_LOCAL SET server_category_id=? WHERE local_category_id=?")
                                ->execute([$newSid, $c['local_category_id']]);
                $this->log("Created & mapped category '{$c['category_name']}' → {$newSid}");
            }
        }
    }

    /* ------------------------ Transactions ------------------------ */
    private function syncTransactions(): int {
        $sql = "
          SELECT t.local_txn_id, t.client_txn_uuid, t.user_local_id, t.account_local_id, t.category_local_id,
                 t.txn_type, t.amount, t.txn_date, t.note,
                 COALESCE(t.created_at, datetime('now')) AS created_at,
                 COALESCE(t.updated_at, datetime('now')) AS updated_at,
                 a.server_account_id, c.server_category_id
            FROM TRANSACTIONS_LOCAL t
            JOIN ACCOUNTS_LOCAL   a ON a.local_account_id  = t.account_local_id
            JOIN CATEGORIES_LOCAL c ON c.local_category_id = t.category_local_id
           WHERE t.user_local_id = ?
             AND t.sync_status   = 'PENDING'
             AND a.server_account_id  IS NOT NULL AND a.server_account_id  != ''
             AND c.server_category_id IS NOT NULL AND c.server_category_id != ''
           ORDER BY datetime(t.txn_date) ASC, t.local_txn_id ASC
        ";
        $st = $this->sqlite_db->prepare($sql);
        $st->execute([$this->local_user_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $count = 0;
        foreach ($rows as $t) {
            try {
                $serverId = $this->upsertCloudTransaction($t);
                $this->sqlite_db->prepare("
                    UPDATE TRANSACTIONS_LOCAL
                       SET server_txn_id = ?,
                           sync_status   = 'SYNCED',
                           last_sync_at  = ?
                     WHERE local_txn_id  = ?")->execute([
                        $serverId, date('Y-m-d H:i:s'), $t['local_txn_id']
                ]);
                $count++;
            } catch (Exception $e) {
                $msg = "Txn {$t['client_txn_uuid']}: ".$e->getMessage();
                $this->errors[] = $msg;
                $this->log("Txn error: ".$msg);
            }
        }
        $this->log("Transactions synced: {$count}");
        return $count;
    }

    private function upsertCloudTransaction(array $t): int {
        // idempotent by client uuid
        $find = oci_parse($this->oracle_conn,
            "SELECT server_txn_id FROM TRANSACTIONS_CLOUD WHERE client_txn_uuid=:u");
        $uuidVar = (string)$t['client_txn_uuid'];
        oci_bind_by_name($find, ':u', $uuidVar);
        if (!oci_execute($find)) $this->ociFail($find, 'Find txn');
        $row = oci_fetch_assoc($find);
        if ($row && !empty($row['SERVER_TXN_ID'])) return (int)$row['SERVER_TXN_ID'];

        $sql = "INSERT INTO TRANSACTIONS_CLOUD
                  (client_txn_uuid, user_server_id, account_server_id, category_server_id,
                   txn_type, amount, txn_date, note, created_at, updated_at)
                VALUES
                  (:uuid, :usr, :acc, :cat, :typ, :amt,
                   TO_DATE(:d, 'YYYY-MM-DD'),
                   :note,
                   TO_TIMESTAMP(:cr, 'YYYY-MM-DD HH24:MI:SS'),
                   TO_TIMESTAMP(:up, 'YYYY-MM-DD HH24:MI:SS'))
                RETURNING server_txn_id INTO :id";
        $stmt = oci_parse($this->oracle_conn, $sql);

        $idVar  = 0;
        $usrVar = (int)$this->server_user_id;
        $accVar = (int)$t['server_account_id'];
        $catVar = (int)$t['server_category_id'];
        $typVar = (string)$t['txn_type'];
        $amtVar = (float)$t['amount'];
        $dayVar = $this->asDateYmd((string)$t['txn_date']);
        $noteVar= $this->strOrNull($t['note']);
        $crVar  = $this->tsOrNow($t['created_at'] ?? null);
        $upVar  = $this->tsOrNow($t['updated_at'] ?? null);

        oci_bind_by_name($stmt, ':uuid', $uuidVar);
        $this->ensureBindInt($stmt, ':usr',  $usrVar);
        $this->ensureBindInt($stmt, ':acc',  $accVar);
        $this->ensureBindInt($stmt, ':cat',  $catVar);
        oci_bind_by_name($stmt, ':typ',  $typVar);
        oci_bind_by_name($stmt, ':amt',  $amtVar);
        oci_bind_by_name($stmt, ':d',    $dayVar);
        oci_bind_by_name($stmt, ':note', $noteVar);
        oci_bind_by_name($stmt, ':cr',   $crVar);
        oci_bind_by_name($stmt, ':up',   $upVar);
        $this->ensureBindInt($stmt, ':id',   $idVar);

        if (!oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) $this->ociFail($stmt, 'Insert txn');
        return (int)$idVar;
    }

    /* ---------------------------- misc ---------------------------- */
    public function getErrors(): array { return $this->errors; }
}
