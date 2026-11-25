<?php
// STY-03 (Steps 111, 112, 113): Read-only dual-DB table count + content-hash auditor,
// Control App mismatch alert writer (control.hohedoc.ir/var/db-parity-state.json),
// and automated 7-day consecutive daily ledger (var/db-parity-7day.json).
// Zero hardcoded hostnames or DB names — reads primary from sites.php and standby from holding-vault.

const DB_PARITY_INTERVAL_SEC  = 1800; // run full count+hash audit every 30 minutes (and on first tick)
const DB_PARITY_ALERT_CONSEC  = 2;    // 2 consecutive mismatches before firing alert
const DB_PARITY_ALERT_COOLDOWN = 3600; // 60-minute cooldown between repeated mismatch alerts

/**
 * 12 core business & integrity tables for deep COUNT(*) + MAX(pk) + BIT_XOR(CRC32(...)) content hash.
 * Each spec: [pk_col, hash_cols_array].
 */
const DB_PARITY_HASH_SPECS = [
    'factor'          => ['id', ['id', 'company_id', 'staff_id', 'customer_id', 'status', 'created_at']],
    'factor_item'     => ['id', ['id', 'factor_id', 'service_id', 'personnel_id', 'price', 'status']],
    'factor_shift'    => ['id', ['id', 'factor_id', 'personnel_id', 'price', 'date', 'status']],
    'user'            => ['id', ['id', 'phone', 'access', 'active']],
    'company'         => ['id', ['id', 'name']],
    'company_user'    => ['id', ['id', 'company_id', 'user_id']],
    'service'         => ['id', ['id', 'price']],
    'customer_rank'   => ['customer_id', ['company_id', 'customer_id', 'rank']],
    'desk_log'        => ['id', ['id', 'company_id', 'factor_id', 'price', 'action']],
    'sms_send_log'    => ['id', ['id', 'company_id', 'shift_id', 'kind']],
    'mp_fund_current' => ['id', ['id']],
    'change_log'      => ['seq', ['seq', 'table_name', 'pk_val', 'op']],
];

function db_parity_state_path(): string {
    return dh_var('db-parity-state.json');
}

function db_parity_7day_path(): string {
    return dh_var('db-parity-7day.json');
}

/** Web-safe read-only snapshot for door.php action=db_parity_status. */
function db_parity_status_snapshot(): array {
    $st = @json_decode((string) @file_get_contents(db_parity_state_path()), true);
    $d7 = @json_decode((string) @file_get_contents(db_parity_7day_path()), true);
    return [
        'ok'      => true,
        'audit'   => is_array($st) ? $st : ['ready' => false, 'reason' => 'waiting_first_tick'],
        'days_7'  => is_array($d7) ? $d7 : ['consecutive_green_days' => 0, 'days' => []],
    ];
}

/** Build safe read-only SELECT query for COUNT(*), MAX(pk), and order-independent BIT_XOR(CRC32(...)) hash. */
function db_parity_table_hash_sql(string $table, string $pk, array $cols, ?int $maxSeqCap = null): string {
    $exprs = array_map(fn($c) => "COALESCE(`$c`, '')", $cols);
    $concat = "CONCAT_WS('|', " . implode(', ', $exprs) . ")";
    $where = ($table === 'change_log' && $maxSeqCap !== null && $maxSeqCap > 0)
        ? (" WHERE `seq` <= " . (int) $maxSeqCap)
        : '';
    return "SELECT COUNT(*) AS cnt, COALESCE(MAX(`$pk`), 0) AS max_pk, "
         . "COALESCE(BIT_XOR(CRC32($concat)), 0) AS content_hash FROM `$table`$where";
}

/** Pure comparison of full table inventory + deep per-table count & content-hash results (unit-testable). */
function db_parity_compare(array $primaryTables, array $standbyTables, array $primaryHashes, array $standbyHashes): array {
    sort($primaryTables, SORT_STRING);
    sort($standbyTables, SORT_STRING);
    $onlyPrimaryTbl = array_values(array_diff($primaryTables, $standbyTables));
    $onlyStandbyTbl = array_values(array_diff($standbyTables, $primaryTables));

    $details = [];
    $mismatches = [];
    foreach (array_keys(DB_PARITY_HASH_SPECS) as $tbl) {
        if (!isset($primaryHashes[$tbl]) && !isset($standbyHashes[$tbl])) continue;
        $p = $primaryHashes[$tbl] ?? ['cnt' => -1, 'max_pk' => -1, 'content_hash' => ''];
        $s = $standbyHashes[$tbl] ?? ['cnt' => -1, 'max_pk' => -1, 'content_hash' => ''];
        $cntMatch  = ((int) $p['cnt'] === (int) $s['cnt']) && ((int) $p['cnt'] >= 0);
        $pkMatch   = ((int) $p['max_pk'] === (int) $s['max_pk']);
        $hashMatch = ((string) $p['content_hash'] === (string) $s['content_hash']);
        $ok = $cntMatch && $pkMatch && $hashMatch;
        $details[$tbl] = [
            'iran_cnt'    => (int) $p['cnt'],
            'de_cnt'      => (int) $s['cnt'],
            'cnt_diff'    => (int) $p['cnt'] - (int) $s['cnt'],
            'iran_max_pk' => (int) $p['max_pk'],
            'de_max_pk'   => (int) $s['max_pk'],
            'iran_hash'   => (string) $p['content_hash'],
            'de_hash'     => (string) $s['content_hash'],
            'hash_match'  => $hashMatch,
            'match'       => $ok,
        ];
        if (!$ok) {
            $mismatches[] = [
                'table'      => $tbl,
                'cnt_diff'   => (int) $p['cnt'] - (int) $s['cnt'],
                'hash_match' => $hashMatch,
            ];
        }
    }

    $allMatch = !$onlyPrimaryTbl && !$onlyStandbyTbl && !$mismatches && count($details) > 0;
    return [
        'all_match'        => $allMatch,
        'tables_primary'   => count($primaryTables),
        'tables_standby'   => count($standbyTables),
        'only_in_primary'  => $onlyPrimaryTbl,
        'only_in_standby'  => $onlyStandbyTbl,
        'hashed_tables'    => count($details),
        'matched_tables'   => count($details) - count($mismatches),
        'mismatches'       => $mismatches,
        'details'          => $details,
    ];
}

/** Pure Control App + Telegram mismatch alert state machine (unit-testable). */
function db_parity_eval_alert(array $prevAlert, array $cmp, int $nowTs, string $tehranTime): array {
    $consec   = (int) ($prevAlert['consecutive_mismatches'] ?? 0);
    $active   = !empty($prevAlert['alert_active']);
    $lastSent = (int) ($prevAlert['last_alert_ts'] ?? 0);
    $lastRec  = (int) ($prevAlert['last_recovery_ts'] ?? 0);

    $send = null;
    $msg  = '';

    if (empty($cmp['all_match'])) {
        $consec++;
        $badNames = array_map(fn($m) => $m['table'], $cmp['mismatches'] ?? []);
        if ($consec >= DB_PARITY_ALERT_CONSEC && (!$active || ($nowTs - $lastSent) >= DB_PARITY_ALERT_COOLDOWN)) {
            $send     = 'mismatch';
            $active   = true;
            $lastSent = $nowTs;
            $msg      = "⚠️ هشدارِ ناهمخوانیِ دو دیتابیس (استایلیست · STY-03)\n"
                      . "• زمان: {$tehranTime}\n"
                      . "• جدول‌های دارای اختلاف: " . (implode('، ', $badNames) ?: 'تفاوتِ فهرستِ جدول‌ها') . "\n"
                      . "• هم‌خوان: {$cmp['matched_tables']} از {$cmp['hashed_tables']} جدولِ اصلی";
        }
    } else {
        $consec = 0;
        if ($active) {
            $send    = 'recovered';
            $active  = false;
            $lastRec = $nowTs;
            $msg     = "✅ بازیابیِ هم‌خوانیِ دو دیتابیس (استایلیست · STY-03)\n"
                     . "• زمان: {$tehranTime}\n"
                     . "• همهٔ {$cmp['hashed_tables']} جدولِ اصلی با شمار و هشِ محتوا ۱۰۰٪ برابر شدند.";
        }
    }

    return [
        'send'    => $send,
        'message' => $msg,
        'state'   => [
            'alert_active'           => $active,
            'consecutive_mismatches' => $consec,
            'last_alert_ts'          => $lastSent,
            'last_recovery_ts'       => $lastRec,
        ],
    ];
}

/** Update automated 7-day rolling daily parity ledger (1 row per Jalali day, unit-testable). */
function db_parity_update_7day(array $prevLedger, string $jalaliDay, array $cmp, string $utcNow): array {
    $days = is_array($prevLedger['days'] ?? null) ? $prevLedger['days'] : [];
    $byDay = [];
    foreach ($days as $d) {
        if (is_array($d) && !empty($d['day'])) {
            $byDay[(string) $d['day']] = $d;
        }
    }
    $prevToday = $byDay[$jalaliDay] ?? ['checks' => 0, 'mismatches' => 0];
    $byDay[$jalaliDay] = [
        'day'            => $jalaliDay,
        'ok'             => !empty($cmp['all_match']),
        'checks'         => (int) ($prevToday['checks'] ?? 0) + 1,
        'mismatches'     => (int) ($prevToday['mismatches'] ?? 0) + (empty($cmp['all_match']) ? 1 : 0),
        'tables_total'   => (int) ($cmp['tables_primary'] ?? 0),
        'hashed_matched' => (int) ($cmp['matched_tables'] ?? 0) . '/' . (int) ($cmp['hashed_tables'] ?? 0),
        'last_at_utc'    => $utcNow,
    ];
    ksort($byDay, SORT_STRING);
    if (count($byDay) > 14) {
        $byDay = array_slice($byDay, -14, null, true);
    }
    $list = array_values($byDay);
    $streak = 0;
    for ($i = count($list) - 1; $i >= 0; $i--) {
        if (!empty($list[$i]['ok'])) $streak++;
        else break;
    }
    return [
        'target_days'            => 7,
        'consecutive_green_days' => $streak,
        'ready_7day_gate'        => ($streak >= 7),
        'updated_at_utc'         => $utcNow,
        'days'                   => $list,
    ];
}

/** Write Control App readable status & 7-day ledger into control.hohedoc.ir/var/ (same bridge as phone-backup.php). */
function db_parity_publish_to_control(array $controlPayload, array $ledger7): bool {
    if (!function_exists('pb_control_var')) return false;
    $dir = pb_control_var();
    if (!is_dir($dir)) return false;
    $ok1 = false;
    $f1 = $dir . '/db-parity-state.json';
    if (@file_put_contents($f1 . '.tmp', json_encode($controlPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        @chmod($f1 . '.tmp', 0644);
        $ok1 = @rename($f1 . '.tmp', $f1);
    }
    $f2 = $dir . '/db-parity-7day.json';
    if (@file_put_contents($f2 . '.tmp', json_encode($ledger7, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        @chmod($f2 . '.tmp', 0644);
        @rename($f2 . '.tmp', $f2);
    }
    return $ok1;
}

/** CLI-only: called from tick.php. Runs every 30 minutes (and immediately on first run). */
function db_parity_audit_tick(): void {
    if (PHP_SAPI !== 'cli') return;
    $nowTs = time();
    $prev = @json_decode((string) @file_get_contents(db_parity_state_path()), true);
    $lastTs = is_array($prev) ? (strtotime((string) ($prev['at'] ?? '')) ?: 0) : 0;
    if ($lastTs > 0 && ($nowTs - $lastTs) < DB_PARITY_INTERVAL_SEC) {
        return;
    }

    $pdo = de_mirror_open_pdo();
    if ($pdo === null) return;
    $site = dh_sites()['stylistcrm'] ?? null;
    if (!$site) return;

    $t0 = microtime(true);

    // 1. Full table inventory on both DBs (read-only)
    $pTblRows = de_mirror_iran_query($site, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME");
    $sTblRows = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
    $primaryTables = array_map(fn($r) => (string) $r['TABLE_NAME'], $pTblRows);
    $standbyTables = array_map(fn($r) => (string) $r['TABLE_NAME'], $sTblRows);

    // Determine common change_log seq cap so change_log hash comparison is deterministic
    $pClMax = (int) (de_mirror_iran_query($site, "SELECT COALESCE(MAX(seq), 0) AS m FROM change_log")[0]['m'] ?? 0);
    $sClMax = (int) $pdo->query("SELECT COALESCE(MAX(seq), 0) FROM `change_log`")->fetchColumn();
    $commonSeq = min($pClMax, $sClMax);

    // Filter specs to columns actually present on local DB
    [$localCols] = de_mirror_introspect_schema($pdo);
    $primaryHashes = [];
    $standbyHashes = [];
    foreach (DB_PARITY_HASH_SPECS as $tbl => [$pk, $cols]) {
        if (!isset($localCols[$tbl])) continue;
        $validCols = array_values(array_filter($cols, fn($c) => isset($localCols[$tbl][$c])));
        if (!$validCols || !isset($localCols[$tbl][$pk])) continue;
        $sql = db_parity_table_hash_sql($tbl, $pk, $validCols, $tbl === 'change_log' ? $commonSeq : null);
        $pr = de_mirror_iran_query($site, $sql)[0] ?? null;
        $sr = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: null;
        if (is_array($pr)) $primaryHashes[$tbl] = $pr;
        if (is_array($sr)) $standbyHashes[$tbl] = $sr;
    }

    $cmp = db_parity_compare($primaryTables, $standbyTables, $primaryHashes, $standbyHashes);
    $utcNow = gmdate('c', $nowTs);
    $tehStr = function_exists('pb_tehran') ? pb_tehran($nowTs) : gmdate('Y-m-d H:i', $nowTs);
    $jalaliDay = explode(' ', $tehStr)[0];

    $alertEval = db_parity_eval_alert(is_array($prev['alert'] ?? null) ? $prev['alert'] : [], $cmp, $nowTs, $tehStr);
    if ($alertEval['send'] !== null && function_exists('de_mirror_send_tg_alert')) {
        $tgRes = de_mirror_send_tg_alert($alertEval['message']);
        $alertEval['state']['last_tg_ok'] = $tgRes[0];
        dh_log("db_parity alert={$alertEval['send']} tg_ok=" . ($tgRes[0] ? '1' : '0'));
    }

    $prev7 = @json_decode((string) @file_get_contents(db_parity_7day_path()), true);
    $ledger7 = db_parity_update_7day(is_array($prev7) ? $prev7 : [], $jalaliDay, $cmp, $utcNow);
    @file_put_contents(db_parity_7day_path(), json_encode($ledger7, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod(db_parity_7day_path(), 0600);

    $state = [
        'ready'           => true,
        'ok'              => true,
        'at'              => $utcNow,
        'at_tehran'       => $tehStr,
        'duration_ms'     => (int) round((microtime(true) - $t0) * 1000),
        'common_seq_cap'  => $commonSeq,
        'all_match'       => $cmp['all_match'],
        'tables_primary'  => $cmp['tables_primary'],
        'tables_standby'  => $cmp['tables_standby'],
        'hashed_tables'   => $cmp['hashed_tables'],
        'matched_tables'  => $cmp['matched_tables'],
        'mismatches'      => $cmp['mismatches'],
        'details'         => $cmp['details'],
        'alert'           => $alertEval['state'],
        'days_7_streak'   => $ledger7['consecutive_green_days'],
    ];

    $controlPayload = [
        'status'                 => $cmp['all_match'] ? 'green' : 'mismatch',
        'all_match'              => $cmp['all_match'],
        'checked_at_tehran'      => $tehStr,
        'checked_at_utc'         => $utcNow,
        'tables_total'           => $cmp['tables_primary'],
        'hashed_matched'         => $cmp['matched_tables'] . '/' . $cmp['hashed_tables'],
        'mismatches'             => $cmp['mismatches'],
        'alert_active'           => $alertEval['state']['alert_active'],
        'consecutive_green_days' => $ledger7['consecutive_green_days'],
        'message_fa'             => $cmp['all_match']
            ? "هم‌خوانیِ دو دیتابیس کامل است ({$cmp['matched_tables']}/{$cmp['hashed_tables']} جدول با هشِ محتوا برابر)."
            : "هشدار: اختلاف در هم‌خوانیِ دو دیتابیس شناسایی شد.",
    ];
    $state['control_app_published'] = db_parity_publish_to_control($controlPayload, $ledger7);

    file_put_contents(db_parity_state_path(), json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod(db_parity_state_path(), 0600);

    dh_state_set('db_parity', [
        'ok'                     => $cmp['all_match'],
        'at'                     => $utcNow,
        'tables'                 => $cmp['tables_primary'] . '/' . $cmp['tables_standby'],
        'hashed_matched'         => $cmp['matched_tables'] . '/' . $cmp['hashed_tables'],
        'control_app_published'  => $state['control_app_published'],
        'consecutive_green_days' => $ledger7['consecutive_green_days'],
    ]);
    dh_log("db_parity all_match=" . ($cmp['all_match'] ? '1' : '0') . " matched={$cmp['matched_tables']}/{$cmp['hashed_tables']} streak={$ledger7['consecutive_green_days']}d");
}
