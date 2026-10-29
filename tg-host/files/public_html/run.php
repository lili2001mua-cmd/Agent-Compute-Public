<?php
// tg door — JSON API only (no page, no layout). POST only; key ONLY in the X-Tg-Key header (never ?key=).
// The door never runs a job: it queues, reports status, and returns small results. Media is served
// statically from public_html/m/<128-bit>/ (never streamed through PHP — 30 s web limit).
// Body (JSON): {"action":"submit","job":"…","args":{…},"idem":"<client idempotency key>"}
//              {"action":"status","id":"…"} · {"action":"result","id":"…"}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
require __DIR__ . '/../app/lib.php';

function out(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
/** Roles a job accepts: explicit `roles`, else the old `role` (read ⇒ read+admin, admin ⇒ admin). No role ladder (ک2). */
function tg_job_roles(array $j): array { return $j['roles'] ?? (($j['role'] ?? 'admin') === 'read' ? ['read', 'admin'] : ['admin']); }
/** Chunked upload (ک9): header X-Tg-Upload: <token32>:<index>:<total>:<sha256 of chunk>:<ext>; ≤4 MB a chunk, ≤20 MB a file. */
function tg_upload_chunk(string $role, string $hdr): void {
    if ($role !== 'chain' && $role !== 'admin') out(403, ['error' => 'chain key required']);
    if (!preg_match('/^([0-9a-f]{32}):(\d{1,2}):(\d{1,2}):([0-9a-f]{64}):(jpg|jpeg|png|webp|gif|pdf|txt|md|json|mp4|mov|m4v|webm|ogg|mp3)$/', $hdr, $m)) out(400, ['error' => 'bad upload header']);
    [, $tok, $i, $n, $sha, $ext] = $m; $i = (int) $i; $n = (int) $n;
    if ($n < 1 || $n > 5 || $i >= $n) out(400, ['error' => 'bad chunk index']);
    $body = (string) file_get_contents('php://input', false, null, 0, 4 * 1048576 + 1);
    if (strlen($body) > 4 * 1048576 || hash('sha256', $body) !== $sha) out(400, ['error' => 'chunk too big or hash mismatch']);
    $dir = tg_dir(tg_var('up/' . $tok . '.parts'));
    file_put_contents($dir . '/' . $i, $body, LOCK_EX);
    $have = count(glob($dir . '/*') ?: []);
    if ($have < $n) out(202, ['ok' => true, 'have' => $have, 'total' => $n]);
    $dest = tg_var('up/' . $tok . '.bin'); $fh = fopen($dest, 'wb'); $size = 0;
    for ($k = 0; $k < $n; $k++) { $c = (string) file_get_contents($dir . '/' . $k); $size += strlen($c); fwrite($fh, $c); }
    fclose($fh); tg_rm_r($dir);
    if ($size > 20 * 1048576) { @unlink($dest); out(413, ['error' => 'file over 20 MB']); }
    out(200, ['ok' => true, 'upload' => $tok, 'bytes' => $size, 'sha256' => hash_file('sha256', $dest)]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['error' => 'POST only']);
if (isset($_SERVER['HTTP_X_TG_INTAKE'])) {
    require_once __DIR__ . '/../app/jobs/phase6.php';
    $in0 = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
    [$code, $body] = tg_chain_intake((string) $_SERVER['HTTP_X_TG_INTAKE'], is_array($in0) ? $in0 : []);
    out($code, $body);
}
$roles = tg_key_roles((string) ($_SERVER['HTTP_X_TG_KEY'] ?? ''));
if (!$roles) out(401, ['error' => 'unauthorized']);
$role = $roles[0];
if (isset($_SERVER['HTTP_X_TG_UPLOAD'])) tg_upload_chunk(array_intersect(['chain', 'admin'], $roles) ? 'chain' : $role, (string) $_SERVER['HTTP_X_TG_UPLOAD']);
$in = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
if (!is_array($in)) out(400, ['error' => 'bad json']);
$reg = include __DIR__ . '/../app/jobs/registry.php';
$action = (string) ($in['action'] ?? '');
$validId = fn($s) => is_string($s) && preg_match('/^[0-9a-f]{32}$/', $s);

if ($action === 'submit') {
    $job = (string) ($in['job'] ?? '');
    if (!isset($reg[$job])) out(400, ['error' => 'unknown job']);
    $role = array_values(array_intersect(tg_job_roles($reg[$job]), $roles))[0] ?? null; // the job's first role this key holds
    if ($role === null) out(403, ['error' => 'role not allowed for this job']);
    $idem = (string) ($in['idem'] ?? '');
    if (strlen($idem) < 8 || strlen($idem) > 128) out(400, ['error' => 'idem required (8-128 chars)']);
    $idemFile = tg_dir(tg_var('idem')) . '/' . hash('sha256', $role . '|' . $idem);
    if (is_file($idemFile)) out(200, ['ok' => true, 'id' => trim((string) file_get_contents($idemFile)), 'deduplicated' => true]);
    $given = is_array($in['args'] ?? null) ? $in['args'] : [];
    $args = [];
    foreach ($reg[$job]['args'] as $name => $rule) {
        if (!array_key_exists($name, $given)) out(400, ['error' => "missing arg $name"]);
        $v = $given[$name];
        $r = explode(':', $rule, 3);
        if ($r[0] === 'int') { if (!is_int($v) || $v < (int) $r[1] || $v > (int) $r[2]) out(400, ['error' => "bad arg $name"]); }
        elseif ($r[0] === 'enum') { if (!in_array($v, explode(',', $r[1]), true)) out(400, ['error' => "bad arg $name"]); }
        elseif ($r[0] === 'str') { if (!is_string($v) || !preg_match('/^' . substr($rule, 4) . '$/u', $v)) out(400, ['error' => "bad arg $name"]); } // the pattern may hold ':'
        elseif ($r[0] === 'json') { if (!is_string($v) || strlen($v) > 60000 || !is_array(json_decode($v, true))) out(400, ['error' => "bad arg $name"]); }
        else out(500, ['error' => 'bad rule']);
        $args[$name] = $v;
    }
    foreach (array_keys($given) as $k) { if (!isset($reg[$job]['args'][$k])) out(400, ['error' => "unexpected arg $k"]); }
    $queued = count(glob(tg_var('queue') . '/*.json') ?: []);
    if ($queued >= TG_QUEUE_CAP) out(429, ['error' => 'queue full']);
    $id = tg_id();
    tg_status($id, ['id' => $id, 'job' => $job, 'state' => 'queued', 'created_at' => time(), 'role' => $role]);
    tg_write_json(tg_dir(tg_var('queue')) . '/' . time() . '-' . $job . '-' . $id . '.json',
        ['id' => $id, 'job' => $job, 'args' => $args, 'role' => $role, 'attempt' => 0, 'not_before' => 0]);
    file_put_contents($idemFile, $id, LOCK_EX);
    out(202, ['ok' => true, 'id' => $id, 'queue_pos' => $queued + 1]);
}
if ($action === 'status' || $action === 'result') {
    $id = $in['id'] ?? '';
    if (!$validId($id)) out(400, ['error' => 'bad id']);
    $st = tg_read_json(tg_job_dir($id) . '/status.json');
    if (!$st || (!in_array($st['role'] ?? '', $roles, true) && !in_array('admin', $roles, true))) out(404, ['error' => 'no such job']);
    if ($action === 'status') {
        if (($st['state'] ?? '') === 'queued') {
            $q = glob(tg_var('queue') . '/*.json') ?: []; sort($q);
            foreach ($q as $i => $f) { if (str_contains($f, $id)) { $st['queue_pos'] = $i + 1; break; } }
        }
        $st['tick_age_s'] = time() - (int) @file_get_contents(tg_var('last-tick.txt'));
        out(200, ['ok' => true, 'status' => $st]);
    }
    if (($st['state'] ?? '') !== 'done') out(409, ['error' => 'not done', 'state' => $st['state'] ?? null]);
    out(200, ['ok' => true, 'result' => tg_read_json(tg_job_dir($id) . '/result.json')]);
}
if ($action === 'bot' || $action === 'drive_token' || $action === 'config') {
    if (!array_intersect(['chain', 'admin'], $roles)) out(403, ['error' => 'chain key required']);
    require_once __DIR__ . '/../app/jobs/phase6.php';
    if ($action === 'config') { $s = tg_chain_secrets(); out(200, ['ok' => true, 'TELEGRAM_POOL_CHAT_ID' => $s['TELEGRAM_POOL_CHAT_ID'] ?? null, 'TG_ARCHIVE_CHAT_ID' => $s['TG_ARCHIVE_CHAT_ID'] ?? null, 'have' => array_keys($s)]); }
    [$code, $body] = $action === 'bot' ? tg_chain_bot($in) : tg_chain_drive_token($in, $roles);
    out($code, $body);
}
if ($action === 'lease' || $action === 'beat') {
    if (!array_intersect(['chain', 'admin'], $roles)) out(403, ['error' => 'chain key required']);
    require_once __DIR__ . '/../app/jobs/phase6.php';
    [$code, $body] = $action === 'lease' ? tg_chain_lease($in) : tg_chain_beat($in);
    out($code, $body);
}
out(400, ['error' => 'unknown action']);
