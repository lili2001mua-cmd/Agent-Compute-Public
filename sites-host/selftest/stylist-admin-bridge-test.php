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

// cleanup
@unlink($tmpCtrl . '/stylist-admin-state.json');
@unlink($tmpCtrl . '/stylist-admin-last-action.json');
@rmdir($tmpCtrl . '/stylist-admin-req');
@rmdir($tmpCtrl);

echo "\nSummary: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
