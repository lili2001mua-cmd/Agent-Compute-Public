<?php
// Host live pull (phase 10, B2) — offline self-test. Not deployed.
// Runs the REAL phase3/phase6/phase7 (writer, tg_gh stubbed)/phase8/phase10 code against a fake MadelineProto API and a
// fake Google Drive (resumable protocol: 308 + Range, status query, PATCH update, transient 503), and checks name and
// source-link parity against the REAL Python functions (telegram_pool.safe_name / source_url, extracted with ast).
// Usage: php tg-host/tests/host-pull-test.php     (APB_ROOT = Agent-Project-Backup checkout, default: sibling folder)
$tmp = sys_get_temp_dir() . '/host-pull-test-' . getmypid();
@mkdir($tmp . '/app/jobs', 0777, true);
$app = __DIR__ . '/../files/app';
$apb = getenv('APB_ROOT') ?: realpath(__DIR__ . '/../../../Agent-Project-Backup');
copy($app . '/lib.php', $tmp . '/app/lib.php');
file_put_contents($tmp . '/app/keys.php', "<?php return [];");
require $tmp . '/app/lib.php';
require $app . '/jobs/phase3.php';
require $app . '/jobs/phase6.php';
$p7 = file_get_contents($app . '/jobs/phase7.php');
$cut = fn($src, $from, $to) => substr($src, strpos($src, $from), strpos($src, $to) - strpos($src, $from));
file_put_contents($tmp . '/app/jobs/p7.php', "<?php\n" . $cut($p7, 'const TG_REPO =', 'const TG_BEAT_EVERY_S') . $cut($p7, 'function tg_repo_allowed', '/** Hourly heartbeat'));
require $tmp . '/app/jobs/p7.php';
require $app . '/jobs/phase8.php';
// phase10 without its network function (tg_drive_http is the fake below)
$p10 = file_get_contents($app . '/jobs/phase10.php');
$a = strpos($p10, "/** [code, body(array|string), headers"); $b = strpos($p10, 'function tg_drive_list');
file_put_contents($tmp . '/app/jobs/p10.php', substr($p10, 0, $a) . substr($p10, $b));
require $tmp . '/app/jobs/p10.php';
$reg = include $app . '/jobs/registry.php';

$F = 0; function ok($c, $m) { global $F; echo ($c ? "  ok   " : "  FAIL ") . $m . "\n"; if (!$c) $F++; }

// ---- stubs ----------------------------------------------------------------------------------------------
$GLOBALS['gh'] = ['files' => [], 'put_code' => 201];
function tg_gh(string $method, string $path, ?array $body = null): array {
    $g = &$GLOBALS['gh'];
    if (preg_match('#/contents/(.+?)(\?ref=main)?$#', $path, $m)) {
        $p = rawurldecode($m[1]);
        if ($method === 'GET') return isset($g['files'][$p]) ? [200, ['sha' => sha1($g['files'][$p]), 'content' => base64_encode($g['files'][$p])]] : [404, []];
        if ($method === 'PUT') { if ($g['put_code'] < 300) $g['files'][$p] = base64_decode($body['content']); return [$g['put_code'], []]; }
    }
    return [500, []];
}
function tg_session_dir(): string { return TG_BASE . '/var/session'; }
function tg_mp_with(string $dir, callable $fn, int $budget = 0): array { $GLOBALS['mp_loads'] = ($GLOBALS['mp_loads'] ?? 0) + 1; return $fn($GLOBALS['fake_api']); }
function bytes_of(int $id, int $n): string { return substr(str_repeat(hash('sha256', 'm' . $id, true), intdiv($n, 32) + 1), 0, $n); }
final class FakeChannels {
    public function __construct(public array $by) {}
    public function getMessages($channel, array $id): array { return ['messages' => array_values(array_filter(array_map(fn($i) => $this->by[$i] ?? null, $id)))]; }
}
final class FakeApi {
    public FakeChannels $channels; public int $downloads = 0; public bool $short = false;
    public function __construct(array $by) { $this->channels = new FakeChannels($by); }
    public function downloadToFile(array $media, string $path): void {
        $this->downloads++;
        $doc = $media['document'] ?? null;
        $id = $doc ? (int) $doc['id'] : (int) $media['photo']['id'];
        $n = $doc ? (int) $doc['size'] : 4321;
        file_put_contents($path, bytes_of($id, $this->short ? $n - 1 : $n));
    }
}
// fake Drive ------------------------------------------------------------------------------------------------
$GLOBALS['dv'] = ['files' => [], 'sess' => [], 'n' => 0, 'fail_next' => 0, 'chunk_puts' => 0, 'skew_after_chunk' => 0, 'creates' => 0, 'updates' => 0];
function tg_drive_http(string $method, string $url, $body = null, array $hdr = []): array {
    $d = &$GLOBALS['dv'];
    $H = []; foreach ($hdr as $h) { [$k, $v] = array_map('trim', explode(':', $h, 2)); $H[strtolower($k)] = $v; }
    $file = fn($f) => ['id' => $f['id'], 'name' => $f['name'], 'md5Checksum' => md5($f['data'] ?? ''), 'webViewLink' => 'https://drive.google.com/file/d/' . $f['id'] . '/view', 'size' => (string) strlen($f['data'] ?? '')];
    if ($method === 'GET' && str_starts_with($url, 'https://www.googleapis.com/drive/v3/files?')) {
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        preg_match("/^name='((?:[^'\\\\]|\\\\.)*)' and '([^']+)' in parents( and mimeType='([^']+)')? and trashed=false$/", $q['q'], $m) || throw new RuntimeException('bad q ' . $q['q']);
        $name = stripcslashes($m[1]);
        $hits = array_values(array_filter($d['files'], fn($f) => $f['name'] === $name && $f['parent'] === $m[2] && (empty($m[4]) || ($f['mime'] ?? '') === $m[4])));
        return [200, ['files' => array_map($file, $hits)], []];
    }
    if ($method === 'POST' && str_starts_with($url, 'https://www.googleapis.com/drive/v3/files?')) {
        $j = json_decode($body, true); $id = 'F' . (++$d['n']);
        $d['files'][$id] = ['id' => $id, 'name' => $j['name'], 'parent' => $j['parents'][0], 'mime' => $j['mimeType']];
        return [200, ['id' => $id], []];
    }
    if (str_starts_with($url, 'https://www.googleapis.com/upload/drive/v3/files')) {
        $sid = 'fake://sess/' . (++$d['n']);
        $existing = preg_match('#/files/([^?]+)\?#', $url, $m) ? rawurldecode($m[1]) : null;
        $meta = json_decode($body, true) ?: [];
        $d['sess'][$sid] = ['total' => (int) $H['x-upload-content-length'], 'data' => '', 'existing' => $existing, 'meta' => $meta, 'final' => null];
        return [200, '', ['location' => $sid]];
    }
    if ($method === 'PUT' && isset($d['sess'][$url])) {
        $s = &$d['sess'][$url];
        $range = fn() => strlen($s['data']) ? ['range' => 'bytes=0-' . (strlen($s['data']) - 1)] : [];
        if ($s['final']) return [200, $file($d['files'][$s['final']]), []];
        if (preg_match('#^bytes \*/(\d+)$#', $H['content-range'], $m)) return [308, '', $range()];
        preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', $H['content-range'], $m) || throw new RuntimeException('bad range');
        $d['chunk_puts']++;
        if ($d['fail_next'] > 0) { $d['fail_next']--; return [503, '', []]; }
        if ((int) $m[1] !== strlen($s['data'])) return [308, '', $range()];
        $s['data'] .= $body;
        if ($d['skew_after_chunk'] && $d['chunk_puts'] >= $d['skew_after_chunk']) { $GLOBALS['tg_pull_skew'] = 100000; $d['skew_after_chunk'] = 0; }
        if (strlen($s['data']) < $s['total']) return [308, '', $range()];
        if ($s['existing']) { $id = $s['existing']; $d['files'][$id]['data'] = $s['data']; $d['updates']++; }
        else { $id = 'F' . (++$d['n']); $d['files'][$id] = ['id' => $id, 'name' => $s['meta']['name'], 'parent' => $s['meta']['parents'][0], 'data' => $s['data']]; $d['creates']++; }
        $s['final'] = $id;
        return [200, $file($d['files'][$id]), []];
    }
    if (isset($d['sess'][$url]) === false && str_starts_with($url, 'fake://')) return [404, '', []];
    throw new RuntimeException("fake drive: unhandled $method $url");
}
function drive_path_of(string $id): string { $d = $GLOBALS['dv']['files']; $p = []; while (isset($d[$id])) { array_unshift($p, $d[$id]['name']); $id = $d[$id]['parent']; } return $id . ':' . implode('/', $p); }

// ---- 1) parity with the REAL Python functions -------------------------------------------------------------
$names = ['v.mp4', 'my video (final).MP4', 'ویدیوی تمرین ۱.mp4', 'a.b.c.verylongextension', '..', '', '  spaced  name  .jpg', "quo'te.mp4",
          str_repeat('x', 90) . '.mp4', 'no_ext_' . str_repeat('y', 80), 'émoji😀.mov', '.hidden', 'تصویر.jpeg'];
$texts = [[['', 'see https://www.instagram.com/reel/ABC/?igsh=1.'], null, null], [['', ''], 'x https://instagr.am/p/Q،', null],
          [['nothing'], null, 'after https://www.instagram.com/p/Z)'], [[''], null, null], [['https://www.Instagram.com/reel/K/,,'], null, null]];
$pyIn = json_encode(['names' => $names, 'bundles' => $texts], JSON_UNESCAPED_UNICODE);
$py = <<<'PY'
import ast, json, re, sys
src = open(sys.argv[1], encoding="utf-8").read(); ns = {"re": re}
for node in ast.parse(src).body:
    if (isinstance(node, ast.FunctionDef) and node.name in ("safe_name", "source_url")) or \
       (isinstance(node, ast.Assign) and any(getattr(t, "id", "") == "SOURCE_URL_RE" for t in node.targets)):
        exec(compile(ast.Module([node], []), "telegram_pool.py", "exec"), ns)
class M:
    def __init__(s, t): s.message = t
inp = json.loads(sys.stdin.read())
out = {"names": [ns["safe_name"](n) for n in inp["names"]],
       "urls": [ns["source_url"]({"media": [M(t) for t in b[0]], "text_before": M(b[1]) if b[1] is not None else None,
                                  "text_after": M(b[2]) if b[2] is not None else None}) for b in inp["bundles"]]}
print(json.dumps(out, ensure_ascii=False))
PY;
file_put_contents($tmp . '/ref.py', $py);
$pp = proc_open(['python3', $tmp . '/ref.py', $apb . '/Sys.scripts/telegram_pool.py'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], $pyIn); fclose($pipes[0]); $ref = json_decode(stream_get_contents($pipes[1]), true); $err = stream_get_contents($pipes[2]); proc_close($pp);
if (!is_array($ref)) { echo "  FAIL python reference did not run: $err\n"; $F++; }
else {
    foreach ($names as $i => $n) ok(tg_safe_name($n) === $ref['names'][$i], 'safe_name parity: ' . json_encode($n, JSON_UNESCAPED_UNICODE) . ' ⇒ ' . json_encode(tg_safe_name($n), JSON_UNESCAPED_UNICODE) . ($ref['names'][$i] === tg_safe_name($n) ? '' : ' python=' . json_encode($ref['names'][$i], JSON_UNESCAPED_UNICODE)));
    foreach ($texts as $i => $t) {
        $b = ['media' => array_map(fn($x) => ['message' => $x], $t[0]), 'text_before' => $t[1] === null ? null : ['message' => $t[1]], 'text_after' => $t[2] === null ? null : ['message' => $t[2]]];
        ok(tg_host_source_url($b) === $ref['urls'][$i], 'source_url parity #' . $i . ' ⇒ ' . json_encode(tg_host_source_url($b)));
    }
}

// ---- 2) fixture: a planned night with three bundles ----------------------------------------------------------
$PEER = -1001234567890;
$vid = fn(int $id, int $size, ?string $fn = 'v.mp4') => ['_' => 'message', 'id' => $id, 'date' => 1, 'message' => '', 'media' => ['_' => 'messageMediaDocument', 'document' =>
    ['_' => 'document', 'id' => $id, 'size' => $size, 'mime_type' => 'video/mp4', 'attributes' => array_merge([['_' => 'documentAttributeVideo']], $fn ? [['_' => 'documentAttributeFilename', 'file_name' => $fn]] : [])]]];
$pho = fn(int $id) => ['_' => 'message', 'id' => $id, 'date' => 1, 'message' => '', 'media' => ['_' => 'messageMediaPhoto', 'photo' => ['_' => 'photo', 'id' => $id]]];
$lnk = fn(int $id) => ['_' => 'message', 'id' => $id, 'date' => 1, 'message' => 'https://www.instagram.com/p/X/', 'media' => ['_' => 'messageMediaWebPage', 'webpage' => ['_' => 'webPage']]];
$by = [];
foreach ([$lnk(200), $vid(201, 20 * 1048576 + 12345, 'my clip.mp4'), $pho(202), $vid(300, 5000, null), $pho(400)] as $m) $by[$m['id']] = $m;
$GLOBALS['fake_api'] = new FakeApi($by);
tg_write_json(tg_var('chain-chats.json'), ['chats' => [(string) $PEER => ['kind' => 'source', 'title' => 'Instant Saver']]]);
$DATE = '2026-11-12';
$plans = [$DATE => ['state' => 'done', 'peer' => $PEER, 'bundles' => [
    ['key' => 'b200', 'media_ids' => [200, 201, 202], 'types' => ['other', 'video', 'photo'], 'source_url' => 'https://www.instagram.com/p/X/'],
    ['key' => 'b300', 'media_ids' => [300], 'types' => ['video'], 'source_url' => null],
    ['key' => 'b400', 'media_ids' => [400], 'types' => ['photo'], 'source_url' => null]]]];
tg_write_json(tg_var('host-plan.json'), $plans);
$teh = fn(string $s) => (new DateTimeImmutable($s, new DateTimeZone('+03:30')))->getTimestamp();
$NOW = $teh('2026-11-12 03:40');
ok(($reg['host_pull']['roles'] ?? null) === [] && !empty($reg['host_pull']['tg']) && !empty($reg['host_pull']['retry']), 'registry: host_pull is internal (no door role), tg, retry');

// ---- 3) the switch -----------------------------------------------------------------------------------------
ok(tg_host_pull_tick($NOW) === 'not-live', 'no switch anywhere ⇒ dry ⇒ no pull');
tg_write_json(tg_var('host-config-repo.json'), ['polled' => time(), 'code' => 404, 'config' => []]);
ok(tg_host_config()['mode'] === 'dry', 'repo proposal absent (404) ⇒ dry');
tg_write_json(tg_var('host-config-repo.json'), ['polled' => time(), 'code' => 200, 'config' => null]);
ok(tg_host_config()['mode'] === 'off', 'repo proposal not a JSON object ⇒ off (fail closed)');
tg_write_json(tg_var('host-config-repo.json'), ['polled' => time(), 'code' => 200, 'config' => ['mode' => 'live', 'limit' => 1]]);
$c = tg_host_config();
ok($c['mode'] === 'live' && $c['limit'] === 1 && $c['source'] === 'repo', 'approved repo proposal {live, limit 1} is read');
file_put_contents(tg_var('host-config.json'), '{"mode":"dry"}');
ok(tg_host_config()['mode'] === 'dry' && tg_host_config()['source'] === 'file', 'a local host-config.json wins over the repo');
unlink(tg_var('host-config.json'));
$GLOBALS['gh']['files']['Memory-Workflow/nightly-chain-independence/host-config.json'] = '{"mode":"live","limit":1}';
@unlink(tg_var('host-config-repo.json')); tg_host_config_sync();
ok(tg_host_config()['mode'] === 'live', 'tg_host_config_sync caches the repo proposal');

// ---- 4) live, limit 1: one bundle, a resumed upload ------------------------------------------------------------
ok(tg_host_pull_tick($NOW) === 'queued', 'live ⇒ first planned bundle queued');
ok(tg_host_pull_tick($NOW) === 'busy', 'job pending ⇒ busy, no second job');
$drain = function () {
    $q = glob(tg_var('queue') . '/*.json'); if (!$q) return null; $j = tg_read_json($q[0]);
    try { $r = job_host_pull($j['args'], fn($p) => null, 'internal'); tg_status($j['id'], ['state' => 'done']); }
    catch (Throwable $e) { $r = $e; tg_status($j['id'], ['state' => 'failed', 'error' => $e->getMessage()]); }
    @unlink($q[0]); return $r;
};
$GLOBALS['dv']['skew_after_chunk'] = 1; $GLOBALS['dv']['fail_next'] = 0;
$r = $drain();
ok(is_array($r) && $r['done'] === false && $r['phase'] === 'upload', 'slice 1: downloaded, upload cut after the first 8 MiB chunk ⇒ partial');
ok(is_file(tg_var('pull/b200/201.upload.json')) && $GLOBALS['fake_api']->downloads === 2, 'upload session kept on disk; 2 downloads (video + photo; the link header has no media)');
unset($GLOBALS['tg_pull_skew']);
ok((tg_pull_state()[$DATE]['b200']['state'] ?? '') === 'partial', 'state partial');
ok(tg_host_pull_tick($NOW) === 'queued', 'partial ⇒ continued on the next tick (not blocked by the limit)');
$GLOBALS['dv']['fail_next'] = 1;
$r = $drain();
ok(is_array($r) && $r['done'] === true, 'slice 2: resumed from the Range offset (one transient 503 retried) ⇒ done');
ok($GLOBALS['fake_api']->downloads === 2, 'no re-download on resume');
$rec = json_decode($GLOBALS['gh']['files']['Memory-Workflow/intake-pool/state/telegram/b200/host-pull.json'] ?? 'null', true);
ok(is_array($rec) && tg_repo_allowed('Memory-Workflow/intake-pool/state/telegram/b200/host-pull.json'), 'record written on an allowlisted path');
$up = $rec['drive_files']['uploaded'] ?? [];
ok(array_column($up, 'rel_path') === ['201+++my_clip.mp4', '202+++photo_202.jpg'], 'names = Python names (filename attr; photo fallback): ' . implode(', ', array_column($up, 'rel_path')));
$okmd5 = count($up) === 2;
foreach ($up as $u) { $f = $GLOBALS['dv']['files'][$u['drive_id']] ?? null; $okmd5 = $okmd5 && $f && md5($f['data']) === $u['md5Checksum'] && md5(bytes_of($u['message_id'], $u['size'])) === $u['md5Checksum']; }
ok($okmd5, 'Drive md5 = Telegram bytes md5 for every file (20 MiB video in 3 chunks)');
ok(drive_path_of($up[0]['drive_id']) === TG_POOL_PARENT_ID . ':intake-pool/telegram/b200/201+++my_clip.mp4', 'folder = intake-pool/telegram/<key>/ under the pool parent');
ok($rec['source_url'] === 'https://www.instagram.com/p/X/' && $rec['media_kind'] === 'mixed' && $rec['skipped'] === [['message_id' => 200, 'type' => 'other', 'why' => 'no downloadable media']], 'record: source_url, media_kind, skipped link header');
ok(!is_dir(tg_var('pull/b200')), 'local copy removed after the record landed');
ok(tg_host_pull_tick($NOW) === 'limit', 'limit 1 reached ⇒ nothing more tonight');
$sum = json_decode($GLOBALS['gh']['files']['Memory-Workflow/nightly-pool/host/' . $DATE . '/pull.json'] ?? 'null', true);
ok(($sum['keys']['b200']['state'] ?? '') === 'done' && ($sum['keys']['b300']['state'] ?? '') === 'not-started' && $sum['done'] === 1, 'night summary on main: b200 done, others not started');

// ---- 5) idempotence with the Python pull and an in-place update ---------------------------------------------------
$GLOBALS['gh']['files']['Memory-Workflow/nightly-chain-independence/host-config.json'] = '{"mode":"live","limit":0}';
tg_write_json(tg_var('host-config-repo.json'), ['polled' => time(), 'code' => 200, 'config' => ['mode' => 'live', 'limit' => 0]]);
// the Python pull already put b300's file there (same name, same bytes) — and a stale copy of nothing else
$fold = tg_drive_pool_folder('b300');
$GLOBALS['dv']['files']['PY1'] = ['id' => 'PY1', 'name' => '300+++video_300.mp4', 'parent' => $fold, 'data' => 'stale'];
$creates0 = $GLOBALS['dv']['creates'];
ok(tg_host_pull_tick($NOW) === 'queued', 'limit 0 ⇒ next bundle queued');
$r = $drain();
$rec3 = json_decode($GLOBALS['gh']['files']['Memory-Workflow/intake-pool/state/telegram/b300/host-pull.json'] ?? 'null', true);
$u3 = $rec3['drive_files']['uploaded'][0] ?? [];
ok(is_array($r) && $r['done'] && $u3['rel_path'] === '300+++video_300.mp4' && $u3['drive_id'] === 'PY1' && $GLOBALS['dv']['updates'] === 1 && $GLOBALS['dv']['creates'] === $creates0,
   'a same-name file with another md5 is updated in place (no second copy); a video without filename attr is named like Python');
$GLOBALS['dv']['files']['PY1']['data'] = bytes_of(300, 5000);
tg_pull_mark($DATE, 'b300', ['state' => '']); $GLOBALS['gh']['files']['Memory-Workflow/intake-pool/state/telegram/b300/host-pull.json'] = 'x';
ok(tg_host_pull_tick($NOW) === 'queued', 're-pull of the same bundle queued');
$r = $drain();
$rec3 = json_decode($GLOBALS['gh']['files']['Memory-Workflow/intake-pool/state/telegram/b300/host-pull.json'], true);
ok($rec3['drive_files']['uploaded'][0]['skipped_existing'] === true && $GLOBALS['dv']['updates'] === 1, 'same name + same md5 ⇒ skipped_existing, nothing uploaded');

// ---- 6) failures: message gone ⇒ bounded retries; short download ⇒ fail ---------------------------------------
unset($GLOBALS['fake_api']->channels->by[400]);
ok(tg_host_pull_tick($NOW) === 'queued', 'b400 queued');
$r = $drain();
ok($r instanceof Throwable && str_contains($r->getMessage(), 'MESSAGE_GONE'), 'source message gone ⇒ the job fails');
$st = tg_pull_state()[$DATE]['b400'];
ok($st['state'] === 'failed' && $st['fails'] === 1, 'b400 failed once');
ok(tg_host_pull_tick($NOW) === 'backoff', 'retry waits for the backoff');
$t = time() + 10 * 3600;
for ($i = 2; $i <= TG_PULL_MAX_FAILS; $i++) { tg_host_pull_tick($t); $drain(); $t += 3600; }
ok(tg_host_pull_tick($t) === 'night-done' && tg_pull_state()[$DATE]['b400']['state'] === 'gave_up', 'after ' . TG_PULL_MAX_FAILS . ' failures ⇒ gave up; the night is done');
ok(!glob(tg_var('queue') . '/*.json'), 'queue empty');
$GLOBALS['fake_api']->channels->by[400] = $pho(400); $GLOBALS['fake_api']->short = true;
tg_write_json(tg_var('host-pull.json'), []); tg_pull_mark($DATE, 'b200', ['state' => 'done']); tg_pull_mark($DATE, 'b400', ['state' => 'done']);
tg_rm_r(tg_var('pull'));
tg_host_pull_tick($t); $r = $drain();
ok($r instanceof Throwable && str_contains($r->getMessage(), 'DOWNLOAD_SHORT'), 'a document shorter than its Telegram size ⇒ DOWNLOAD_SHORT, nothing uploaded');
try { job_host_pull(['date' => $DATE, 'key' => 'b300'], fn($p) => null, 'read'); ok(false, 'door role cannot run host_pull'); } catch (Throwable $e) { ok($e->getMessage() === 'internal job', 'door role cannot run host_pull'); }

// ---- 7) old plans are never pulled --------------------------------------------------------------------------------
tg_write_json(tg_var('host-pull.json'), []);
ok(tg_host_pull_tick($teh('2026-11-20 04:00')) === 'plan-too-old', 'a plan older than ' . TG_PULL_NIGHTS_BACK . ' nights is not pulled');

tg_rm_r($tmp);
echo "\n# host pull tests: $F failures\n"; exit($F ? 1 : 0);
