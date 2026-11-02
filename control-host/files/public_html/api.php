<?php
// The only door of control.hohedoc.ir. Every call is signed by the phone (see app/lib.php ctl_auth).
//   GET  api.php?op=status                       → cached live state of every workflow
//   GET  api.php?op=ping                         → unsigned, {"ok":true,"secrets":bool} (no data)
//   POST api.php?op=toggle-request  {workflow, action: enable|disable, reason}  → challenge
//   POST api.php?op=toggle-confirm  {challenge}  → runs it: GitHub state + registry rows in one [skip ci] commit
// Two steps on purpose: the phone asks, shows the summary, the user confirms again (all workflows are critical).
require __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function out(int $code, array $x): void { http_response_code($code); echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$op = (string) ($_GET['op'] ?? '');
$secrets = ctl_secrets_loaded();
if ($op === 'ping') out(200, ['ok' => true, 'secrets' => $secrets, 'device_keys' => defined('DEVICE_KEYS'), 'gh_token' => ctl_gh_token() !== null, 'time' => time()]);
if (!$secrets) out(503, ['ok' => false, 'error' => 'secrets missing on host: ' . (defined('DEVICE_KEYS') ? 'GitHub token (vault)' : 'secrets.php')]);

$body = (string) file_get_contents('php://input');
if (strlen($body) > 4096) out(413, ['ok' => false, 'error' => 'too large']);
$why = ctl_auth($op, $body);
if ($why !== null) { ctl_log("auth fail op=$op $why"); out(401, ['ok' => false, 'error' => $why]); }

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
