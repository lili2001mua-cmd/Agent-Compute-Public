<?php
// Offline selftest for STY-03 (Steps 111, 112, 113): db_parity_audit.php
require_once __DIR__ . '/../files/app/lib.php';
require_once __DIR__ . '/../files/app/db_parity_audit.php';

$pass = 0; $fail = 0;
function ok(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: $label\n"; }
    else { $fail++; echo "FAIL: $label\n"; }
}

// 1. SQL builder uses order-independent BIT_XOR(CRC32(...)) and caps change_log at commonSeq
$sqlF = db_parity_table_hash_sql('factor', 'id', ['id', 'company_id', 'status']);
ok(str_contains($sqlF, 'BIT_XOR(CRC32(CONCAT_WS') && !str_contains($sqlF, 'WHERE'), 'factor hash SQL built cleanly');
$sqlCl = db_parity_table_hash_sql('change_log', 'seq', ['seq', 'op'], 34500);
ok(str_contains($sqlCl, 'WHERE `seq` <= 34500'), 'change_log hash SQL caps at commonSeq');

// 2. Compare: exact count + content hash match across all tables
$tbls = ['factor', 'user', 'change_log'];
$pH = [
    'factor'     => ['cnt' => 208499, 'max_pk' => 217830, 'content_hash' => '987654321'],
    'user'       => ['cnt' => 16981,  'max_pk' => 17424,  'content_hash' => '123456789'],
    'change_log' => ['cnt' => 34495,  'max_pk' => 34495,  'content_hash' => '555555555'],
];
$c1 = db_parity_compare($tbls, $tbls, $pH, $pH);
ok($c1['all_match'] === true && $c1['matched_tables'] === 3 && count($c1['mismatches']) === 0, 'exact count + content hash matches');

// 3. Detects 1-byte content hash drift even when row count and max_pk are identical!
$sHDrift = $pH;
$sHDrift['factor']['content_hash'] = '987654320';
$c2 = db_parity_compare($tbls, $tbls, $pH, $sHDrift);
ok($c2['all_match'] === false && count($c2['mismatches']) === 1 && $c2['mismatches'][0]['table'] === 'factor' && $c2['mismatches'][0]['hash_match'] === false,
    'detects 1-byte content hash drift with identical row count');

// 4. Detects missing table in standby inventory
$c3 = db_parity_compare(['factor', 'user', 'extra_tbl'], ['factor', 'user'], $pH, $pH);
ok($c3['all_match'] === false && $c3['only_in_primary'] === ['extra_tbl'], 'detects missing table in standby');

// 5. Control App + Telegram alert state machine (debounce, fire, cooldown, recovery)
$t0 = 1795670000;
$a1 = db_parity_eval_alert([], $c2, $t0, '1405-09-04 20:00');
ok($a1['send'] === null && $a1['state']['consecutive_mismatches'] === 1, 'single mismatch debounced');
$a2 = db_parity_eval_alert($a1['state'], $c2, $t0 + 1800, '1405-09-04 20:30');
ok($a2['send'] === 'mismatch' && $a2['state']['alert_active'] === true && str_contains($a2['message'], 'factor'), '2nd mismatch fires alert');
$a3 = db_parity_eval_alert($a2['state'], $c2, $t0 + 2400, '1405-09-04 20:40');
ok($a3['send'] === null && $a3['state']['alert_active'] === true, '60m cooldown suppresses duplicate');
$a4 = db_parity_eval_alert($a3['state'], $c1, $t0 + 3600, '1405-09-04 21:00');
ok($a4['send'] === 'recovered' && $a4['state']['alert_active'] === false, 'recovery clears alert_active');

// 6. 7-day rolling daily ledger (dedup same day, streak counts up to 7, resets on red day)
$l = [];
for ($d = 1; $d <= 7; $d++) {
    $l = db_parity_update_7day($l, sprintf('1405-09-%02d', $d), $c1, '2026-11-25T17:00:00Z');
}
ok($l['consecutive_green_days'] === 7 && $l['ready_7day_gate'] === true, '7 consecutive green days passes 7-day gate');
$lSame = db_parity_update_7day($l, '1405-09-07', $c1, '2026-11-25T17:30:00Z');
ok(count($lSame['days']) === 7 && $lSame['days'][6]['checks'] === 2, 'same Jalali day deduplicates and increments checks');
$lRed = db_parity_update_7day($lSame, '1405-09-08', $c2, '2026-11-26T17:00:00Z');
ok($lRed['consecutive_green_days'] === 0 && $lRed['ready_7day_gate'] === false, 'mismatch day resets consecutive green streak to 0');

// 7. 5-specialist policy: host-local ops tables ignored & reported transparently without false mismatch
$cHostLocal = db_parity_compare(
    ['factor', 'user'],
    ['factor', 'user', 'schema_migration_log', 'stylist_write_lease', '_sty_probe_test'],
    $pH,
    $pH
);
ok(
    $cHostLocal['all_match'] === true
        && $cHostLocal['tables_primary'] === 2
        && $cHostLocal['tables_standby'] === 2
        && $cHostLocal['ignored_host_local'] === ['_sty_probe_test', 'schema_migration_log', 'stylist_write_lease'],
    'host-local ops tables ignored cleanly and reported in ignored_host_local'
);

// 8. Security guard: core business table can NEVER be treated as host-local
ok(db_parity_is_host_local_table('factor') === false && db_parity_is_host_local_table('user') === false,
    'security guard forbids excluding core business tables');

// 9. Real business-table schema drift includes exact table name & host side in Telegram alert
$aDrift1 = db_parity_eval_alert([], $c3, $t0, '1405-09-05 07:00');
$aDrift2 = db_parity_eval_alert($aDrift1['state'], $c3, $t0 + 1800, '1405-09-05 07:30');
ok(
    $aDrift2['send'] === 'mismatch' && str_contains($aDrift2['message'], 'extra_tbl') && str_contains($aDrift2['message'], 'فقط در اصلی'),
    'business-table schema drift names exact table & host side in Telegram alert'
);

// 10. Transient 1-tick in-flight diff does NOT break 7-day green streak, whereas 2nd consecutive or schema drift DOES
$lTrans = db_parity_update_7day($lSame, '1405-09-08', $c2, '2026-11-26T17:00:00Z', 1);
ok($lTrans['consecutive_green_days'] === 8, 'single transient 1-tick in-flight diff preserves green streak');
$lPersist = db_parity_update_7day($lSame, '1405-09-08', $c2, '2026-11-26T17:30:00Z', 2);
ok($lPersist['consecutive_green_days'] === 0, '2nd consecutive mismatch breaks green streak');
$lSchemaRed = db_parity_update_7day($lSame, '1405-09-08', $c3, '2026-11-26T17:00:00Z', 1);
ok($lSchemaRed['consecutive_green_days'] === 0, 'real business-table schema drift breaks green streak immediately');

echo "\nTOTAL: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
