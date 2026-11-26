<?php
// Self-test for sites-host/files/app/stylist_admin_bridge.php (Stylist Admin Control App backend).
require_once __DIR__ . '/../files/app/stylist_admin_bridge.php';

$pass = 0; $fail = 0;
function t_assert(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { echo "  ✓ $label\n"; $pass++; }
    else { echo "  ✗ FAIL: $label\n"; $fail++; }
}

$tmpCtrl = sys_get_temp_dir() . '/stylist_admin_ctrl_' . getmypid() . '_' . bin2hex(random_bytes(3));
@mkdir($tmpCtrl . '/stylist-admin-req', 0755, true);

// 1) Atomic snapshot publish
$snap = stylist_admin_publish_snapshot(false, $tmpCtrl);
t_assert($snap['published_to_control'] === true, '1) Snapshot atomically published to control var/');
t_assert(is_file($tmpCtrl . '/stylist-admin-state.json'), '2) stylist-admin-state.json exists on disk');
$decoded = json_decode((string) file_get_contents($tmpCtrl . '/stylist-admin-state.json'), true);
t_assert(($decoded['schema'] ?? '') === 'stylist-admin-v1', '3) Schema is stylist-admin-v1');
t_assert(($decoded['apk_download']['version'] ?? '') === '2.4.0', '4) APK version 2.4.0 exposed');

// 2) Action validation guards
$badAct = stylist_admin_execute_action(['id' => 'a1', 'action' => 'drop_database']);
t_assert($badAct['ok'] === false && $badAct['error'] === 'action_not_allowed', '5) Unknown action strictly rejected');

$badRid = stylist_admin_execute_action(['id' => 'a2', 'action' => 'release_promote', 'release_id' => '../evil']);
t_assert($badRid['ok'] === false && $badRid['error'] === 'invalid_release_id', '6) Path traversal release_id strictly rejected');

// 3) release_assign_900 payload
$captured = [];
$mockBridge = function (array $payload) use (&$captured): array {
    $captured[] = $payload;
    return ['ok' => true, 'state' => ['default_stable' => 'R0004', 'branch_overrides' => ['900' => $payload['release_id'] ?? 'R0005']]];
};
$rAssign = stylist_admin_execute_action(
    ['id' => 'a3', 'action' => 'release_assign_900', 'release_id' => 'r0005', 'reason' => 'canary test'],
    $mockBridge
);
t_assert($rAssign['ok'] === true && ($captured[0]['op'] ?? '') === 'assign' && ($captured[0]['company_id'] ?? 0) === 900 && ($captured[0]['release_id'] ?? '') === 'R0005',
    '7) release_assign_900 normalizes R0005 & targets company_id=900');

// 4) release_promote & release_rollback
$rProm = stylist_admin_execute_action(['id' => 'a4', 'action' => 'release_promote', 'release_id' => 'R0005'], $mockBridge);
t_assert($rProm['ok'] === true && ($captured[1]['op'] ?? '') === 'promote', '8) release_promote dispatches op=promote');

$rRoll = stylist_admin_execute_action(['id' => 'a5', 'action' => 'release_rollback', 'release_id' => 'R0004'], $mockBridge);
t_assert($rRoll['ok'] === true && ($captured[2]['op'] ?? '') === 'rollback' && ($captured[2]['target_release_id'] ?? '') === 'R0004',
    '9) release_rollback dispatches op=rollback with target_release_id');

// 5) Queue drain + db_parity_now
file_put_contents(
    $tmpCtrl . '/stylist-admin-req/1700000000-q1.json',
    json_encode(['id' => 'q1', 'action' => 'db_parity_now'])
);
$qRes = stylist_admin_process_queue($tmpCtrl, $mockBridge, fn() => ['ok' => true, 'all_match' => true, 'tables_primary' => 75, 'tables_standby' => 75]);
t_assert($qRes['processed'] === 1 && ($qRes['last']['ok'] ?? false) === true && ($qRes['last']['result']['tables_primary'] ?? 0) === 75,
    '10) Queue drains db_parity_now & runs parity check');
t_assert(!is_file($tmpCtrl . '/stylist-admin-req/1700000000-q1.json'), '11) Processed request file deleted cleanly');
t_assert(is_file($tmpCtrl . '/stylist-admin-last-action.json'), '12) stylist-admin-last-action.json written atomically');

// 6) Real producer->consumer contract test for STY-03 (db_parity_audit.php -> stylist_admin_normalize_db_parity)
require_once __DIR__ . '/../files/app/db_parity_audit.php';
$realCmp = db_parity_compare(
    ['factor', 'desk_log'],
    ['factor', 'desk_log'],
    [
        'factor'   => ['cnt' => 10, 'max_pk' => 10, 'content_hash' => '111'],
        'desk_log' => ['cnt' => 5,  'max_pk' => 5,  'content_hash' => '222'],
    ],
    [
        'factor'   => ['cnt' => 10, 'max_pk' => 10, 'content_hash' => '111'],
        'desk_log' => ['cnt' => 4,  'max_pk' => 4,  'content_hash' => '333'],
    ]
);
$realAlert = db_parity_eval_alert([], $realCmp, 1700000000, '1405-09-05 16:00');
$real7 = db_parity_update_7day(
    ['days' => [['day' => '1405-09-04', 'ok' => true, 'checks' => 7, 'mismatches' => 0]]],
    '1405-09-05',
    $realCmp,
    '2026-11-26T12:30:00+00:00',
    $realAlert['state']['consecutive_mismatches']
);
t_assert(($real7['consecutive_green_days'] ?? 0) === 2 && ($real7['consecutive_clean_days'] ?? 0) === 2,
    '13) db_parity_update_7day emits both consecutive_green_days=2 and consecutive_clean_days=2');
t_assert(($real7['days'][0]['all_match'] ?? false) === true && ($real7['days'][0]['date_jalali'] ?? '') === '1405-09-04',
    '14) db_parity_update_7day backfills all_match & date_jalali on existing days');
$realState = [
    'ok'             => true,
    'at'             => '2026-11-26T12:30:00+00:00',
    'all_match'      => $realCmp['all_match'],
    'tables_primary' => $realCmp['tables_primary'],
    'tables_standby' => $realCmp['tables_standby'],
    'mismatches'     => $realCmp['mismatches'],
    'alert'          => $realAlert['state'],
    'days_7_streak'  => $real7['consecutive_green_days'],
];
$normParity = stylist_admin_normalize_db_parity($realState, $real7);
t_assert(($normParity['consecutive_days'] ?? 0) === 2 && ($normParity['mismatched_tables'] ?? []) === ['desk_log'] && ($normParity['alert_active'] ?? true) === false,
    '15) stylist_admin_normalize_db_parity maps real db_parity_audit output without key drift');

// 7) STY-14 automatic DB query fallback when sync.php returns HTTP 400 action not allowed
$fallbackCaller = function (array $payload): array {
    if (($payload['action'] ?? '') === 'release') {
        return [400, ['ok' => false, 'error' => 'action'], null];
    }
    if (($payload['action'] ?? '') === 'query' && str_contains((string) ($payload['sql'] ?? ''), 'app_releases')) {
        return [200, ['ok' => true, 'rows' => [
            ['release_id' => 'R0002', 'status' => 'staged', 'git_sha' => 'abc-canary', 'notes' => 'canary 900'],
            ['release_id' => 'R0001', 'status' => 'stable', 'git_sha' => 'abc', 'notes' => 'baseline'],
        ]], null];
    }
    if (($payload['action'] ?? '') === 'query' && str_contains((string) ($payload['sql'] ?? ''), 'branch_release_assignments')) {
        return [200, ['ok' => true, 'rows' => [
            ['company_id' => 900, 'release_id' => 'R0002', 'scope_type' => 'branch', 'reason' => 'canary'],
        ]], null];
    }
    return [404, ['ok' => false, 'error' => 'unexpected'], null];
};
$relFb = stylist_admin_fetch_releases(true, $fallbackCaller);
t_assert(
    ($relFb['ok'] ?? false) === true
    && ($relFb['default_stable'] ?? '') === 'R0001'
    && ($relFb['branch_900'] ?? '') === 'R0002'
    && count($relFb['releases'] ?? []) === 2
    && ($relFb['source'] ?? '') === 'db_query_fallback',
    '16) STY-14 falls back to live DB query on HTTP 400 action and resolves R0001/R0002'
);

// 8) Real producer->consumer contract test for STY-05 (de_mirror_sync embedded sla_24h + at)
$normRep = stylist_admin_normalize_replication([
    'ok'             => true,
    'at'             => '2026-11-26T13:01:02+00:00',
    'de_max_seq'     => 42360,
    'total_applied'  => 10107,
    'lag_seconds'    => 15,
    'seq_gap'        => 1,
    'sla_target_sec' => 120,
    'sla_ok'         => true,
    'sla_24h'        => ['ticks_24h' => 1378, 'breaches_24h' => 0, 'max_lag_24h' => 66, 'sla_pass_pct' => 100],
    'alert'          => ['alert_active' => false],
], null);
t_assert(
    ($normRep['last_run_utc'] ?? '') === '2026-11-26T13:01:02+00:00'
    && ($normRep['sla_target_sec'] ?? 0) === 120
    && ($normRep['sla_24h']['ticks_24h'] ?? 0) === 1378
    && ($normRep['sla_24h']['sla_pass_pct'] ?? 0) === 100
    && ($normRep['sla_24h']['alert_active'] ?? true) === false,
    '17) stylist_admin_normalize_replication reads embedded sla_24h, at, and sla_target_sec'
);

// cleanup
@unlink($tmpCtrl . '/stylist-admin-state.json');
@unlink($tmpCtrl . '/stylist-admin-last-action.json');
@rmdir($tmpCtrl . '/stylist-admin-req');
@rmdir($tmpCtrl);

echo "\nSummary: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
