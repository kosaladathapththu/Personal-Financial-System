<?php
// app/reports/index_oracle.php
declare(strict_types=1);

require __DIR__ . '/../../config/env.php';
require __DIR__ . '/../../db/oracle.php'; // oracle_conn(): ?resource
require __DIR__ . '/../../db/sqlite.php'; // sqlite(): PDO
require __DIR__ . '/../auth/common/auth_guard.php';

function h($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }

/* ────────────────────────────────────────────────────────────────────────────
   DATE HELPERS  (Oracle uses DD-MM-YYYY; HTML <input type="date"> uses YYYY-MM-DD)
   ──────────────────────────────────────────────────────────────────────────── */
function norm_date(string $s, string $fallback): string {
    $s = trim($s);
    if ($s === '') return $fallback;
    foreach (['d-m-Y','Y-m-d','d/m/Y','m/d/Y','d.m.Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt) return $dt->format('d-m-Y'); // always DD-MM-YYYY
    }
    $ts = strtotime($s);
    return $ts ? date('d-m-Y', $ts) : $fallback;
}
function enforce_ddmmyyyy_or_default(string $s, string $default): string {
    $s = trim($s);
    return preg_match('/^\d{2}-\d{2}-\d{4}$/', $s) ? $s : $default;
}
function ddmmyyyy_plus1(string $ddmmyyyy): string {
    $d = DateTime::createFromFormat('d-m-Y', $ddmmyyyy);
    $d->modify('+1 day');
    return $d->format('d-m-Y');
}
function ddmmyyyy_to_ymd(string $ddmmyyyy): string {
    $d = DateTime::createFromFormat('d-m-Y', $ddmmyyyy);
    return $d ? $d->format('Y-m-d') : '';
}

/* ────────────────────────────────────────────────────────────────────────────
   DB CHOOSER
   ──────────────────────────────────────────────────────────────────────────── */
function try_oracle(): ?array {
    $c = @oracle_conn();
    if (!$c) return null;
    $s = @oci_parse($c, "SELECT 1 FROM DUAL");
    if (!$s || !@oci_execute($s)) return null;
    return ['type'=>'oracle','conn'=>$c];
}
function use_sqlite(): array {
    $pdo = sqlite();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return ['type'=>'sqlite','conn'=>$pdo];
}

/* ────────────────────────────────────────────────────────────────────────────
   QUERY HELPER — keep distinct refs for each bind (OCI8 binds by reference)
   ──────────────────────────────────────────────────────────────────────────── */
function db_query(array $db, string $sql, array $binds = []): array {
    preg_match_all('/:([A-Z0-9_]+)/i', $sql, $m);
    $placeholders = array_change_key_case(array_flip($m[1] ?? []), CASE_UPPER);

    if ($db['type'] === 'oracle') {
        $stmt = oci_parse($db['conn'], $sql);
        if (!$stmt) { $e=oci_error($db['conn']); throw new RuntimeException("Oracle parse error: ".($e['message']??'unknown')); }

        $bindStore = []; // keep variables alive per placeholder

        foreach ($binds as $k => $v) {
            $K = strtoupper(preg_replace('/[^A-Z0-9_]/i','',(string)$k));
            if (!isset($placeholders[$K])) continue;
            $name = ':' . $K;

            if (in_array($K, ['P_FROM','P_TO','P_TO1'], true)) {
                $val = substr(trim((string)$v), 0, 10);
                if (strlen($val) !== 10) {
                    throw new RuntimeException("Bad date for $name: '$val' (expected DD-MM-YYYY)");
                }
                $bindStore[$name] = $val;
                oci_bind_by_name($stmt, $name, $bindStore[$name], 10, SQLT_CHR);
            } elseif (is_int($v)) {
                $bindStore[$name] = (int)$v;
                oci_bind_by_name($stmt, $name, $bindStore[$name], -1, SQLT_INT);
            } elseif (is_float($v)) {
                $bindStore[$name] = (float)$v;
                oci_bind_by_name($stmt, $name, $bindStore[$name], -1, SQLT_FLT);
            } else {
                $bindStore[$name] = trim((string)$v);
                oci_bind_by_name($stmt, $name, $bindStore[$name]);
            }
        }

        if (!oci_execute($stmt)) { $e=oci_error($stmt); throw new RuntimeException("Oracle execute error: ".($e['message']??'unknown')); }
        $rows=[]; while ($r=oci_fetch_assoc($stmt)) $rows[]=$r; return $rows;

    } else {
        $stmt = $db['conn']->prepare($sql);
        foreach ($binds as $k=>$v) {
            $K = strtoupper(preg_replace('/[^A-Z0-9_]/i','',(string)$k));
            if (!isset($placeholders[$K])) continue;
            $stmt->bindValue(':'.$K, $v);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ────────────────────────────────────────────────────────────────────────────
   INPUTS
   ──────────────────────────────────────────────────────────────────────────── */
$from   = norm_date($_GET['from'] ?? '', date('01-01-Y')); // DD-MM-YYYY
$to     = norm_date($_GET['to']   ?? '', date('d-m-Y'));   // DD-MM-YYYY
$uid    = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;

$from = enforce_ddmmyyyy_or_default($from, date('01-01-Y'));
$to   = enforce_ddmmyyyy_or_default($to,   date('d-m-Y'));

$fromYmd   = ddmmyyyy_to_ymd($from);            // for HTML input and SQLite
$to_html   = ddmmyyyy_to_ymd($to);
$toPlusYmd = ddmmyyyy_to_ymd(ddmmyyyy_plus1($to));

/* ────────────────────────────────────────────────────────────────────────────
   PICK DB
   ──────────────────────────────────────────────────────────────────────────── */
$db = try_oracle();
$fallback = false;
if (!$db) { $db = use_sqlite(); $fallback = true; }

/* ────────────────────────────────────────────────────────────────────────────
   QUERIES  (Oracle first, then SQLite)
   ──────────────────────────────────────────────────────────────────────────── */
$probe = []; // for debug panel

if ($db['type'] === 'oracle') {
    // Bind set
    $B = ['P_FROM'=>$from, 'P_TO'=>$to];
    $userFilter = '';
    $userWhere  = '';
    if ($uid > 0) { $B['P_UID'] = $uid; $userFilter = " AND t.user_server_id = :P_UID"; $userWhere = "WHERE u.server_user_id = :P_UID"; }

    // DEBUG PROBE — show which user_server_id actually has txns in range
    $SQL_USER_PROBE = "
        SELECT t.user_server_id, COUNT(*) AS CNT,
               MIN(t.txn_date) AS MIN_DT, MAX(t.txn_date) AS MAX_DT
        FROM TRANSACTIONS_CLOUD t
        WHERE t.txn_date >= TO_DATE(:P_FROM,'DD-MM-YYYY')
          AND t.txn_date <  TO_DATE(:P_TO,'DD-MM-YYYY') + 1
        GROUP BY t.user_server_id
        ORDER BY CNT DESC
    ";
    $probe = db_query($db, $SQL_USER_PROBE, $B);

    // OVERALL
    $SQL_OVERALL = "
        SELECT
          COUNT(t.server_txn_id) AS CNT,
          SUM(CASE WHEN t.txn_type='INCOME'  THEN t.amount END) AS INCOME,
          SUM(CASE WHEN t.txn_type='EXPENSE' THEN t.amount END) AS EXPENSE,
          MIN(t.txn_date) AS FIRST_TXN,
          MAX(t.txn_date) AS LAST_TXN
        FROM TRANSACTIONS_CLOUD t
        WHERE t.txn_date >= TO_DATE(:P_FROM,'DD-MM-YYYY')
          AND t.txn_date <  TO_DATE(:P_TO,'DD-MM-YYYY') + 1
          $userFilter
    ";

    // USERS
    $SQL_USERS = "
        SELECT
          u.server_user_id AS SERVER_USER_ID,
          u.email          AS EMAIL,
          u.full_name      AS FULL_NAME,
          COUNT(t.server_txn_id) AS TXN_COUNT,
          MIN(t.txn_date)  AS FIRST_TXN,
          MAX(t.txn_date)  AS LAST_TXN
        FROM USERS_CLOUD u
        LEFT JOIN TRANSACTIONS_CLOUD t
          ON t.user_server_id = u.server_user_id
         AND t.txn_date >= TO_DATE(:P_FROM,'DD-MM-YYYY')
         AND t.txn_date <  TO_DATE(:P_TO,'DD-MM-YYYY') + 1
        $userWhere
        GROUP BY u.server_user_id, u.email, u.full_name
        ORDER BY TXN_COUNT DESC, u.server_user_id
    ";

    // ACCOUNTS — anchor on ACCOUNTS so rows show even if zero txns in range
    if ($uid > 0) {
        $SQL_ACCOUNTS = "
            SELECT
              a.account_name                         AS ACCOUNT_NAME,
              NVL(a.opening_balance,0)               AS OPENING_BALANCE,
              NVL(SUM(CASE WHEN t.txn_type='INCOME'  THEN t.amount END),0) AS INC_AMT,
              NVL(SUM(CASE WHEN t.txn_type='EXPENSE' THEN t.amount END),0) AS EXP_AMT
            FROM ACCOUNTS_CLOUD a
            LEFT JOIN TRANSACTIONS_CLOUD t
              ON t.account_server_id = a.server_account_id
             AND t.user_server_id    = a.user_server_id
             AND t.txn_date >= TO_DATE(:P_FROM,'DD-MM-YYYY')
             AND t.txn_date <  TO_DATE(:P_TO,'DD-MM-YYYY') + 1
            WHERE a.user_server_id = :P_UID
            GROUP BY a.account_name, NVL(a.opening_balance,0)
            ORDER BY a.account_name
        ";
    } else {
        $SQL_ACCOUNTS = "
            SELECT
              NVL(a.account_name,'Unknown')          AS ACCOUNT_NAME,
              NVL(a.opening_balance,0)               AS OPENING_BALANCE,
              NVL(SUM(CASE WHEN t.txn_type='INCOME'  THEN t.amount END),0) AS INC_AMT,
              NVL(SUM(CASE WHEN t.txn_type='EXPENSE' THEN t.amount END),0) AS EXP_AMT
            FROM ACCOUNTS_CLOUD a
            LEFT JOIN TRANSACTIONS_CLOUD t
              ON t.account_server_id = a.server_account_id
             AND t.user_server_id    = a.user_server_id
             AND t.txn_date >= TO_DATE(:P_FROM,'DD-MM-YYYY')
             AND t.txn_date <  TO_DATE(:P_TO,'DD-MM-YYYY') + 1
            GROUP BY NVL(a.account_name,'Unknown'), NVL(a.opening_balance,0)
            ORDER BY ACCOUNT_NAME
        ";
    }

    $overall  = db_query($db, $SQL_OVERALL,  $B);
    $users    = db_query($db, $SQL_USERS,    $B);
    $accounts = db_query($db, $SQL_ACCOUNTS, $B);

} else {
    // SQLITE FALLBACK
    $B = ['P_FROM'=>$fromYmd, 'P_TO1'=>$toPlusYmd];
    $filter = '';
    if ($uid > 0) { $B['P_UID'] = $uid; $filter = " AND t.user_local_id = :P_UID"; }

    $SQL_OVERALL = "
        SELECT
          COUNT(*) AS CNT,
          SUM(CASE WHEN t.txn_type='INCOME'  THEN t.amount ELSE 0 END) AS INCOME,
          SUM(CASE WHEN t.txn_type='EXPENSE' THEN t.amount ELSE 0 END) AS EXPENSE,
          MIN(substr(t.txn_date,1,10)) AS FIRST_TXN,
          MAX(substr(t.txn_date,1,10)) AS LAST_TXN
        FROM TRANSACTIONS_LOCAL t
        WHERE date(substr(t.txn_date,1,10)) >= date(:P_FROM)
          AND date(substr(t.txn_date,1,10)) <  date(:P_TO1)
          $filter
    ";

    $SQL_USERS = "
        SELECT
          u.local_user_id  AS SERVER_USER_ID,
          u.email          AS EMAIL,
          u.full_name      AS FULL_NAME,
          COUNT(t.rowid)   AS TXN_COUNT,
          MIN(substr(t.txn_date,1,10)) AS FIRST_TXN,
          MAX(substr(t.txn_date,1,10)) AS LAST_TXN
        FROM USERS_LOCAL u
        LEFT JOIN TRANSACTIONS_LOCAL t
          ON t.user_local_id = u.local_user_id
         AND date(substr(t.txn_date,1,10)) >= date(:P_FROM)
         AND date(substr(t.txn_date,1,10)) <  date(:P_TO1)
        " . ($uid>0 ? "WHERE u.local_user_id = :P_UID" : "") . "
        GROUP BY u.local_user_id, u.email, u.full_name
        ORDER BY TXN_COUNT DESC, u.local_user_id
    ";

    $SQL_ACCOUNTS = "
        SELECT
          IFNULL(a.account_name,'Unknown') AS ACCOUNT_NAME,
          IFNULL(a.opening_balance,0)      AS OPENING_BALANCE,
          SUM(CASE WHEN t.txn_type='INCOME'  THEN t.amount ELSE 0 END) AS INC_AMT,
          SUM(CASE WHEN t.txn_type='EXPENSE' THEN t.amount ELSE 0 END) AS EXP_AMT
        FROM ACCOUNTS_LOCAL a
        LEFT JOIN TRANSACTIONS_LOCAL t
          ON t.account_local_id = a.local_account_id
         AND date(substr(t.txn_date,1,10)) >= date(:P_FROM)
         AND date(substr(t.txn_date,1,10)) <  date(:P_TO1)
        " . ($uid>0 ? "WHERE a.user_local_id = :P_UID" : "") . "
        GROUP BY IFNULL(a.account_name,'Unknown'), IFNULL(a.opening_balance,0)
        ORDER BY ACCOUNT_NAME
    ";

    $overall  = db_query($db, $SQL_OVERALL,  $B);
    $users    = db_query($db, $SQL_USERS,    $B);
    $accounts = db_query($db, $SQL_ACCOUNTS, $B);
}

/* ────────────────────────────────────────────────────────────────────────────
   TOTALS
   ──────────────────────────────────────────────────────────────────────────── */
$totOpen=$totInc=$totExp=$totBal=0.0;
foreach ($accounts as $r) {
    $open=(float)($r['OPENING_BALANCE']??0);
    $inc =(float)($r['INC_AMT']??0);
    $exp =(float)($r['EXP_AMT']??0);
    $bal =$open+$inc-$exp;
    $totOpen+=$open; $totInc+=$inc; $totExp+=$exp; $totBal+=$bal;
}
$ov = [
  'CNT'       => (int)($overall[0]['CNT'] ?? 0),
  'INCOME'    => (float)($overall[0]['INCOME'] ?? 0),
  'EXPENSE'   => (float)($overall[0]['EXPENSE'] ?? 0),
  'FIRST_TXN' => (string)($overall[0]['FIRST_TXN'] ?? ''),
  'LAST_TXN'  => (string)($overall[0]['LAST_TXN'] ?? ''),
];

// for <input type="date">
$from_html = ddmmyyyy_to_ymd($from);
$to_html   = ddmmyyyy_to_ymd($to);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Reports Dashboard - PFMS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<link rel="stylesheet" href="<?= APP_BASE ?>/public/dashboard.css">
<style>
  /* Enhanced Reports Styles */
  .reports-hero{background:linear-gradient(135deg,#667eea,#764ba2);padding:32px;border-radius:20px;color:#fff;box-shadow:0 20px 60px rgba(102,126,234,.3);margin-bottom:24px;position:relative;overflow:hidden}
  .reports-hero::before{content:'';position:absolute;top:-50%;right:-10%;width:500px;height:500px;background:radial-gradient(circle,rgba(255,255,255,.1) 0%,transparent 70%);animation:pulse 8s ease-in-out infinite}
  .reports-hero-content{position:relative;z-index:2}
  
  .filter-panel{background:#fff;border-radius:20px;padding:24px;box-shadow:0 10px 40px rgba(0,0,0,.05);border:1px solid #e5e7eb;margin-bottom:24px}
  .filter-row{display:flex;gap:16px;align-items:end;flex-wrap:wrap}
  .filter-group{flex:1;min-width:200px}
  .filter-group label{display:block;margin-bottom:8px;color:#374151;font-weight:600;font-size:.875rem}
  .filter-group input{width:100%;padding:12px;border:2px solid #e5e7eb;border-radius:12px;font-size:1rem;transition:all .3s}
  .filter-group input:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.1)}
  
  .btn-filter{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;padding:12px 24px;border-radius:12px;font-weight:700;cursor:pointer;transition:all .3s;display:inline-flex;align-items:center;gap:8px}
  .btn-filter:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(102,126,234,.4)}
  .btn-reset{background:#fff;color:#667eea;border:2px solid #667eea;padding:12px 24px;border-radius:12px;font-weight:700;cursor:pointer;transition:all .3s}
  .btn-reset:hover{background:#667eea;color:#fff}
  .btn-download{background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;padding:12px 24px;border-radius:12px;font-weight:700;cursor:pointer;transition:all .3s;display:inline-flex;align-items:center;gap:8px}
  .btn-download:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(16,185,129,.4)}
  
  .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;margin:24px 0}
  .stat-card{background:#fff;border-radius:20px;padding:24px;box-shadow:0 4px 20px rgba(0,0,0,.06);border:1px solid #e5e7eb;transition:all .3s;position:relative;overflow:hidden}
  .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px}
  .stat-card:hover{transform:translateY(-5px);box-shadow:0 12px 40px rgba(0,0,0,.1)}
  .stat-card.income::before{background:linear-gradient(90deg,#10b981,#059669)}
  .stat-card.expense::before{background:linear-gradient(90deg,#ef4444,#dc2626)}
  .stat-card.net::before{background:linear-gradient(90deg,#3b82f6,#2563eb)}
  .stat-card.count::before{background:linear-gradient(90deg,#667eea,#764ba2)}
  
  .stat-icon{width:56px;height:56px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin-bottom:16px}
  .stat-card.income .stat-icon{background:linear-gradient(135deg,#10b981,#059669);color:#fff;box-shadow:0 8px 24px rgba(16,185,129,.3)}
  .stat-card.expense .stat-icon{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 8px 24px rgba(239,68,68,.3)}
  .stat-card.net .stat-icon{background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;box-shadow:0 8px 24px rgba(59,130,246,.3)}
  .stat-card.count .stat-icon{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;box-shadow:0 8px 24px rgba(102,126,234,.3)}
  
  .stat-value{font-size:2rem;font-weight:800;color:#111827;line-height:1;margin-bottom:8px}
  .stat-label{color:#6b7280;font-size:.875rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
  
  .report-card{background:#fff;border-radius:20px;padding:24px;box-shadow:0 4px 20px rgba(0,0,0,.06);border:1px solid #e5e7eb;margin-bottom:24px}
  .report-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:16px;border-bottom:2px solid #e5e7eb}
  .report-title{font-size:1.5rem;font-weight:700;color:#111827;display:flex;align-items:center;gap:12px}
  
  .data-table{width:100%;border-collapse:separate;border-spacing:0;margin-top:16px}
  .data-table thead tr{background:linear-gradient(135deg,#667eea,#764ba2)}
  .data-table th{padding:16px;text-align:left;color:#fff;font-weight:700;font-size:.875rem;text-transform:uppercase;letter-spacing:.05em}
  .data-table th:first-child{border-radius:12px 0 0 0}
  .data-table th:last-child{border-radius:0 12px 0 0}
  .data-table tbody tr{background:#fff;transition:all .3s}
  .data-table tbody tr:hover{background:#f9fafb;transform:translateX(5px)}
  .data-table td{padding:16px;border-bottom:1px solid #e5e7eb;color:#374151}
  .data-table tbody tr:last-child td{border-bottom:none}
  .data-table tfoot tr{background:#f9fafb;font-weight:700}
  .data-table tfoot td{padding:16px;border-top:2px solid #667eea;color:#111827}
  
  .badge-db{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:100px;font-weight:700;font-size:.875rem}
  .badge-db.oracle{background:rgba(16,185,129,.1);color:#10b981;border:2px solid rgba(16,185,129,.3)}
  .badge-db.sqlite{background:rgba(245,158,11,.1);color:#f59e0b;border:2px solid rgba(245,158,11,.3)}
  
  .debug-panel{background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:20px;padding:24px;border:2px solid #fbbf24;margin-bottom:24px}
  .debug-panel h3{color:#92400e;margin:0 0 16px 0;display:flex;align-items:center;gap:8px}
  
  .empty-state{text-align:center;padding:48px 24px;background:#f9fafb;border-radius:16px;border:2px dashed #e5e7eb}
  .empty-state i{font-size:3rem;color:#d1d5db;margin-bottom:16px}
  
  @keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.8;transform:scale(1.05)}}
  
  @media(max-width:768px){
    .filter-row{flex-direction:column}
    .filter-group{width:100%}
    .data-table{font-size:.875rem}
    .data-table th,.data-table td{padding:12px 8px}
  }
  
  @media print{
    .sidebar,.filter-panel,.btn-download{display:none}
    .report-card{box-shadow:none;page-break-inside:avoid}
  }
</style>
</head>
<body>

<div class="app-container">
  
  <!-- Sidebar Navigation -->
  <aside class="sidebar">
    <div class="logo">
      <i class="fas fa-chart-line"></i>
      <span>PFMS</span>
    </div>
    
    <nav class="nav-menu">
      <a href="<?= APP_BASE ?>/public/dashboard.php" class="nav-item">
        <i class="fas fa-home"></i>
        <span>Dashboard</span>
      </a>
      <a href="<?= APP_BASE ?>/app/auth/accounts/index.php" class="nav-item">
        <i class="fas fa-wallet"></i>
        <span>Accounts</span>
      </a>
      <a href="<?= APP_BASE ?>/app/categories/index.php" class="nav-item">
        <i class="fas fa-tags"></i>
        <span>Categories</span>
      </a>
      <a href="<?= APP_BASE ?>/app/transactions/index.php" class="nav-item">
        <i class="fas fa-exchange-alt"></i>
        <span>Transactions</span>
      </a>
      <a href="<?= APP_BASE ?>/app/reports/index.php" class="nav-item active">
        <i class="fas fa-chart-bar"></i>
        <span>Reports</span>
      </a>
      <a href="<?= APP_BASE ?>/public/sync.php" class="nav-item">
        <i class="fas fa-sync-alt"></i>
        <span>Sync</span>
      </a>
    </nav>
    
    <div class="sidebar-footer">
      <a href="<?= APP_BASE ?>/public/logout.php" class="logout-link">
        <i class="fas fa-sign-out-alt"></i>
        <span>Logout</span>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="main-content">

    <!-- Hero Section -->
    <div class="reports-hero">
      <div class="reports-hero-content">
        <h1 style="margin:0 0 8px 0;font-size:2.5rem;font-weight:800;text-shadow:0 2px 10px rgba(0,0,0,.1)">
          <i class="fas fa-chart-bar"></i> Reports Dashboard
        </h1>
        <p style="margin:0 0 16px 0;opacity:.95;font-size:1.125rem">
          Comprehensive financial reports and analytics
        </p>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
          <span class="badge-db <?= $db['type'] ?>">
            <i class="fas fa-<?= $db['type']==='oracle' ? 'database' : 'laptop' ?>"></i>
            <?= $db['type']==='oracle' ? 'Oracle Cloud' : 'SQLite (Fallback)' ?>
          </span>
          <span style="color:rgba(255,255,255,.9);font-size:.875rem">
            <i class="fas fa-calendar-alt"></i> <?= h($from) ?> to <?= h($to) ?>
          </span>
          <?php if ($uid > 0): ?>
          <span style="color:rgba(255,255,255,.9);font-size:.875rem">
            <i class="fas fa-user"></i> User ID: <?= $uid ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Filter Panel -->
    <div class="filter-panel">
      <form method="get" class="filter-row">
        <div class="filter-group">
          <label><i class="fas fa-calendar-day"></i> From Date</label>
          <input type="date" name="from" value="<?= h($from_html) ?>" required>
        </div>
        
        <div class="filter-group">
          <label><i class="fas fa-calendar-day"></i> To Date</label>
          <input type="date" name="to" value="<?= h($to_html) ?>" required>
        </div>
        
        <div class="filter-group">
          <label><i class="fas fa-user"></i> User ID (Optional)</label>
          <input type="number" name="uid" min="0" step="1" value="<?= $uid > 0 ? $uid : '' ?>" placeholder="All users">
        </div>
        
        <div style="display:flex;gap:12px;align-items:end">
          <button type="submit" class="btn-filter">
            <i class="fas fa-filter"></i> Apply Filters
          </button>
          <a href="<?= APP_BASE ?>/app/reports/index_oracle.php" class="btn-reset" style="text-decoration:none;display:inline-flex;align-items:center;gap:8px">
            <i class="fas fa-redo"></i> Reset
          </a>
          <button type="button" class="btn-download" onclick="downloadReport()">
            <i class="fas fa-download"></i> Export
          </button>
        </div>
      </form>
    </div>

    <!-- Statistics Grid -->
    <div class="stats-grid">
      <div class="stat-card count">
        <div class="stat-icon">
          <i class="fas fa-list"></i>
        </div>
        <div class="stat-value"><?= number_format($ov['CNT']) ?></div>
        <div class="stat-label">Total Transactions</div>
      </div>
      
      <div class="stat-card income">
        <div class="stat-icon">
          <i class="fas fa-arrow-down"></i>
        </div>
        <div class="stat-value">$<?= number_format($ov['INCOME'], 2) ?></div>
        <div class="stat-label">Total Income</div>
      </div>
      
      <div class="stat-card expense">
        <div class="stat-icon">
          <i class="fas fa-arrow-up"></i>
        </div>
        <div class="stat-value">$<?= number_format($ov['EXPENSE'], 2) ?></div>
        <div class="stat-label">Total Expense</div>
      </div>
      
      <div class="stat-card net">
        <div class="stat-icon">
          <i class="fas fa-balance-scale"></i>
        </div>
        <div class="stat-value" style="color:<?= ($ov['INCOME'] - $ov['EXPENSE']) >= 0 ? '#10b981' : '#ef4444' ?>">
          $<?= number_format($ov['INCOME'] - $ov['EXPENSE'], 2) ?>
        </div>
        <div class="stat-label">Net Balance</div>
      </div>
    </div>

    <!-- Debug Panel (Oracle only) -->
    <?php if ($db['type'] === 'oracle'): ?>
    <div class="debug-panel">
      <h3><i class="fas fa-bug"></i> Debug Information</h3>
      <div style="background:#fff;padding:16px;border-radius:12px;margin-bottom:12px">
        <p style="margin:0 0 8px 0;color:#374151"><strong>Bind Parameters:</strong></p>
        <p style="margin:0;color:#6b7280;font-family:monospace;font-size:.875rem">
          FROM: <code style="background:#f3f4f6;padding:4px 8px;border-radius:6px"><?= h($from) ?></code> · 
          TO: <code style="background:#f3f4f6;padding:4px 8px;border-radius:6px"><?= h($to) ?></code> · 
          UID: <code style="background:#f3f4f6;padding:4px 8px;border-radius:6px"><?= $uid ?: 'All' ?></code>
        </p>
      </div>
      
      <div style="background:#fff;padding:16px;border-radius:12px">
        <p style="margin:0 0 12px 0;color:#374151"><strong>User Transactions in Date Range:</strong></p>
        <?php if (empty($probe)): ?>
          <div class="empty-state" style="padding:24px">
            <i class="fas fa-inbox"></i>
            <p style="color:#6b7280;margin:8px 0 0 0">No transactions found in this date range</p>
          </div>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>User ID</th>
                <th>Transaction Count</th>
                <th>First Date</th>
                <th>Last Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($probe as $p): ?>
              <tr>
                <td><?= h($p['USER_SERVER_ID']) ?></td>
                <td><strong><?= number_format((int)$p['CNT']) ?></strong></td>
                <td><?= h($p['MIN_DT']) ?></td>
                <td><?= h($p['MAX_DT']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Overall Transactions Report -->
    <div class="report-card">
      <div class="report-header">
        <h3 class="report-title">
          <i class="fas fa-chart-line" style="color:#667eea"></i>
          Overall Transaction Summary
        </h3>
      </div>
      
      <table class="data-table">
        <thead>
          <tr>
            <th>Total Transactions</th>
            <th>Income</th>
            <th>Expense</th>
            <th>Net Amount</th>
            <th>First Transaction</th>
            <th>Last Transaction</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><strong><?= number_format($ov['CNT']) ?></strong></td>
            <td style="color:#10b981"><strong>$<?= number_format($ov['INCOME'], 2) ?></strong></td>
            <td style="color:#ef4444"><strong>$<?= number_format($ov['EXPENSE'], 2) ?></strong></td>
            <td style="color:<?= ($ov['INCOME'] - $ov['EXPENSE']) >= 0 ? '#10b981' : '#ef4444' ?>">
              <strong>$<?= number_format($ov['INCOME'] - $ov['EXPENSE'], 2) ?></strong>
            </td>
            <td><?= h($ov['FIRST_TXN']) ?: 'N/A' ?></td>
            <td><?= h($ov['LAST_TXN']) ?: 'N/A' ?></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- User Details Report -->
    <div class="report-card">
      <div class="report-header">
        <h3 class="report-title">
          <i class="fas fa-users" style="color:#667eea"></i>
          User Activity Report
        </h3>
        <span style="background:rgba(102,126,234,.1);color:#667eea;padding:8px 16px;border-radius:100px;font-weight:700;font-size:.875rem">
          <?= count($users) ?> Users
        </span>
      </div>
      
      <?php if (empty($users)): ?>
        <div class="empty-state">
          <i class="fas fa-user-slash"></i>
          <h4 style="color:#6b7280;margin:8px 0">No Users Found</h4>
          <p style="color:#9ca3af;font-size:.875rem">No user data available for the selected period</p>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>User ID</th>
              <th>Email</th>
              <th>Full Name</th>
              <th>Transaction Count</th>
              <th>First Transaction</th>
              <th>Last Transaction</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
              <td><?= h($u['SERVER_USER_ID']) ?></td>
              <td><?= h($u['EMAIL']) ?></td>
              <td><?= h($u['FULL_NAME']) ?></td>
              <td><strong><?= number_format((int)($u['TXN_COUNT'] ?? 0)) ?></strong></td>
              <td><?= h((string)($u['FIRST_TXN'] ?? 'N/A')) ?></td>
              <td><?= h((string)($u['LAST_TXN'] ?? 'N/A')) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Account Details Report -->
    <div class="report-card">
      <div class="report-header">
        <h3 class="report-title">
          <i class="fas fa-wallet" style="color:#667eea"></i>
          Account Balance Report
        </h3>
        <span style="background:rgba(102,126,234,.1);color:#667eea;padding:8px 16px;border-radius:100px;font-weight:700;font-size:.875rem">
          <?= count($accounts) ?> Accounts
        </span>
      </div>
      
      <?php if (empty($accounts)): ?>
        <div class="empty-state">
          <i class="fas fa-wallet"></i>
          <h4 style="color:#6b7280;margin:8px 0">No Accounts Found</h4>
          <p style="color:#9ca3af;font-size:.875rem">No account data available for the selected period</p>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Account Name</th>
              <th>Opening Balance</th>
              <th>Income</th>
              <th>Expense</th>
              <th>Current Balance</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($accounts as $a): 
              $open = (float)($a['OPENING_BALANCE'] ?? 0);
              $inc = (float)($a['INC_AMT'] ?? 0);
              $exp = (float)($a['EXP_AMT'] ?? 0);
              $bal = $open + $inc - $exp;
            ?>
            <tr>
              <td><strong><?= h((string)($a['ACCOUNT_NAME'] ?? 'Unknown')) ?></strong></td>
              <td>$<?= number_format($open, 2) ?></td>
              <td style="color:#10b981">$<?= number_format($inc, 2) ?></td>
              <td style="color:#ef4444">$<?= number_format($exp, 2) ?></td>
              <td style="color:<?= $bal >= 0 ? '#10b981' : '#ef4444' ?>">
                <strong>$<?= number_format($bal, 2) ?></strong>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td><strong>TOTAL</strong></td>
              <td><strong>$<?= number_format($totOpen, 2) ?></strong></td>
              <td style="color:#10b981"><strong>$<?= number_format($totInc, 2) ?></strong></td>
              <td style="color:#ef4444"><strong>$<?= number_format($totExp, 2) ?></strong></td>
              <td style="color:<?= $totBal >= 0 ? '#10b981' : '#ef4444' ?>">
                <strong>$<?= number_format($totBal, 2) ?></strong>
              </td>
            </tr>
          </tfoot>
        </table>
      <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="report-card">
      <div class="report-header">
        <h3 class="report-title">
          <i class="fas fa-bolt" style="color:#667eea"></i>
          Quick Actions
        </h3>
      </div>
      
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <button onclick="window.print()" class="btn-filter">
          <i class="fas fa-print"></i> Print Report
        </button>
        <button onclick="downloadReport('csv')" class="btn-download">
          <i class="fas fa-file-csv"></i> Export CSV
        </button>
        <button onclick="downloadReport('pdf')" class="btn-download">
          <i class="fas fa-file-pdf"></i> Export PDF
        </button>
        <a href="<?= APP_BASE ?>/public/dashboard.php" class="btn-reset" style="text-decoration:none;display:inline-flex;align-items:center;gap:8px">
          <i class="fas fa-home"></i> Back to Dashboard
        </a>
      </div>
    </div>

  </main>
</div>

<script>
// Download Report Function
function downloadReport(format = 'csv') {
  format = format || 'csv';
  
  if (format === 'csv') {
    // Generate CSV data
    let csv = 'PFMS Financial Report\n';
    csv += 'Generated: ' + new Date().toLocaleString() + '\n';
    csv += 'Period: <?= h($from) ?> to <?= h($to) ?>\n';
    csv += 'Database: <?= $db['type'] ?>\n\n';
    
    // Overall Summary
    csv += 'OVERALL SUMMARY\n';
    csv += 'Total Transactions,Income,Expense,Net Amount\n';
    csv += '<?= $ov['CNT'] ?>,<?= number_format($ov['INCOME'], 2) ?>,<?= number_format($ov['EXPENSE'], 2) ?>,<?= number_format($ov['INCOME'] - $ov['EXPENSE'], 2) ?>\n\n';
    
    // User Details
    csv += 'USER ACTIVITY\n';
    csv += 'User ID,Email,Full Name,Transaction Count,First Transaction,Last Transaction\n';
    <?php foreach ($users as $u): ?>
    csv += '<?= h($u['SERVER_USER_ID']) ?>,<?= h($u['EMAIL']) ?>,<?= h($u['FULL_NAME']) ?>,<?= (int)($u['TXN_COUNT'] ?? 0) ?>,<?= h((string)($u['FIRST_TXN'] ?? '')) ?>,<?= h((string)($u['LAST_TXN'] ?? '')) ?>\n';
    <?php endforeach; ?>
    csv += '\n';
    
    // Account Details
    csv += 'ACCOUNT BALANCE REPORT\n';
    csv += 'Account Name,Opening Balance,Income,Expense,Current Balance\n';
    <?php foreach ($accounts as $a): 
      $open = (float)($a['OPENING_BALANCE'] ?? 0);
      $inc = (float)($a['INC_AMT'] ?? 0);
      $exp = (float)($a['EXP_AMT'] ?? 0);
      $bal = $open + $inc - $exp;
    ?>
    csv += '<?= h((string)($a['ACCOUNT_NAME'] ?? 'Unknown')) ?>,<?= number_format($open, 2) ?>,<?= number_format($inc, 2) ?>,<?= number_format($exp, 2) ?>,<?= number_format($bal, 2) ?>\n';
    <?php endforeach; ?>
    csv += 'TOTAL,<?= number_format($totOpen, 2) ?>,<?= number_format($totInc, 2) ?>,<?= number_format($totExp, 2) ?>,<?= number_format($totBal, 2) ?>\n';
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'PFMS_Report_' + new Date().toISOString().split('T')[0] + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
    
    // Show success notification
    alert('✅ Report exported successfully as CSV!');
  } else if (format === 'pdf') {
    // For PDF, trigger print dialog (browser will handle PDF generation)
    alert('💡 Use your browser\'s Print function and select "Save as PDF" as the destination.');
    window.print();
  }
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
  // Ctrl/Cmd + P for print
  if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
    e.preventDefault();
    window.print();
  }
  // Ctrl/Cmd + E for export
  if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
    e.preventDefault();
    downloadReport('csv');
  }
});

// Smooth animations on load
document.addEventListener('DOMContentLoaded', function() {
  const cards = document.querySelectorAll('.stat-card, .report-card');
  cards.forEach((card, index) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(20px)';
    setTimeout(() => {
      card.style.transition = 'all 0.5s ease';
      card.style.opacity = '1';
      card.style.transform = 'translateY(0)';
    }, index * 100);
  });
});

// Console branding
console.log('%c📊 PFMS Reports Dashboard', 'color: #667eea; font-size: 20px; font-weight: bold;');
console.log('%cDatabase: <?= $db['type'] ?>', 'color: <?= $db['type']==='oracle' ? '#10b981' : '#f59e0b' ?>; font-size: 14px;');
console.log('%cShortcuts: Ctrl+P (Print) | Ctrl+E (Export)', 'color: #6b7280; font-size: 12px;');
</script>

</body>
</html>