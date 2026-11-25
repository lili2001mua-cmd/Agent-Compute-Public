<?php
// Offline selftest for sites-host/files/app/de_mirror_sync.php (STY-20 Step 200).
require_once __DIR__ . '/../files/app/lib.php';
require_once __DIR__ . '/../files/app/da_provision.php';
require_once __DIR__ . '/../files/app/de_mirror_sync.php';

$pass = 0;
$fail = 0;
function ok(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: $label\n"; }
    else { $fail++; echo "FAIL: $label\n"; }
}

$tableCols = [
    'factor' => ['id' => true, 'company_id' => true, 'total' => true, 'note' => true, 'is_paid' => true],
    'customer_rank' => ['company_id' => true, 'customer_id' => true, 'rank' => true],
    'schedule_log' => ['id' => true, 'job_key' => true, 'status' => true],
];
$tablePks = [
    'factor' => ['id'],
    'customer_rank' => ['company_id', 'customer_id'],
    'schedule_log' => ['id'],
];

// 1. Insert (I) -> UPSERT with unknown column filtered out and bool normalized
$p1 = de_mirror_plan_row([
    'seq' => 32254, 'table_name' => 'factor', 'pk_val' => '101', 'op' => 'I',
    'after_json' => json_encode(['id' => 101, 'company_id' => 5, 'total' => 250000, 'is_paid' => true, 'unknown_col' => 'dropme']),
], $tableCols, $tablePks);
ok($p1['kind'] === 'upsert', 'insert produces upsert');
ok(str_contains($p1['sql'], 'INSERT INTO `factor` (`id`, `company_id`, `total`, `is_paid`)'), 'insert filters unknown columns');
ok(str_contains($p1['sql'], 'ON DUPLICATE KEY UPDATE'), 'insert is idempotent upsert');
ok($p1['params'] === [101, 5, 250000, 1], 'insert normalizes bool to int');

// 2. Update (U) -> idempotent UPSERT
$p2 = de_mirror_plan_row([
    'seq' => 32255, 'table_name' => 'schedule_log', 'pk_val' => '466765', 'op' => 'U',
    'after_json' => json_encode(['id' => 466765, 'job_key' => 'system.heartbeat', 'status' => 'done']),
], $tableCols, $tablePks);
ok($p2['kind'] === 'upsert' && $p2['params'] === [466765, 'system.heartbeat', 'done'], 'update produces upsert with exact params');

// 3. Delete (D) with single PK from before_json
$p3 = de_mirror_plan_row([
    'seq' => 32256, 'table_name' => 'factor', 'pk_val' => '101', 'op' => 'D',
    'before_json' => json_encode(['id' => 101, 'company_id' => 5]),
], $tableCols, $tablePks);
ok($p3['kind'] === 'delete' && $p3['sql'] === 'DELETE FROM `factor` WHERE `id` = ?' && $p3['params'] === [101], 'single-PK delete from before_json');

// 4. Delete (D) with composite PK from before_json
$p4 = de_mirror_plan_row([
    'seq' => 32257, 'table_name' => 'customer_rank', 'pk_val' => '5:17030', 'op' => 'D',
    'before_json' => json_encode(['company_id' => 5, 'customer_id' => 17030, 'rank' => 'loyal']),
], $tableCols, $tablePks);
ok($p4['kind'] === 'delete' && $p4['sql'] === 'DELETE FROM `customer_rank` WHERE `company_id` = ? AND `customer_id` = ?' && $p4['params'] === [5, 17030], 'composite-PK delete from before_json');

// 5. Delete (D) with composite PK fallback to pk_val when before_json is null
$p5 = de_mirror_plan_row([
    'seq' => 32258, 'table_name' => 'customer_rank', 'pk_val' => '5:17030', 'op' => 'D',
    'before_json' => null,
], $tableCols, $tablePks);
ok($p5['kind'] === 'delete' && $p5['params'] === ['5', '17030'], 'composite-PK delete falls back to pk_val');

// 6. DDL row is logged, never blindly executed
$p6 = de_mirror_plan_row([
    'seq' => 32259, 'table_name' => 'factor', 'pk_val' => '0', 'op' => 'DDL',
    'after_json' => '{"sql":"ALTER TABLE factor ADD COLUMN x INT"}',
], $tableCols, $tablePks);
ok($p6['kind'] === 'ddl' && $p6['sql'] === null, 'DDL does not execute arbitrary SQL');

// 7. SQL-injection table name rejected
$p7 = de_mirror_plan_row([
    'seq' => 32260, 'table_name' => 'factor; DROP TABLE user;--', 'pk_val' => '1', 'op' => 'I',
    'after_json' => '{"id":1}',
], $tableCols, $tablePks);
ok($p7['kind'] === 'skip' && $p7['reason'] === 'invalid_table_name', 'invalid table name rejected');

// 8. Lag computation: caught up vs within SLA vs breached SLA
$l1 = de_mirror_compute_lag(32332, 32332, '2026-11-25 15:30:00', '2026-11-25 15:25:00');
ok($l1['seq_gap'] === 0 && $l1['lag_seconds'] === 0 && $l1['sla_ok'] === true, 'lag=0 when seq_gap=0');
$l2 = de_mirror_compute_lag(32335, 32332, '2026-11-25 15:30:45', '2026-11-25 15:30:00');
ok($l2['seq_gap'] === 3 && $l2['lag_seconds'] === 45 && $l2['sla_ok'] === true, 'lag=45s passes 120s SLA');
$l3 = de_mirror_compute_lag(32400, 32332, '2026-11-25 15:35:00', '2026-11-25 15:30:00');
ok($l3['seq_gap'] === 68 && $l3['lag_seconds'] === 300 && $l3['sla_ok'] === false, 'lag=300s breaches 120s SLA');

// 9. Parity comparison: exact match vs mismatch
$irRows = [];
$deRows = [];
foreach (DE_MIRROR_PARITY_TABLES as $tbl => $_pk) {
    $irRows[] = ['tbl' => $tbl, 'cnt' => 100, 'max_pk' => 500];
    $deRows[] = ['tbl' => $tbl, 'cnt' => 100, 'max_pk' => 500];
}
$c1 = de_mirror_compare_parity($irRows, $deRows);
ok($c1['all_match'] === true && count($c1['tables']) === 8, 'parity all_match=true when 8/8 tables match');
$deRows[0]['cnt'] = 99;
$c2 = de_mirror_compare_parity($irRows, $deRows);
ok($c2['all_match'] === false && $c2['tables']['factor']['cnt_diff'] === 1, 'parity detects 1-row drift');

echo "\nTOTAL: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
