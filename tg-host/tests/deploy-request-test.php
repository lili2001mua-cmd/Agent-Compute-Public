<?php
// Offline-ish test of phase9 (session-approved proposals): valid SHA with a tg-host manifest is pinned once; malformed,
// unchanged and manifest-less SHAs are not. Uses the real public repo for the manifest check (network).
$base = sys_get_temp_dir() . '/tgdr' . getmypid(); @mkdir($base . '/var', 0700, true);
define('TG_BASE', $base); const TG_REPO = 'x/y';
function tg_var($r = '') { return TG_BASE . '/var/' . $r; }
function tg_read_json($p) { return is_file($p) ? json_decode(file_get_contents($p), true) : null; }
function tg_write_json($p, $d) { file_put_contents($p, json_encode($d)); }
function tg_log($a, $b) {}
$GLOBALS['REQ'] = '';
function tg_gh($m, $p) { return [200, ['content' => base64_encode($GLOBALS['REQ'])]]; }
require __DIR__ . '/../files/app/jobs/phase9.php';
$fail = 0; $ok = function ($c, $l) use (&$fail) { echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n"; if (!$c) $fail++; };
$OLD = str_repeat('b', 40); file_put_contents(TG_BASE . '/deploy-ref.txt', $OLD . "\n");
$run = function ($sha) { $GLOBALS['REQ'] = json_encode(['sha' => $sha]); @unlink(tg_var('deploy-state.json')); tg_deploy_request_tick(); return trim(file_get_contents(TG_BASE . '/deploy-ref.txt')); };
$ok($run('not-a-sha') === $OLD, 'malformed SHA ignored');
$ok($run(str_repeat('c', 40)) === $OLD && !empty(tg_read_json(tg_var('deploy-state.json'))['rejected_sha']), 'SHA without a tg-host manifest rejected');
$REAL = $argv[1] ?? '';
if ($REAL !== '') {
    $ok($run($REAL) === $REAL && (tg_read_json(tg_var('deploy-state.json'))['write_ok'] ?? false), 'real SHA with manifest pinned');
    $ok($run($REAL) === $REAL && empty(tg_read_json(tg_var('deploy-state.json'))['applied_sha']), 'same SHA again is a no-op');
}
echo "\n# deploy request tests: $fail failures\n"; exit($fail ? 1 : 0);
