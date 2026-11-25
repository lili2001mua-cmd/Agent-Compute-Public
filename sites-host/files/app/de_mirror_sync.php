<?php
// Live 1-minute change_log replicator & parity auditor: Iran Host 1 (redliliu_main) -> Germany Host 2 (hohedoc1_stylist_de).
// Part of STY-20 (Step 200 / STY-20-03).
// - Reads Iran Host 1 STRICTLY read-only via pwa.redlilium.ir/deploy/sync.php (action=query). Zero writes on Iran Host 1.
// - Writes ONLY to local Germany mirror DB (hohedoc1_stylist_de) using credentials from <home>/holding-vault/db-stylist_de.php (0600).
// - Suppresses local triggers via SET @change_log_suppress = 1 and records every applied change_log row locally so MAX(seq) is durable.
// - Never exposes secrets in state files, logs, or door.php responses.

const DE_MIRROR_DB_SUFFIX     = 'stylist_de';
const DE_MIRROR_BATCH_SIZE    = 200;
const DE_MIRROR_MAX_PAGES     = 5;       // up to 1,000 rows per 1-minute tick
const DE_MIRROR_SLA_LAG_SEC   = 120;     // 2-minute RPO SLA target
const DE_MIRROR_PARITY_EVERY  = 300;     // run core-table parity audit every 5 minutes (and on first run)

const DE_MIRROR_PARITY_TABLES = [
    'factor'       => 'id',
    'factor_item'  => 'id',
    'factor_shift' => 'id',
    'user'         => 'id',
    'company'      => 'id',
    'company_user' => 'id',
    'service'      => 'id',
    'change_log'   => 'seq',
];

function de_mirror_state_path(): string {
    return dh_var('de-mirror-state.json');
}

function de_mirror_parity_path(): string {
    return dh_var('de-mirror-parity.json');
}

/** Web-safe read-only status snapshot (called by sealed door.php action=de_sync_status). */
function de_mirror_status_snapshot(): array {
    $st = @json_decode((string) @file_get_contents(de_mirror_state_path()), true);
    $pa = @json_decode((string) @file_get_contents(de_mirror_parity_path()), true);
    return [
        'ok'     => true,
        'sync'   => is_array($st) ? $st : ['ready' => false, 'reason' => 'waiting_first_tick'],
        'parity' => is_array($pa) ? $pa : null,
    ];
}

/** Normalize a JSON-decoded value for PDO bound parameter execution. */
function de_mirror_bind_val($v) {
    if ($v === null) return null;
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $v;
}

/** Validate MySQL identifier (table or column name) before quoting with backticks. */
function de_mirror_ident_ok(string $name): bool {
    return (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/', $name);
}

/** Load local table columns and primary key columns from information_schema (cached once per tick). */
function de_mirror_introspect_schema(PDO $pdo): array {
    $cols = [];
    $q1 = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()");
    foreach ($q1->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cols[(string) $r['TABLE_NAME']][(string) $r['COLUMN_NAME']] = true;
    }
    $pks = [];
    $q2 = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE "
        . "WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY' "
        . "ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    foreach ($q2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pks[(string) $r['TABLE_NAME']][] = (string) $r['COLUMN_NAME'];
    }
    return [$cols, $pks];
}

/**
 * Build SQL + bound parameters to apply one change_log row to the target table (pure helper, unit-testable).
 * Returns ['kind' => 'upsert'|'delete'|'ddl'|'skip', 'sql' => ?string, 'params' => array, 'reason' => ?string].
 */
function de_mirror_plan_row(array $row, array $tableCols, array $tablePks): array {
    $table = (string) ($row['table_name'] ?? '');
    $op = strtoupper(trim((string) ($row['op'] ?? '')));
    if (!de_mirror_ident_ok($table)) {
        return ['kind' => 'skip', 'sql' => null, 'params' => [], 'reason' => 'invalid_table_name'];
    }
    if ($op === 'DDL') {
        return ['kind' => 'ddl', 'sql' => null, 'params' => [], 'reason' => 'ddl_logged_only'];
    }
    if ($table === 'change_log') {
        return ['kind' => 'skip', 'sql' => null, 'params' => [], 'reason' => 'self_change_log'];
    }
    if (!isset($tableCols[$table])) {
        return ['kind' => 'skip', 'sql' => null, 'params' => [], 'reason' => 'table_not_in_replica'];
    }

    if ($op === 'I' || $op === 'U') {
        $afterRaw = $row['after_json'] ?? null;
        $data = is_string($afterRaw) ? json_decode($afterRaw, true) : (is_array($afterRaw) ? $afterRaw : null);
        if (!is_array($data) || !$data) {
            return ['kind' => 'skip', 'sql' => null, 'params' => [], 'reason' => 'empty_after_json'];
        }
        $allowed = $tableCols[$table];
        $cols = [];
        $placeholders = [];
        $updates = [];
        $params = [];
        foreach ($data as $k => $v) {
            $col = (string) $k;
            if (!isset($allowed[$col]) || !de_mirror_ident_ok($col)) continue;
            $q = "`$col`";
            $cols[] = $q;
            $placeholders[] = '?';
            $updates[] = "$q = VALUES($q)";
            $params[] = de_mirror_bind_val($v);
        }
        if (!$cols) {
            return ['kind' => 'skip', 'sql' => null, 'params' => [], 'reason' => 'no_matching_columns'];
        }
        $sql = "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders)
             . ") ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
        return ['kind' => 'upsert', 'sql' => $sql, 'params' => $params, 'reason' => null];
    }

    if ($op === 'D') {
        $pkCols = $tablePks[$table] ?? ['id'];
        $beforeRaw = $row['before_json'] ?? null;
        $before = is_string($beforeRaw) ? json_decode($beforeRaw, true) : (is_array($beforeRaw) ? $beforeRaw : null);
        $pkVals = [];
        $allFromBefore = is_array($before);
        if ($allFromBefore) {
            foreach ($pkCols as $pc) {
                if (!array_key_exists($pc, $before)) {
                    $allFromBefore = false;
                    break;
                }
                $pkVals[] = de_mirror_bind_val($before[$pc]);
            }
        }
        if (!$allFromBefore) {
            $parts = explode(':', (string) ($row['pk_val'] ?? ''));
            if (count($parts) !== count($pkCols)) {
                return ['kind' => 'skip', 'sql' => null, 'params' => [], 'reason' => 'delete_pk_mismatch'];
            }
            $pkVals = $parts;
        }
        $conds = [];
        foreach ($pkCols as $pc) {
            if (!de_mirror_ident_ok($pc)) {
                return ['kind' => 'skip', 'sql' => null, 'params' => [], 'reason' => 'invalid_pk_col'];
            }
            $conds[] = "`$pc` = ?";
        }
        $sql = "DELETE FROM `$table` WHERE " . implode(' AND ', $conds);
        return ['kind' => 'delete', 'sql' => $sql, 'params' => $pkVals, 'reason' => null];
    }

    return ['kind' => 'skip', 'sql' => null, 'params' => [], 'reason' => 'unknown_op'];
}

/** Apply one change_log row to the replica PDO + mirror the change_log entry itself. */
function de_mirror_apply_row(PDO $pdo, array $row, array $tableCols, array $tablePks): array {
    $plan = de_mirror_plan_row($row, $tableCols, $tablePks);
    if ($plan['sql'] !== null) {
        $st = $pdo->prepare($plan['sql']);
        $st->execute($plan['params']);
    }
    $clSql = "INSERT IGNORE INTO `change_log` "
           . "(`seq`, `tx_id`, `table_name`, `pk_val`, `op`, `company_id`, `before_json`, `after_json`, `created_at`) "
           . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $cl = $pdo->prepare($clSql);
    $cl->execute([
        (int) ($row['seq'] ?? 0),
        $row['tx_id'] ?? null,
        (string) ($row['table_name'] ?? ''),
        $row['pk_val'] ?? null,
        (string) ($row['op'] ?? ''),
        isset($row['company_id']) && $row['company_id'] !== '' ? (int) $row['company_id'] : null,
        $row['before_json'] ?? null,
        $row['after_json'] ?? null,
        $row['created_at'] ?? null,
    ]);
    return $plan;
}

/** Compute replication lag in seconds given Iran & Germany head metadata (pure helper, unit-testable). */
function de_mirror_compute_lag(int $iranMaxSeq, int $deMaxSeq, ?string $iranDbNow, ?string $deMaxAt): array {
    $gap = max(0, $iranMaxSeq - $deMaxSeq);
    if ($gap === 0) {
        return ['seq_gap' => 0, 'lag_seconds' => 0, 'sla_ok' => true];
    }
    $nowTs = $iranDbNow ? strtotime($iranDbNow) : false;
    $deTs  = $deMaxAt ? strtotime($deMaxAt) : false;
    $lag = ($nowTs !== false && $deTs !== false) ? max(0, $nowTs - $deTs) : null;
    $slaOk = ($lag !== null && $lag <= DE_MIRROR_SLA_LAG_SEC);
    return ['seq_gap' => $gap, 'lag_seconds' => $lag, 'sla_ok' => $slaOk];
}

/** Build UNION ALL SQL for core-table parity audit (COUNT(*) and MAX(pk)). */
function de_mirror_parity_sql(): string {
    $parts = [];
    foreach (DE_MIRROR_PARITY_TABLES as $tbl => $pk) {
        $parts[] = "SELECT '$tbl' AS tbl, COUNT(*) AS cnt, COALESCE(MAX(`$pk`), 0) AS max_pk FROM `$tbl`";
    }
    return implode(' UNION ALL ', $parts);
}

/** Compare Iran vs Germany parity rows (pure helper, unit-testable). */
function de_mirror_compare_parity(array $iranRows, array $deRows): array {
    $irMap = [];
    foreach ($iranRows as $r) {
        $irMap[(string) $r['tbl']] = ['cnt' => (int) $r['cnt'], 'max_pk' => (int) $r['max_pk']];
    }
    $deMap = [];
    foreach ($deRows as $r) {
        $deMap[(string) $r['tbl']] = ['cnt' => (int) $r['cnt'], 'max_pk' => (int) $r['max_pk']];
    }
    $tables = [];
    $allMatch = true;
    foreach (DE_MIRROR_PARITY_TABLES as $tbl => $_pk) {
        $ir = $irMap[$tbl] ?? ['cnt' => -1, 'max_pk' => -1];
        $de = $deMap[$tbl] ?? ['cnt' => -1, 'max_pk' => -1];
        $cntDiff = $ir['cnt'] - $de['cnt'];
        $pkDiff  = $ir['max_pk'] - $de['max_pk'];
        $match   = ($cntDiff === 0 && $pkDiff === 0 && $ir['cnt'] >= 0);
        if (!$match) $allMatch = false;
        $tables[$tbl] = [
            'iran_cnt'    => $ir['cnt'],
            'de_cnt'      => $de['cnt'],
            'cnt_diff'    => $cntDiff,
            'iran_max_pk' => $ir['max_pk'],
            'de_max_pk'   => $de['max_pk'],
            'pk_diff'     => $pkDiff,
            'match'       => $match,
        ];
    }
    return ['all_match' => $allMatch, 'tables' => $tables];
}

/** Open PDO connection to local Germany mirror DB using vault file (CLI-only). */
function de_mirror_open_pdo(): ?PDO {
    if (PHP_SAPI !== 'cli') return null;
    $vd = da_vault_dir();
    if ($vd === null) return null;
    $f = $vd . '/db-' . DE_MIRROR_DB_SUFFIX . '.php';
    if (!is_file($f)) return null;
    $cfg = @include $f;
    if (!is_array($cfg) || empty($cfg['database']) || empty($cfg['username']) || empty($cfg['password'])) {
        return null;
    }
    $pdo = new PDO(
        'mysql:host=' . ($cfg['host'] ?? 'localhost') . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        (string) $cfg['username'],
        (string) $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0; SET @change_log_suppress = 1; SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");
    return $pdo;
}

/** Run one read-only SQL query against Iran Host 1 (pwa.redlilium.ir/deploy/sync.php). */
function de_mirror_iran_query(array $site, string $sql): array {
    [$code, $j, $err] = dh_post($site, 'pwa', ['action' => 'query', 'ref' => 'main', 'sql' => $sql, 'files' => []]);
    if ($code !== 200 || empty($j['ok']) || !is_array($j['rows'] ?? null)) {
        throw new RuntimeException('iran_query_failed http=' . $code . ' err=' . ($err ?: (string) ($j['error'] ?? 'unknown')));
    }
    return $j['rows'];
}

/** CLI-only: called every minute from tick.php. Replicates new change_log rows Iran -> Germany and audits parity. */
function de_mirror_sync_tick(): void {
    if (PHP_SAPI !== 'cli') return;
    $t0 = microtime(true);
    $prev = @json_decode((string) @file_get_contents(de_mirror_state_path()), true);
    if (!is_array($prev)) $prev = [];

    $pdo = de_mirror_open_pdo();
    if ($pdo === null) {
        $st = ['ready' => false, 'reason' => 'vault_db_not_configured', 'at' => gmdate('c')];
        file_put_contents(de_mirror_state_path(), json_encode($st, JSON_UNESCAPED_SLASHES), LOCK_EX);
        return;
    }

    $sites = dh_sites();
    $site = $sites['stylistcrm'] ?? null;
    if (!$site) {
        throw new RuntimeException('stylistcrm_site_missing');
    }

    [$tableCols, $tablePks] = de_mirror_introspect_schema($pdo);
    $cursor = (int) $pdo->query("SELECT COALESCE(MAX(seq), 0) FROM `change_log`")->fetchColumn();

    // STY-05 گامِ ۱۲۰: قاعدهٔ خواندنِ امن (حاشیهٔ ۵ ثانیه‌ایِ پیوسته تا هیچ تراکنشِ هم‌زمانی جا نیفتد)
    $wmRows = de_mirror_iran_query(
        $site,
        "SELECT MIN(seq) AS first_young, COALESCE(MAX(seq), 0) AS max_seq FROM change_log "
        . "WHERE seq > " . (int) $cursor . " AND created_at > (NOW(6) - INTERVAL 5 SECOND)"
    );
    $firstYoung = $wmRows[0]['first_young'] ?? null;
    $safeUpperClause = ($firstYoung !== null && (int) $firstYoung > 0)
        ? (" AND seq <= " . max($cursor, (int) $firstYoung - 1))
        : "";

    $applied = 0;
    $upserts = 0;
    $deletes = 0;
    $skipped = 0;
    $ddlNotices = is_array($prev['ddl_notices'] ?? null) ? array_slice($prev['ddl_notices'], -20) : [];

    for ($page = 0; $page < DE_MIRROR_MAX_PAGES; $page++) {
        $sql = "SELECT seq, tx_id, table_name, pk_val, op, company_id, before_json, after_json, created_at "
             . "FROM change_log WHERE seq > " . (int) $cursor . $safeUpperClause
             . " ORDER BY seq ASC LIMIT " . DE_MIRROR_BATCH_SIZE;
        $rows = de_mirror_iran_query($site, $sql);
        if (!$rows) break;

        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $plan = de_mirror_apply_row($pdo, $row, $tableCols, $tablePks);
                $seq = (int) ($row['seq'] ?? 0);
                if ($seq > $cursor) $cursor = $seq;
                $applied++;
                if ($plan['kind'] === 'upsert') $upserts++;
                elseif ($plan['kind'] === 'delete') $deletes++;
                elseif ($plan['kind'] === 'ddl') {
                    $ddlNotices[] = [
                        'seq'   => $seq,
                        'table' => (string) ($row['table_name'] ?? ''),
                        'at'    => (string) ($row['created_at'] ?? gmdate('c')),
                    ];
                } else {
                    $skipped++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if (count($rows) < DE_MIRROR_BATCH_SIZE) break;
    }

    // Read head metadata from both Iran and Germany to compute exact seq_gap & lag_seconds
    $iranHeadRows = de_mirror_iran_query(
        $site,
        "SELECT COALESCE(MAX(seq), 0) AS max_seq, MAX(created_at) AS max_at, NOW() AS db_now FROM change_log"
    );
    $iranHead = $iranHeadRows[0] ?? ['max_seq' => 0, 'max_at' => null, 'db_now' => null];
    $deHead = $pdo->query("SELECT COALESCE(MAX(seq), 0) AS max_seq, MAX(created_at) AS max_at FROM `change_log`")->fetch(PDO::FETCH_ASSOC);

    $iranMaxSeq = (int) ($iranHead['max_seq'] ?? 0);
    $deMaxSeq   = (int) ($deHead['max_seq'] ?? 0);
    $lagInfo    = de_mirror_compute_lag($iranMaxSeq, $deMaxSeq, $iranHead['db_now'] ?? null, $deHead['max_at'] ?? null);

    // Periodic core-table parity audit (every 5 minutes or first run)
    $prevParity = @json_decode((string) @file_get_contents(de_mirror_parity_path()), true);
    $lastParityTs = is_array($prevParity) ? (strtotime((string) ($prevParity['at'] ?? '')) ?: 0) : 0;
    $parityDue = ($lastParityTs === 0) || ((time() - $lastParityTs) >= DE_MIRROR_PARITY_EVERY);
    $paritySummary = is_array($prevParity) ? ['at' => $prevParity['at'] ?? null, 'all_match' => (bool) ($prevParity['all_match'] ?? false)] : null;

    if ($parityDue) {
        $pSql = de_mirror_parity_sql();
        $iranParityRows = de_mirror_iran_query($site, $pSql);
        $deParityRows   = $pdo->query($pSql)->fetchAll(PDO::FETCH_ASSOC);
        $cmp = de_mirror_compare_parity($iranParityRows, $deParityRows);
        $parityDoc = [
            'at'        => gmdate('c'),
            'all_match' => $cmp['all_match'],
            'tables'    => $cmp['tables'],
        ];
        file_put_contents(de_mirror_parity_path(), json_encode($parityDoc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod(de_mirror_parity_path(), 0600);
        $paritySummary = ['at' => $parityDoc['at'], 'all_match' => $parityDoc['all_match']];
    }

    $totalApplied = (int) ($prev['total_applied'] ?? 0) + $applied;
    $state = [
        'ready'           => true,
        'ok'              => true,
        'at'              => gmdate('c'),
        'duration_ms'     => (int) round((microtime(true) - $t0) * 1000),
        'iran_max_seq'    => $iranMaxSeq,
        'de_max_seq'      => $deMaxSeq,
        'seq_gap'         => $lagInfo['seq_gap'],
        'lag_seconds'     => $lagInfo['lag_seconds'],
        'sla_target_sec'  => DE_MIRROR_SLA_LAG_SEC,
        'sla_ok'          => $lagInfo['sla_ok'],
        'last_batch'      => ['applied' => $applied, 'upserts' => $upserts, 'deletes' => $deletes, 'skipped' => $skipped],
        'total_applied'   => $totalApplied,
        'ddl_notices'     => array_slice($ddlNotices, -20),
        'parity_summary'  => $paritySummary,
    ];
    file_put_contents(de_mirror_state_path(), json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod(de_mirror_state_path(), 0600);

    dh_state_set('de_mirror', [
        'ok'           => true,
        'at'           => $state['at'],
        'iran_max_seq' => $iranMaxSeq,
        'de_max_seq'   => $deMaxSeq,
        'seq_gap'      => $lagInfo['seq_gap'],
        'lag_seconds'  => $lagInfo['lag_seconds'],
        'sla_ok'       => $lagInfo['sla_ok'],
        'total_applied'=> $totalApplied,
        'parity_match' => $paritySummary['all_match'] ?? null,
    ]);
    if ($applied > 0 || !$lagInfo['sla_ok']) {
        dh_log("de_mirror applied=$applied de_seq=$deMaxSeq iran_seq=$iranMaxSeq gap={$lagInfo['seq_gap']} lag={$lagInfo['lag_seconds']}s");
    }
}
