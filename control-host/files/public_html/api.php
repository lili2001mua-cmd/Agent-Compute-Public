<?php
// The only door of control.hohedoc.ir. Every call is signed by the phone (see app/lib.php ctl_auth).
//   GET  api.php?op=status                       → cached live state of every workflow
//   GET  api.php?op=ping                         → unsigned, {"ok":true,"secrets":bool} (no data)
//   POST api.php?op=toggle-request  {workflow, action: enable|disable, reason}  → challenge
//   POST api.php?op=toggle-confirm  {challenge}  → runs it: GitHub state + registry rows in one [skip ci] commit
//   POST api.php?op=backup-request  {target: redlilium-db} → one DB dump to the Telegram backup channel (sites cron runs it)
//   GET  api.php?op=backup-status                 → last backup result + pending count
//   GET  api.php?op=trainer-board                 → trainer project-control board (app/board.php, cron) + fire states
//   POST api.php?op=step3-fire     {key}         → routine.hohedoc.ir opens a step-3 session for that bundle
// Two steps on purpose: the phone asks, shows the summary, the user confirms again (all workflows are critical).
require __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function out(int $code, array $x): void { http_response_code($code); echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$op = (string) ($_GET['op'] ?? '');
$secrets = ctl_secrets_loaded();
if ($op === 'ping') out(200, ['ok' => true, 'secrets' => $secrets, 'device_keys' => defined('DEVICE_KEYS'), 'gh_token' => ctl_gh_token() !== null, 'vault' => ctl_json_read('vault.json', ['state' => 'tick never ran']), 'time' => time()]);
if (!$secrets) out(503, ['ok' => false, 'error' => 'secrets missing on host: ' . (defined('DEVICE_KEYS') ? 'GitHub token (vault)' : 'secrets.php')]);

$body = (string) file_get_contents('php://input');
if (strlen($body) > 4096) out(413, ['ok' => false, 'error' => 'too large']);
$why = ctl_auth($op, $body);
if ($why !== null) { ctl_log("auth fail op=$op $why"); out(401, ['ok' => false, 'error' => $why]); }

if ($op === 'trainer-board' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // computed every 5 minutes by the cron from the trainer's records on GitHub (app/board.php); never written by a session
    $b = @json_decode((string) @file_get_contents(ctl_var('trainer-board') . '/board.json'), true);
    $st = @json_decode((string) @file_get_contents(ctl_var('trainer-board') . '/board-state.json'), true);
    $fire = @json_decode((string) @file_get_contents(ctl_var('fire-state.json')), true);   // written by routine.hohedoc.ir
    out(200, ['ok' => true, 'state' => $st, 'board' => $b, 'fire' => is_array($fire) ? $fire : (object) []]);
}

if ($op === 'backup-status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // written by the sites.hohedoc.ir cron (app/phone-backup.php) into our var/
    $pending = count(glob(ctl_var('backup-req') . '/*.json') ?: []);
    out(200, ['ok' => true, 'pending' => $pending, 'last' => ctl_json_read('backup-state.json', null)]);
}

if ($op === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    out(200, ['ok' => true] + ctl_json_read('status.json', ['error' => 'no status yet']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false]);
$in = json_decode($body, true);
if (!is_array($in)) out(400, ['ok' => false, 'error' => 'bad json']);

if ($op === 'toggle-request') {
    $wf = (string) ($in['workflow'] ?? '');
    $action = (string) ($in['action'] ?? '');
    $reason = trim((string) ($in['reason'] ?? ''));
    if (!preg_match('/^[a-z0-9._-]+\.ya?ml$/', $wf) || !in_array($action, ['enable', 'disable'], true)) out(400, ['ok' => false, 'error' => 'bad workflow or action']);
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) out(400, ['ok' => false, 'error' => 'reason 3-200 chars']);
    $st = ctl_json_read('status.json', []);
    $row = null;
    foreach (($st['workflows'] ?? []) as $w) if ($w['file'] === $wf) $row = $w;
    if (!$row) out(404, ['ok' => false, 'error' => 'unknown workflow']);
    if ($action === 'enable' && !empty($row['retired_to'])) out(409, ['ok' => false, 'error' => 'retired workflow — its work moved to ' . $row['retired_to'] . '; it is never re-enabled']);
    $id = bin2hex(random_bytes(12));
    $ch = ctl_json_read('challenges.json', []);
    foreach ($ch as $k => $c) if ($c['exp'] < time()) unset($ch[$k]);
    $ch[$id] = ['workflow' => $wf, 'action' => $action, 'reason' => $reason, 'exp' => time() + CTL_CHALLENGE_TTL,
                'device' => (string) $_SERVER['HTTP_X_HC_DEVICE']];
    ctl_json_write('challenges.json', $ch);
    out(200, ['ok' => true, 'challenge' => $id, 'expires_in' => CTL_CHALLENGE_TTL,
              'summary' => ['workflow' => $wf, 'action' => $action, 'reason' => $reason, 'state_now' => $row['state']]]);
}

if ($op === 'backup-request') {
    // One press = one dump of the redlilium database into the private Telegram backup channel.
    if (($in['target'] ?? '') !== 'redlilium-db') out(400, ['ok' => false, 'error' => 'unknown target']);
    $dir = ctl_var('backup-req');
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    if (glob($dir . '/*.json')) out(409, ['ok' => false, 'error' => 'a backup is already waiting']);
    $st = ctl_json_read('backup-state.json', []);
    if (($st['state'] ?? '') === 'running' && time() - (int) ($st['started_at'] ?? 0) < 1200) out(409, ['ok' => false, 'error' => 'a backup is running']);
    $id = bin2hex(random_bytes(6));
    file_put_contents($dir . '/' . time() . '-' . $id . '.json', json_encode(['id' => $id, 'target' => 'redlilium-db', 'at' => time(),
        'device' => (string) $_SERVER['HTTP_X_HC_DEVICE']]), LOCK_EX);
    ctl_log("backup-request $id");
    out(200, ['ok' => true, 'id' => $id, 'note' => 'the sites.hohedoc.ir cron picks it up within a minute']);
}

if ($op === 'step3-fire') {
    // «سشن بساز و اجرا کن»: one bundle waiting in step 3 without a session → routine.hohedoc.ir fires the
    // account's dispatcher with job=step3-bundle (Memory-Routine-Runbook/step3-bundle.md). We only drop a file.
    $key = (string) ($in['key'] ?? '');
    if (!preg_match('/^b[0-9]{1,8}$/', $key)) out(400, ['ok' => false, 'error' => 'bad key']);
    $b = @json_decode((string) @file_get_contents(ctl_var('trainer-board') . '/board.json'), true) ?: [];
    $row = null; foreach (($b['bundles'] ?? []) as $x) if (($x['key'] ?? '') === $key) $row = $x;
    if (!$row || ($row['kind'] ?? '') !== 'nightly') out(409, ['ok' => false, 'error' => 'bundle has a session or is not waiting in step 3']);
    $dir = ctl_var('fire-req');
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    if (glob($dir . '/*.json')) out(409, ['ok' => false, 'error' => 'a session request is already waiting']);
    $fs = @json_decode((string) @file_get_contents(ctl_var('fire-state.json')), true) ?: [];
    if (($fs[$key]['state'] ?? '') === 'fired' && time() - (int) ($fs[$key]['at'] ?? 0) < 3600) out(409, ['ok' => false, 'error' => 'a session for this bundle was started less than an hour ago']);
    file_put_contents($dir . '/' . time() . '-' . $key . '.json', json_encode(['key' => $key, 'at' => time(),
        'device' => (string) $_SERVER['HTTP_X_HC_DEVICE']]), LOCK_EX);
    ctl_log("step3-fire request $key");
    out(200, ['ok' => true, 'key' => $key, 'note' => 'routine.hohedoc.ir fires it within 5 minutes']);
}

if ($op === 'toggle-confirm') {
    $id = (string) ($in['challenge'] ?? '');
    $ch = ctl_json_read('challenges.json', []);
    $c = $ch[$id] ?? null;
    unset($ch[$id]);
    ctl_json_write('challenges.json', $ch);
    if (!$c || $c['exp'] < time() || $c['device'] !== (string) $_SERVER['HTTP_X_HC_DEVICE']) out(410, ['ok' => false, 'error' => 'challenge expired or unknown']);
    require __DIR__ . '/../app/toggle.php';
    $r = ctl_toggle($c['workflow'], $c['action'], $c['reason']);
    ctl_log('toggle ' . $c['action'] . ' ' . $c['workflow'] . ' => ' . json_encode($r));
    out($r['ok'] ? 200 : 502, $r);
}

out(404, ['ok' => false, 'error' => 'unknown op']);
