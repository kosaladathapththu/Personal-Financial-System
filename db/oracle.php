<?php
/**
 * Oracle Database Connection Module
 * Path: pfms/db/oracle.php
 *
 * Features:
 *  - Connect via SERVICE (PDB) or SID
 *  - Optional ALTER SESSION SET CURRENT_SCHEMA=<schema>
 *  - Safe bind-by-name
 *  - Helpers: execute, fetch_all, query_all, tx, ping, status
 */

//////////////////////////////
// Availability / Basics
//////////////////////////////

/** Is OCI8 extension available? */
function oracle_is_available(): bool {
    return function_exists('oci_connect');
}

/** Close Oracle connection safely */
function oracle_close($conn): void {
    if ($conn) { @oci_close($conn); }
}

/** Build connection string depending on SERVICE or SID */
function oracle_build_conn_str(): ?string {
    $host    = defined('ORACLE_HOST')    ? constant('ORACLE_HOST')    : 'localhost';
    $port    = defined('ORACLE_PORT')    ? constant('ORACLE_PORT')    : '1521';
    $service = defined('ORACLE_SERVICE') ? trim((string)constant('ORACLE_SERVICE')) : '';
    $sid     = defined('ORACLE_SID')     ? trim((string)constant('ORACLE_SID'))     : '';

    if ($service !== '') {
        // Easy connect using SERVICE (recommended for XE PDBs, e.g., XEPDB1)
        return "//{$host}:{$port}/{$service}";
    }
    if ($sid !== '') {
        // Descriptor using SID
        return "(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST={$host})(PORT={$port}))"
             . "(CONNECT_DATA=(SID={$sid})))";
    }

    error_log('oracle_build_conn_str: Neither ORACLE_SERVICE nor ORACLE_SID is set.');
    return null;
}

//////////////////////////////
// Connect
//////////////////////////////

/**
 * Connect to Oracle and (optionally) set CURRENT_SCHEMA.
 * Returns: OCI connection resource or null on failure.
 */
function oracle_connect() {
    if (!oracle_is_available()) {
        error_log('oracle_connect: OCI8 extension not loaded.');
        return null;
    }

    $username = defined('ORACLE_USER') ? (string)constant('ORACLE_USER') : '';
    $password = defined('ORACLE_PASS') ? (string)constant('ORACLE_PASS') : '';

    if ($username === '' || $password === '') {
        error_log('oracle_connect: ORACLE_USER/ORACLE_PASS not set.');
        return null;
    }

    $connStr = oracle_build_conn_str();
    if (!$connStr) return null;

    try {
        $charset = defined('ORACLE_CHARSET') ? (string)constant('ORACLE_CHARSET') : 'AL32UTF8';

        $conn = @oci_connect($username, $password, $connStr, $charset);
        if (!$conn) {
            $e = oci_error();
            error_log('oracle_connect: ' . ($e['message'] ?? 'Unknown connection error'));
            return null;
        }

        // Optional CURRENT_SCHEMA
        if (defined('ORACLE_CURRENT_SCHEMA') && constant('ORACLE_CURRENT_SCHEMA')) {
            $schema = (string)constant('ORACLE_CURRENT_SCHEMA');
            $st = @oci_parse($conn, "ALTER SESSION SET CURRENT_SCHEMA={$schema}");
            if (!$st || !@oci_execute($st)) {
                $e = oci_error($st) ?: oci_error();
                error_log('oracle_connect: Set CURRENT_SCHEMA failed: ' . ($e['message'] ?? 'Unknown'));
                // Not fatal—continue with default schema
            }
        }

        return $conn;
    } catch (Throwable $th) {
        error_log('oracle_connect (exception): ' . $th->getMessage());
        return null;
    }
}

/** Tiny alias so old code calling oracle_conn() still works */
function oracle_conn() { return oracle_connect(); }

//////////////////////////////
// Execute & Fetch Helpers
//////////////////////////////

/**
 * Internal: bind params safely (must bind variables, not array elements).
 */
function _oracle_bind_params($stmt, array &$params): void {
    $refs = [];
    foreach ($params as $key => $val) {
        $var = $val;
        $refs[$key] = $var;

        if (is_int($var)) {
            oci_bind_by_name($stmt, $key, $refs[$key], -1, SQLT_INT);
        } elseif (is_float($var)) {
            oci_bind_by_name($stmt, $key, $refs[$key], -1, SQLT_FLT);
        } else {
            oci_bind_by_name($stmt, $key, $refs[$key]);
        }
    }
    $GLOBALS['__oci_stmt_refs__'][spl_object_id($stmt)] = $refs;
}

/** Execute SQL with optional bind params */
function oracle_execute($conn, string $sql, array $params = [], int $mode = OCI_COMMIT_ON_SUCCESS) {
    if (!$conn) {
        error_log('oracle_execute: Invalid connection');
        return false;
    }

    $stmt = @oci_parse($conn, $sql);
    if (!$stmt) {
        $e = oci_error($conn);
        error_log('oracle_execute (parse): ' . ($e['message'] ?? 'Unknown'));
        return false;
    }

    if (!empty($params)) {
        _oracle_bind_params($stmt, $params);
    }

    $ok = @oci_execute($stmt, $mode);
    
    unset($GLOBALS['__oci_stmt_refs__'][intval($stmt)]);


    if (!$ok) {
        $e = oci_error($stmt);
        error_log('oracle_execute (execute): ' . ($e['message'] ?? 'Unknown'));
        return false;
    }
    return $stmt;
}

/** Fetch all rows (assoc) */
function oracle_fetch_all($stmt): array {
    $rows = [];
    if ($stmt) {
        while ($row = oci_fetch_assoc($stmt)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/** Convenience: run + fetch all in one call */
function oracle_query_all($conn, string $sql, array $params = [], int $mode = OCI_COMMIT_ON_SUCCESS): array {
    $stmt = oracle_execute($conn, $sql, $params, $mode);
    return $stmt ? oracle_fetch_all($stmt) : [];
}

//////////////////////////////
// Transactions
//////////////////////////////

function oracle_begin($conn) { return true; }
function oracle_commit($conn): bool { return $conn ? @oci_commit($conn) : false; }
function oracle_rollback($conn): bool { return $conn ? @oci_rollback($conn) : false; }

//////////////////////////////
// Diagnostics / Status
//////////////////////////////

/** Quick ping */
function oracle_ping($conn): bool {
    if (!$conn) return false;
    $st = @oci_parse($conn, 'SELECT 1 FROM dual');
    return $st && @oci_execute($st) && (bool)oci_fetch_assoc($st);
}

/** Test a quick connect */
function oracle_test_connection(): bool {
    $c = oracle_connect();
    if ($c) { oracle_close($c); return true; }
    return false;
}

/** Get connection status details */
function oracle_get_status(): array {
    $status = [
        'extension_loaded' => oracle_is_available(),
        'can_connect'      => false,
        'oracle_version'   => null,
        'db_name'          => null,
        'con_name'         => null,
        'session_user'     => null,
        'current_schema'   => defined('ORACLE_CURRENT_SCHEMA') ? (string)constant('ORACLE_CURRENT_SCHEMA') : '',
        'error'            => null,
    ];

    if (!$status['extension_loaded']) {
        $status['error'] = 'OCI8 extension not loaded';
        return $status;
    }

    $conn = oracle_connect();
    if (!$conn) {
        $status['error'] = 'Cannot connect to Oracle database';
        return $status;
    }

    $status['can_connect'] = true;

    $vers = @oci_parse($conn, "SELECT banner FROM v\$version WHERE banner LIKE 'Oracle%'");
    if ($vers && @oci_execute($vers) && ($r = oci_fetch_assoc($vers))) {
        $status['oracle_version'] = $r['BANNER'] ?? null;
    }

    $who = @oci_parse($conn, "
        SELECT
          sys_context('USERENV','DB_NAME')      AS db_name,
          sys_context('USERENV','CON_NAME')     AS con_name,
          sys_context('USERENV','SESSION_USER') AS session_user
        FROM dual
    ");
    if ($who && @oci_execute($who) && ($r = oci_fetch_assoc($who))) {
        $status['db_name']      = $r['DB_NAME'] ?? null;
        $status['con_name']     = $r['CON_NAME'] ?? null;
        $status['session_user'] = $r['SESSION_USER'] ?? null;
    }

    oracle_close($conn);
    return $status;
}
