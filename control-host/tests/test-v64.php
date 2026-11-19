<?php
// Selftest of control-host v64 (My-Control-Project app v64, owner decision 1405-08-28). Not deployed: outside files/.
//   php control-host/tests/test-v64.php [<Agent-Project-Backup checkout>]
// Checks: the unsigned ping says only booleans + vault state (no folder listing, vault file names or open_basedir);
// public_html/status.php shape is ages and booleans only; app/sessions.php turns live-sessions folders into the
// read-only rows the app's «سشن‌ها» screen shows (whitelisted fields, valid task and session URL only).
require __DIR__ . '/../files/app/lib.php';
require __DIR__ . '/../files/app/sessions.php';

$fail = 0; $n = 0;
function ok(bool $c, string $what): void { global $fail, $n; $n++; if (!$c) { $fail++; echo "✗ $what\n"; } }

// ---- ping ----
$vault = ['state' => 'ok', 'at' => 1795062602, 'dir' => true, 'file' => true, 'readable' => true,
          'home_listed' => ['holding-vault', 'holding-vault.zip'], 'vault_listed' => ['gh-write-host.php'], 'open_basedir' => '/home/x/'];
$p = ctl_ping_payload(true, true, true, $vault, 1795062700);
ok(array_keys($p) === ['ok', 'secrets', 'device_keys', 'gh_token', 'vault', 'time'], 'ping keys');
ok(array_keys($p['vault']) === ['state', 'at'], 'ping vault is state + at only');
$js = json_encode($p);
foreach (['home_listed', 'vault_listed', 'open_basedir', 'holding-vault', 'gh-write-host', '/home/'] as $bad) ok(!str_contains($js, $bad), "ping leaks $bad");
ok(ctl_ping_payload(false, false, false, ['state' => 'tick never ran'], 1)['vault'] === ['state' => 'tick never ran', 'at' => null], 'ping before first tick');

// ---- status.php ----
$s = ctl_status_payload(['version' => '20261119080000', 'ref' => 'main', 'at' => '2026-11-19T04:30:02+00:00', 'changed' => 0],
                        ['at' => 1795062580], $vault, true, 1795062700);
ok(array_keys($s) === ['ok', 'deployed', 'last_tick_age_s', 'vault_state', 'vault_age_s', 'secrets', 'time'], 'status keys');
ok($s['deployed'] === ['version' => '20261119080000', 'at' => '2026-11-19T04:30:02+00:00'], 'status deployed = version + at');
ok($s['last_tick_age_s'] === 120 && $s['vault_age_s'] === 98 && $s['vault_state'] === 'ok' && $s['secrets'] === true, 'status ages');
$js = json_encode($s);
foreach (['home_listed', 'holding-vault', 'gh-', '/home/', 'open_basedir'] as $bad) ok(!str_contains($js, $bad), "status leaks $bad");
$e = ctl_status_payload(null, null, null, false, 5);
ok($e['deployed'] === null && $e['last_tick_age_s'] === null && $e['vault_state'] === null, 'status before first deploy/tick');
ok(is_file(__DIR__ . '/../files/public_html/status.php'), 'public_html/status.php exists');

// ---- sessions ----
$r = sess_row('b8974', ['task' => 'b8974', 'session_id' => 'session_01abc', 'url' => 'https://claude.ai/code/session_01abc',
    'created_utc' => '2026-11-13T16:05:37Z', 'listener' => 'git-poll', 'listener_heartbeat_utc' => '2026-11-13T16:05:37Z', 'secret' => 'x', 'relays' => 3]);
ok(array_keys($r) === ['task', 'url', 'created_utc', 'last_relay_utc', 'listener', 'listener_heartbeat_utc', 'listener_off_reason'], 'row fields whitelisted');
ok($r['url'] === 'https://claude.ai/code/session_01abc' && $r['last_relay_utc'] === null, 'row values');
ok(sess_row('b1', ['url' => 'https://evil.example/x'])['url'] === null, 'foreign url dropped');
ok(sess_row('b1', ['url' => 'https://claude.ai/code/cse_01abc'])['url'] === 'https://claude.ai/code/session_01abc', 'cse_ prefix normalised like op=hub');
ok(sess_row('../etc', []) === null && sess_row('MANIFEST.md', []) === null, 'invalid task refused');
ok(sess_row('t-p-stylist-hesabdari-sty-02', null)['url'] === null, 'folder without session.json is a row without url');
$b = sessions_build(['b2' => null, 'README.md' => null, 't-a' => ['url' => 'https://claude.ai/code/session_1']]);
ok(array_column($b, 'task') === ['b2', 't-a'], 'build skips non-task names and sorts by task');

$root = $argv[1] ?? '';
if ($root !== '' && is_dir($root . '/' . SESS_DIR)) {
    $loc = sessions_from_local($root);
    $tasks = array_column($loc, 'task');
    ok(count($loc) >= 10, 'local checkout has rows (' . count($loc) . ')');
    ok(in_array('t-projects-hub', $tasks, true), 'local has t-projects-hub');
    ok(!in_array('MANIFEST.md', $tasks, true) && !in_array('README.md', $tasks, true), 'local skips files');
    $hub = $loc[array_search('t-projects-hub', $tasks, true)];
    ok(is_string($hub['url']) && str_starts_with($hub['url'], 'https://claude.ai/code/session_'), 'local hub url');
}

echo ($fail ? "✗ $fail of $n failed\n" : "✓ all $n passed\n");
exit($fail ? 1 : 0);
